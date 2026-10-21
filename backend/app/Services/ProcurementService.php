<?php

namespace App\Services;

use App\Models\GoodsReceiving;
use App\Models\GoodsReceivingItem;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\OperationalNotification;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use App\Models\StockMovement;
use App\Models\StorageLocation;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Transactional Procurement, Supplier, Purchase Requisition, Purchase Order, and Goods Receiving (GRN) Service.
 *
 * Rules:
 * - Creating or approving a Purchase Order NEVER increases stock.
 * - Stock is increased ONLY when a valid `GoodsReceiving` (GRN) is committed.
 * - Separation of duties: the user who created a Purchase Requisition or Purchase Order cannot approve their own
 *   record (unless they hold `super-admin`), enforcing dual-control procurement governance.
 * - Supports partial deliveries and multiple receiving events against a single Purchase Order (`approved` -> `partially_received` -> `received`).
 * - Prevents duplicate receiving via `idempotency_key` inside `DB::transaction()` with row locking on `PurchaseOrder` and `InventoryItem`.
 * - Flags quantity and price discrepancies (`has_discrepancy = true`) and emits an `OperationalNotification` for finance/management review.
 */
class ProcurementService
{
    public function __construct(
        private InventoryService $inventory,
        private SettingsService $settings,
        private AuditLogger $audit,
    ) {}

    /**
     * Create a Purchase Requisition.
     */
    public function createRequisition(array $data, User $actor): PurchaseRequisition
    {
        return DB::transaction(function () use ($data, $actor) {
            if (empty($data['items']) || ! is_array($data['items'])) {
                throw ValidationException::withMessages(['items' => ['At least one requisition line item is required.']]);
            }

            $requisition = PurchaseRequisition::create([
                'requisition_reference' => $this->generateUniqueRequisitionReference(),
                'storage_location_id' => ! empty($data['storage_location_id']) ? (int) $data['storage_location_id'] : null,
                'status' => PurchaseRequisition::STATUS_PENDING_APPROVAL,
                'priority' => $data['priority'] ?? 'normal',
                'required_by_date' => $data['required_by_date'] ?? null,
                'reason' => strip_tags((string) $data['reason']),
                'requested_by' => $actor->id,
                'requested_at' => now(),
            ]);

            foreach ($data['items'] as $idx => $line) {
                /** @var InventoryItem $invItem */
                $invItem = InventoryItem::findOrFail((int) $line['inventory_item_id']);
                $qty = (float) ($line['quantity_requested'] ?? 0);
                if ($qty <= 0) {
                    throw ValidationException::withMessages(["items.{$idx}.quantity_requested" => ['Requested quantity must be greater than zero.']]);
                }

                PurchaseRequisitionItem::create([
                    'purchase_requisition_id' => $requisition->id,
                    'inventory_item_id' => $invItem->id,
                    'quantity_requested' => $qty,
                    'unit' => isset($line['unit']) ? strip_tags((string) $line['unit']) : $invItem->purchase_unit,
                    'notes' => isset($line['notes']) ? strip_tags((string) $line['notes']) : null,
                ]);
            }

            OperationalNotification::create([
                'type' => 'procurement.requisition_pending',
                'severity' => in_array($requisition->priority, ['high', 'urgent'], true) ? 'warning' : 'info',
                'title' => "Purchase Requisition Pending Approval: {$requisition->requisition_reference}",
                'message' => "{$actor->name} submitted requisition {$requisition->requisition_reference} ({$requisition->reason}).",
                'target_permission' => 'procurement.requisitions.approve',
                'subject_type' => PurchaseRequisition::class,
                'subject_id' => $requisition->id,
            ]);

            $this->audit->log('procurement.requisition_created', $requisition, [
                'requisition_reference' => $requisition->requisition_reference,
                'priority' => $requisition->priority,
                'lines_count' => count($data['items']),
            ], 'success', $actor->id);

            return $requisition->load(['location', 'items.inventoryItem', 'requester', 'reviewer']);
        }, 3);
    }

    /**
     * Approve or reject a Purchase Requisition.
     * Enforces separation of duties: requester cannot approve their own requisition (unless super-admin).
     */
    public function reviewRequisition(PurchaseRequisition $requisition, string $decision, User $actor, ?string $reviewNotes = null): PurchaseRequisition
    {
        return DB::transaction(function () use ($requisition, $decision, $actor, $reviewNotes) {
            /** @var PurchaseRequisition $locked */
            $locked = PurchaseRequisition::where('id', $requisition->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PurchaseRequisition::STATUS_PENDING_APPROVAL) {
                throw ValidationException::withMessages([
                    'status' => ["Requisition {$locked->requisition_reference} has already been reviewed ({$locked->status})."],
                ]);
            }

            if ($decision === 'approve' && $locked->requested_by === $actor->id && ! $actor->hasRole('super-admin')) {
                throw ValidationException::withMessages([
                    'decision' => ['Separation of duties violation: you cannot approve a purchase requisition that you submitted yourself.'],
                ]);
            }

            if (! in_array($decision, ['approve', 'reject'], true)) {
                throw ValidationException::withMessages(['decision' => ['Decision must be approve or reject.']]);
            }

            $newStatus = $decision === 'approve'
                ? PurchaseRequisition::STATUS_APPROVED
                : PurchaseRequisition::STATUS_REJECTED;

            $locked->update([
                'status' => $newStatus,
                'review_notes' => $reviewNotes ? strip_tags($reviewNotes) : ucfirst($newStatus),
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ]);

            if ($newStatus === PurchaseRequisition::STATUS_APPROVED) {
                foreach ($locked->items as $item) {
                    if ($item->quantity_approved === null) {
                        $item->update(['quantity_approved' => $item->quantity_requested]);
                    }
                }
            }

            $this->audit->log('procurement.requisition_reviewed', $locked, [
                'requisition_reference' => $locked->requisition_reference,
                'decision' => $decision,
                'status' => $newStatus,
            ], 'success', $actor->id);

            return $locked->fresh(['location', 'items.inventoryItem', 'requester', 'reviewer']);
        }, 3);
    }

    /**
     * Create a Purchase Order (does NOT increase inventory stock).
     */
    public function createPurchaseOrder(array $data, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $actor) {
            /** @var Supplier $supplier */
            $supplier = Supplier::findOrFail((int) $data['supplier_id']);
            if ($supplier->status !== 'active') {
                throw ValidationException::withMessages([
                    'supplier_id' => ["Supplier [{$supplier->name}] is currently [{$supplier->status}] and cannot receive new purchase orders."],
                ]);
            }

            /** @var StorageLocation $location */
            $location = StorageLocation::findOrFail((int) $data['storage_location_id']);

            if (empty($data['items']) || ! is_array($data['items'])) {
                throw ValidationException::withMessages(['items' => ['At least one purchase order line item is required.']]);
            }

            $currency = $this->settings->all()['hotel']['currency'] ?? 'NGN';
            $requisitionId = ! empty($data['purchase_requisition_id']) ? (int) $data['purchase_requisition_id'] : null;

            $po = PurchaseOrder::create([
                'po_reference' => $this->generateUniquePoReference(),
                'supplier_id' => $supplier->id,
                'purchase_requisition_id' => $requisitionId,
                'storage_location_id' => $location->id,
                'status' => PurchaseOrder::STATUS_PENDING_APPROVAL,
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'currency_code' => $currency,
                'notes' => isset($data['notes']) ? strip_tags((string) $data['notes']) : null,
                'created_by' => $actor->id,
            ]);

            $subtotalMinor = 0;
            foreach ($data['items'] as $idx => $line) {
                /** @var InventoryItem $invItem */
                $invItem = InventoryItem::findOrFail((int) $line['inventory_item_id']);
                $qtyOrdered = (float) ($line['quantity_ordered'] ?? 0);
                if ($qtyOrdered <= 0) {
                    throw ValidationException::withMessages(["items.{$idx}.quantity_ordered" => ['Ordered quantity must be greater than zero.']]);
                }

                $unitCostMinor = isset($line['unit_cost_minor'])
                    ? (int) $line['unit_cost_minor']
                    : AvailabilityService::toMinor($line['unit_cost'] ?? 0);

                if ($unitCostMinor < 0) {
                    throw ValidationException::withMessages(["items.{$idx}.unit_cost" => ['Unit cost cannot be negative.']]);
                }

                $unit = isset($line['unit']) && filled($line['unit'])
                    ? strip_tags((string) $line['unit'])
                    : $invItem->purchase_unit;

                $conversionToBase = isset($line['conversion_to_base']) && (float) $line['conversion_to_base'] > 0
                    ? (float) $line['conversion_to_base']
                    : (strtolower($unit) === strtolower($invItem->purchase_unit) ? (float) $invItem->purchase_to_base_factor : 1.0);

                // Validate unit compatibility up front
                $this->inventory->convertToBaseQuantity($invItem, 1.0, $unit, $conversionToBase);

                $lineTotalMinor = (int) round($qtyOrdered * $unitCostMinor);
                $subtotalMinor += $lineTotalMinor;

                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'inventory_item_id' => $invItem->id,
                    'unit' => $unit,
                    'conversion_to_base' => $conversionToBase,
                    'quantity_ordered' => $qtyOrdered,
                    'quantity_received' => 0,
                    'quantity_rejected' => 0,
                    'unit_cost_minor' => $unitCostMinor,
                    'line_total_minor' => $lineTotalMinor,
                    'unit_cost' => AvailabilityService::toMajor($unitCostMinor),
                    'line_total' => AvailabilityService::toMajor($lineTotalMinor),
                ]);
            }

            $taxMinor = isset($data['tax_amount']) ? AvailabilityService::toMinor($data['tax_amount']) : 0;
            $totalMinor = $subtotalMinor + $taxMinor;

            $po->update([
                'subtotal_minor' => $subtotalMinor,
                'tax_minor' => $taxMinor,
                'total_minor' => $totalMinor,
                'total_amount' => AvailabilityService::toMajor($totalMinor),
            ]);

            if ($requisitionId) {
                PurchaseRequisition::where('id', $requisitionId)->update(['status' => PurchaseRequisition::STATUS_ORDERED]);
            }

            $this->audit->log('procurement.purchase_order_created', $po, [
                'po_reference' => $po->po_reference,
                'supplier_id' => $supplier->id,
                'total_minor' => $totalMinor,
            ], 'success', $actor->id);

            return $po->load(['supplier', 'location', 'items.inventoryItem', 'creator', 'approver', 'receivings']);
        }, 3);
    }

    /**
     * Approve or cancel a Purchase Order (`procurement.approve`).
     * Enforces separation of duties: creator cannot approve their own purchase order (unless super-admin).
     * Approving a PO NEVER modifies inventory stock balances.
     */
    public function reviewPurchaseOrder(PurchaseOrder $po, string $decision, User $actor, ?string $reason = null): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $decision, $actor, $reason) {
            /** @var PurchaseOrder $locked */
            $locked = PurchaseOrder::where('id', $po->id)->lockForUpdate()->firstOrFail();

            if ($decision === 'approve') {
                if ($locked->status !== PurchaseOrder::STATUS_PENDING_APPROVAL) {
                    throw ValidationException::withMessages([
                        'status' => ["Purchase order {$locked->po_reference} cannot be approved from status [{$locked->status}]."],
                    ]);
                }

                if ($locked->created_by === $actor->id && ! $actor->hasRole('super-admin')) {
                    throw ValidationException::withMessages([
                        'decision' => ['Separation of duties violation: you cannot approve a purchase order that you created yourself.'],
                    ]);
                }

                $locked->update([
                    'status' => PurchaseOrder::STATUS_APPROVED,
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                ]);

                $this->audit->log('procurement.purchase_order_approved', $locked, [
                    'po_reference' => $locked->po_reference,
                    'total_minor' => $locked->total_minor,
                ], 'success', $actor->id);
            } elseif ($decision === 'cancel') {
                if (in_array($locked->status, [PurchaseOrder::STATUS_PARTIALLY_RECEIVED, PurchaseOrder::STATUS_RECEIVED, PurchaseOrder::STATUS_CANCELLED], true)) {
                    throw ValidationException::withMessages([
                        'status' => ["Cannot cancel purchase order {$locked->po_reference} in status [{$locked->status}]."],
                    ]);
                }

                $locked->update([
                    'status' => PurchaseOrder::STATUS_CANCELLED,
                    'cancellation_reason' => $reason ? strip_tags($reason) : 'Cancelled by authorized officer',
                    'cancelled_by' => $actor->id,
                    'cancelled_at' => now(),
                ]);

                $this->audit->log('procurement.purchase_order_cancelled', $locked, [
                    'po_reference' => $locked->po_reference,
                    'reason' => $locked->cancellation_reason,
                ], 'success', $actor->id);
            } else {
                throw ValidationException::withMessages(['decision' => ['Decision must be approve or cancel.']]);
            }

            return $locked->fresh(['supplier', 'location', 'items.inventoryItem', 'creator', 'approver', 'receivings']);
        }, 3);
    }

    /**
     * Record a Goods Receiving Note (GRN) against an approved or partially-received Purchase Order.
     *
     * Rules:
     * - Locks `PurchaseOrder` and all affected `InventoryItem` rows in deterministic order.
     * - Idempotent via `idempotency_key` so duplicate requests never increase stock twice.
     * - Rejects receiving more than the remaining outstanding quantity unless `allow_over_delivery = true` with a reason
     *   (which flags `has_quantity_discrepancy = true`).
     * - Flags `has_price_discrepancy = true` if received `unit_cost_minor` differs from the PO line's `unit_cost_minor`.
     * - Increases stock ONLY for `quantity_received` (rejected quantities are recorded on the GRN and PO line but never added to stock).
     */
    public function receiveGoods(PurchaseOrder $po, array $data, User $actor): GoodsReceiving
    {
        $idempotencyKey = isset($data['idempotency_key']) && filled($data['idempotency_key'])
            ? trim((string) $data['idempotency_key'])
            : null;

        if ($idempotencyKey) {
            $existing = GoodsReceiving::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ($existing->purchase_order_id !== $po->id) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['Idempotency key has already been used for another purchase order.'],
                    ]);
                }

                return $existing->load(['purchaseOrder', 'supplier', 'location', 'items.inventoryItem', 'receiver']);
            }
        }

        return DB::transaction(function () use ($po, $data, $actor, $idempotencyKey) {
            /** @var PurchaseOrder $lockedPo */
            $lockedPo = PurchaseOrder::where('id', $po->id)->lockForUpdate()->firstOrFail();

            if ($idempotencyKey) {
                $existingLocked = GoodsReceiving::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if ($existingLocked) {
                    if ($existingLocked->purchase_order_id !== $lockedPo->id) {
                        throw ValidationException::withMessages([
                            'idempotency_key' => ['Idempotency key has already been used for another purchase order.'],
                        ]);
                    }

                    return $existingLocked->load(['purchaseOrder', 'supplier', 'location', 'items.inventoryItem', 'receiver']);
                }
            }

            if (! in_array($lockedPo->status, [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_PARTIALLY_RECEIVED], true)) {
                throw ValidationException::withMessages([
                    'status' => ["Goods can only be received against an approved or partially-received purchase order (current status: {$lockedPo->status})."],
                ]);
            }

            if (empty($data['items']) || ! is_array($data['items'])) {
                throw ValidationException::withMessages(['items' => ['At least one receiving line item is required.']]);
            }

            /** @var StorageLocation $location */
            $location = StorageLocation::where('id', ! empty($data['storage_location_id']) ? (int) $data['storage_location_id'] : $lockedPo->storage_location_id)
                ->lockForUpdate()
                ->firstOrFail();

            // Lock all PO items and their InventoryItems in ascending ID order
            $poItems = PurchaseOrderItem::where('purchase_order_id', $lockedPo->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $invIds = $poItems->pluck('inventory_item_id')->unique()->sort()->values()->all();
            $lockedInvItems = InventoryItem::whereIn('id', $invIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $receiving = GoodsReceiving::create([
                'receiving_reference' => $this->generateUniqueGrnReference(),
                'idempotency_key' => $idempotencyKey,
                'purchase_order_id' => $lockedPo->id,
                'supplier_id' => $lockedPo->supplier_id,
                'storage_location_id' => $location->id,
                'supplier_delivery_note' => isset($data['supplier_delivery_note']) ? strip_tags((string) $data['supplier_delivery_note']) : null,
                'supplier_invoice_reference' => isset($data['supplier_invoice_reference']) ? strip_tags((string) $data['supplier_invoice_reference']) : null,
                'currency_code' => $lockedPo->currency_code,
                'notes' => isset($data['notes']) ? strip_tags((string) $data['notes']) : null,
                'received_by' => $actor->id,
                'received_at' => now(),
            ]);

            $totalReceivedCostMinor = 0;
            $anyDiscrepancy = false;
            $discrepancySummaries = [];
            $acceptedAnyLine = false;

            foreach ($data['items'] as $idx => $recLine) {
                $poItemId = (int) ($recLine['purchase_order_item_id'] ?? 0);
                /** @var PurchaseOrderItem|null $poItem */
                $poItem = $poItems->get($poItemId);
                if (! $poItem) {
                    throw ValidationException::withMessages([
                        "items.{$idx}.purchase_order_item_id" => ['Purchase order item does not belong to this purchase order.'],
                    ]);
                }

                /** @var InventoryItem $invItem */
                $invItem = $lockedInvItems->get($poItem->inventory_item_id) ?? InventoryItem::findOrFail($poItem->inventory_item_id);

                $qtyAccepted = round((float) ($recLine['quantity_received'] ?? 0), 4);
                $qtyRejected = round((float) ($recLine['quantity_rejected'] ?? 0), 4);

                if ($qtyAccepted < 0 || $qtyRejected < 0 || ($qtyAccepted == 0.0 && $qtyRejected == 0.0)) {
                    continue;
                }

                $acceptedAnyLine = true;
                $outstandingBefore = max(0.0, round(((float) $poItem->quantity_ordered) - ((float) $poItem->quantity_received), 4));
                $allowOverDelivery = ! empty($data['allow_over_delivery']);

                $hasQtyDiscrepancy = false;
                if ($qtyAccepted > $outstandingBefore + 0.0001) {
                    if (! $allowOverDelivery) {
                        throw ValidationException::withMessages([
                            "items.{$idx}.quantity_received" => ["Cannot receive {$qtyAccepted} {$poItem->unit} for {$invItem->name}; only {$outstandingBefore} {$poItem->unit} remain outstanding on {$lockedPo->po_reference}."],
                        ]);
                    }
                    $hasQtyDiscrepancy = true;
                }
                if ($qtyRejected > 0) {
                    $hasQtyDiscrepancy = true;
                }

                $actualUnitCostMinor = isset($recLine['unit_cost_minor'])
                    ? (int) $recLine['unit_cost_minor']
                    : (isset($recLine['unit_cost']) ? AvailabilityService::toMinor($recLine['unit_cost']) : (int) $poItem->unit_cost_minor);

                $hasPriceDiscrepancy = $actualUnitCostMinor !== (int) $poItem->unit_cost_minor;
                if ($hasPriceDiscrepancy || $hasQtyDiscrepancy) {
                    $anyDiscrepancy = true;
                    $discrepancySummaries[] = "{$invItem->name}: ordered {$poItem->quantity_ordered} @ {$poItem->unit_cost_minor} minor, received {$qtyAccepted} (rejected {$qtyRejected}) @ {$actualUnitCostMinor} minor";
                }

                $conversionFactor = max(0.0001, (float) $poItem->conversion_to_base);
                $baseQtyAccepted = round($qtyAccepted * $conversionFactor, 4);
                $unitCostMinorPerBase = (int) round($actualUnitCostMinor / $conversionFactor);
                $lineTotalMinor = (int) round($qtyAccepted * $actualUnitCostMinor);
                $totalReceivedCostMinor += $lineTotalMinor;

                $batchId = null;
                $batchNumber = isset($recLine['batch_number']) && filled($recLine['batch_number'])
                    ? strip_tags((string) $recLine['batch_number'])
                    : null;
                $expiryDate = $recLine['expiry_date'] ?? null;

                if ($qtyAccepted > 0 && ($invItem->track_batches || $batchNumber || $expiryDate)) {
                    $batch = InventoryBatch::create([
                        'inventory_item_id' => $invItem->id,
                        'storage_location_id' => $location->id,
                        'batch_number' => $batchNumber ?: ('B-'.now()->format('Ymd').'-'.strtoupper(Str::random(4))),
                        'expiry_date' => $expiryDate,
                        'received_quantity' => $baseQtyAccepted,
                        'remaining_quantity' => $baseQtyAccepted,
                        'unit_cost_minor' => $unitCostMinorPerBase,
                        'goods_receiving_id' => $receiving->id,
                    ]);
                    $batchId = $batch->id;
                }

                $movement = null;
                if ($baseQtyAccepted > 0) {
                    $movement = $this->inventory->applyLockedStockMovement(
                        item: $invItem,
                        location: $location,
                        movementType: StockMovement::TYPE_GOODS_RECEIVED,
                        quantityDeltaBase: $baseQtyAccepted,
                        unitCostMinorPerBase: $unitCostMinorPerBase,
                        actor: $actor,
                        reason: "Goods received on {$receiving->receiving_reference} (PO {$lockedPo->po_reference})",
                        notes: $receiving->supplier_delivery_note,
                        sourceType: 'goods_receiving',
                        sourceId: (string) $receiving->id,
                        idempotencyKey: null,
                        uniqueSourceKey: "grn:{$receiving->id}:poi:{$poItem->id}",
                        allowNegativeOverride: false,
                        batchId: $batchId,
                        updateWeightedAverageCost: true,
                    );
                }

                GoodsReceivingItem::create([
                    'goods_receiving_id' => $receiving->id,
                    'purchase_order_item_id' => $poItem->id,
                    'inventory_item_id' => $invItem->id,
                    'quantity_received' => $qtyAccepted,
                    'quantity_rejected' => $qtyRejected,
                    'base_quantity_received' => $baseQtyAccepted,
                    'unit_cost_minor' => $actualUnitCostMinor,
                    'line_total_minor' => $lineTotalMinor,
                    'batch_number' => $batchNumber,
                    'expiry_date' => $expiryDate,
                    'has_price_discrepancy' => $hasPriceDiscrepancy,
                    'has_quantity_discrepancy' => $hasQtyDiscrepancy,
                    'discrepancy_reason' => isset($recLine['discrepancy_reason']) ? strip_tags((string) $recLine['discrepancy_reason']) : null,
                    'stock_movement_id' => $movement?->id,
                ]);

                $poItem->update([
                    'quantity_received' => round(((float) $poItem->quantity_received) + $qtyAccepted, 4),
                    'quantity_rejected' => round(((float) $poItem->quantity_rejected) + $qtyRejected, 4),
                ]);
            }

            if (! $acceptedAnyLine) {
                throw ValidationException::withMessages([
                    'items' => ['At least one line item must have a positive received or rejected quantity.'],
                ]);
            }

            // Determine updated PO status (`partially_received` vs `received`)
            $freshPoItems = PurchaseOrderItem::where('purchase_order_id', $lockedPo->id)->get();
            $allFulfilled = $freshPoItems->every(fn (PurchaseOrderItem $line) => ((float) $line->quantity_received) >= ((float) $line->quantity_ordered) - 0.0001);

            $lockedPo->update([
                'status' => $allFulfilled ? PurchaseOrder::STATUS_RECEIVED : PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
            ]);

            $receiving->update([
                'total_received_cost_minor' => $totalReceivedCostMinor,
                'total_received_cost' => AvailabilityService::toMajor($totalReceivedCostMinor),
                'has_discrepancy' => $anyDiscrepancy,
                'discrepancy_notes' => $anyDiscrepancy
                    ? ($data['discrepancy_notes'] ?? implode('; ', $discrepancySummaries))
                    : null,
            ]);

            if ($anyDiscrepancy) {
                OperationalNotification::create([
                    'type' => 'procurement.receiving_discrepancy',
                    'severity' => 'warning',
                    'title' => "Receiving Discrepancy Flagged: {$receiving->receiving_reference}",
                    'message' => "GRN {$receiving->receiving_reference} for PO {$lockedPo->po_reference} reported price or quantity discrepancies: {$receiving->discrepancy_notes}",
                    'target_permission' => 'procurement.approve',
                    'subject_type' => GoodsReceiving::class,
                    'subject_id' => $receiving->id,
                ]);
            }

            $this->audit->log('procurement.goods_received', $receiving, [
                'receiving_reference' => $receiving->receiving_reference,
                'po_reference' => $lockedPo->po_reference,
                'po_status' => $lockedPo->status,
                'total_received_cost_minor' => $totalReceivedCostMinor,
                'has_discrepancy' => $anyDiscrepancy,
            ], 'success', $actor->id);

            return $receiving->fresh(['purchaseOrder', 'supplier', 'location', 'items.inventoryItem', 'receiver']);
        }, 3);
    }

    private function generateUniqueRequisitionReference(): string
    {
        $date = now()->format('Ymd');
        for ($i = 0; $i < 10; $i++) {
            $ref = 'PR-'.$date.'-'.strtoupper(Str::random(6));
            if (! PurchaseRequisition::where('requisition_reference', $ref)->exists()) {
                return $ref;
            }
        }

        return 'PR-'.$date.'-'.strtoupper(Str::random(8));
    }

    private function generateUniquePoReference(): string
    {
        $date = now()->format('Ymd');
        for ($i = 0; $i < 10; $i++) {
            $ref = 'PO-'.$date.'-'.strtoupper(Str::random(6));
            if (! PurchaseOrder::where('po_reference', $ref)->exists()) {
                return $ref;
            }
        }

        return 'PO-'.$date.'-'.strtoupper(Str::random(8));
    }

    private function generateUniqueGrnReference(): string
    {
        $date = now()->format('Ymd');
        for ($i = 0; $i < 10; $i++) {
            $ref = 'GRN-'.$date.'-'.strtoupper(Str::random(6));
            if (! GoodsReceiving::where('receiving_reference', $ref)->exists()) {
                return $ref;
            }
        }

        return 'GRN-'.$date.'-'.strtoupper(Str::random(8));
    }
}
