<?php

namespace App\Services;

use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\InventoryStockBalance;
use App\Models\OperationalNotification;
use App\Models\StockMovement;
use App\Models\StorageLocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Transactional Inventory & Stock Control Service.
 *
 * Costing & Unit Rules:
 * - Every `InventoryItem` defines an authoritative `base_unit` (e.g. `g`, `ml`, `unit`, `bottle`) and a `purchase_unit`
 *   with an explicit conversion factor `purchase_to_base_factor` (`1 purchase_unit = purchase_to_base_factor base_units`).
 * - All `InventoryStockBalance.quantity`, `InventoryItem.current_stock`, and `StockMovement.quantity_delta` values are
 *   stored in the item's `base_unit` so units are never mixed.
 * - Costing uses the **Moving Weighted Average** method in integer minor units (`unit_cost_minor` per 1 `base_unit`):
 *   when new stock is received at cost $C_{\text{rec}}$ for $Q_{\text{rec}}$ base units and existing positive stock is
 *   $Q_{\text{old}}$ at $C_{\text{old}}$, the new weighted average cost is:
 *     $$C_{\text{new}} = \text{round}\left(\frac{\max(0, Q_{\text{old}}) \cdot C_{\text{old}} + Q_{\text{rec}} \cdot C_{\text{rec}}}{\max(0, Q_{\text{old}}) + Q_{\text{rec}}}\right)$$
 * - Negative stock is rejected by default unless:
 *   (a) the item has `allow_negative_stock = true` OR
 *   (b) `operations.pos_allow_negative_stock_with_override = true` AND the actor holds `inventory.negative_override`
 *       with an explicit reason recorded on the movement.
 */
class InventoryService
{
    public function __construct(
        private SettingsService $settings,
        private AuditLogger $audit,
    ) {}

    /**
     * Convert a quantity from `$unit` into the item's `base_unit`.
     * Rejects unconfigured units so kilograms are never subtracted from grams without conversion.
     */
    public function convertToBaseQuantity(InventoryItem $item, float $quantity, ?string $unit = null, ?float $explicitConversionFactor = null): float
    {
        $targetUnit = $unit ? strtolower(trim($unit)) : strtolower(trim($item->base_unit));
        $baseUnit = strtolower(trim($item->base_unit));
        $purchaseUnit = strtolower(trim($item->purchase_unit));

        if ($explicitConversionFactor !== null && $explicitConversionFactor > 0) {
            return round($quantity * $explicitConversionFactor, 4);
        }

        if ($targetUnit === $baseUnit) {
            return round($quantity, 4);
        }

        if ($targetUnit === $purchaseUnit) {
            return round($quantity * max(0.0001, (float) $item->purchase_to_base_factor), 4);
        }

        // Standard metric conversions when base_unit and targetUnit are standard pairs
        $metricPairs = [
            'kg:g' => 1000.0,
            'g:kg' => 0.001,
            'l:ml' => 1000.0,
            'ml:l' => 0.001,
        ];
        $pairKey = "{$targetUnit}:{$baseUnit}";
        if (isset($metricPairs[$pairKey])) {
            return round($quantity * $metricPairs[$pairKey], 4);
        }

        throw ValidationException::withMessages([
            'unit' => ["Unit [{$unit}] is not compatible with {$item->name} (base unit: {$item->base_unit}, purchase unit: {$item->purchase_unit}). Specify an explicit conversion factor."],
        ]);
    }

    /**
     * Record a manual stock adjustment, opening stock addition, waste/spoilage, or supplier return.
     */
    public function recordAdjustmentOrWaste(array $data, User $actor): StockMovement
    {
        return DB::transaction(function () use ($data, $actor) {
            $movementType = (string) ($data['movement_type'] ?? StockMovement::TYPE_ADJUSTMENT);
            if (! in_array($movementType, [
                StockMovement::TYPE_ADJUSTMENT,
                StockMovement::TYPE_WASTE,
                StockMovement::TYPE_SUPPLIER_RETURN,
                StockMovement::TYPE_RESTOCK_RETURN,
            ], true)) {
                throw ValidationException::withMessages([
                    'movement_type' => ['Invalid stock movement type for manual recording.'],
                ]);
            }

            if (empty($data['reason']) || strlen(trim((string) $data['reason'])) < 3) {
                throw ValidationException::withMessages([
                    'reason' => ['A mandatory reason is required for all stock adjustments, waste, and returns.'],
                ]);
            }

            /** @var InventoryItem $item */
            $item = InventoryItem::where('id', (int) $data['inventory_item_id'])->lockForUpdate()->firstOrFail();
            /** @var StorageLocation $location */
            $location = StorageLocation::where('id', (int) $data['storage_location_id'])->lockForUpdate()->firstOrFail();

            $rawQty = (float) ($data['quantity'] ?? 0);
            if ($rawQty == 0.0) {
                throw ValidationException::withMessages(['quantity' => ['Movement quantity cannot be zero.']]);
            }

            $unit = $data['unit'] ?? $item->base_unit;
            $baseMagnitude = $this->convertToBaseQuantity($item, abs($rawQty), $unit);

            // Waste and supplier_return are always negative deductions; adjustment uses sign of $rawQty or `direction`
            if (in_array($movementType, [StockMovement::TYPE_WASTE, StockMovement::TYPE_SUPPLIER_RETURN], true)) {
                $delta = -$baseMagnitude;
            } elseif (($data['direction'] ?? null) === 'deduct' || $rawQty < 0) {
                $delta = -$baseMagnitude;
            } else {
                $delta = $baseMagnitude;
            }

            $unitCostMinor = isset($data['unit_cost'])
                ? AvailabilityService::toMinor($data['unit_cost'])
                : (int) $item->unit_cost_minor;

            return $this->applyLockedStockMovement(
                item: $item,
                location: $location,
                movementType: $movementType,
                quantityDeltaBase: $delta,
                unitCostMinorPerBase: $unitCostMinor,
                actor: $actor,
                reason: strip_tags((string) $data['reason']),
                notes: isset($data['notes']) ? strip_tags((string) $data['notes']) : null,
                sourceType: 'manual_'.$movementType,
                sourceId: null,
                idempotencyKey: $data['idempotency_key'] ?? null,
                uniqueSourceKey: null,
                allowNegativeOverride: ! empty($data['allow_negative_override']),
            );
        }, 3);
    }

    /**
     * Record a physical stock count and automatically generate a reconciling `stock_count` movement
     * if the counted quantity differs from the current location balance.
     */
    public function recordStockCount(array $data, User $actor): StockMovement
    {
        return DB::transaction(function () use ($data, $actor) {
            /** @var InventoryItem $item */
            $item = InventoryItem::where('id', (int) $data['inventory_item_id'])->lockForUpdate()->firstOrFail();
            /** @var StorageLocation $location */
            $location = StorageLocation::where('id', (int) $data['storage_location_id'])->lockForUpdate()->firstOrFail();

            $countedRaw = (float) ($data['counted_quantity'] ?? -1);
            if ($countedRaw < 0) {
                throw ValidationException::withMessages(['counted_quantity' => ['Counted quantity cannot be negative.']]);
            }

            $countedBase = $this->convertToBaseQuantity($item, $countedRaw, $data['unit'] ?? $item->base_unit);

            $balanceRow = $this->lockOrCreateLocationBalance($item, $location);
            $delta = round($countedBase - (float) $balanceRow->quantity, 4);

            $reason = ! empty($data['reason'])
                ? strip_tags((string) $data['reason'])
                : "Physical stock count reconciliation at {$location->name} (counted: {$countedBase} {$item->base_unit})";

            return $this->applyLockedStockMovement(
                item: $item,
                location: $location,
                movementType: StockMovement::TYPE_STOCK_COUNT,
                quantityDeltaBase: $delta,
                unitCostMinorPerBase: (int) $item->unit_cost_minor,
                actor: $actor,
                reason: $reason,
                notes: isset($data['notes']) ? strip_tags((string) $data['notes']) : null,
                sourceType: 'stock_count',
                sourceId: null,
                idempotencyKey: $data['idempotency_key'] ?? null,
                uniqueSourceKey: null,
                allowNegativeOverride: false,
            );
        }, 3);
    }

    /**
     * Transfer stock atomically between two storage locations (`transfer_out` + `transfer_in`).
     *
     * @return array{out: StockMovement, in: StockMovement}
     */
    public function transferStock(array $data, User $actor): array
    {
        return DB::transaction(function () use ($data, $actor) {
            $fromLocId = (int) $data['from_location_id'];
            $toLocId = (int) $data['to_location_id'];
            if ($fromLocId === $toLocId) {
                throw ValidationException::withMessages([
                    'to_location_id' => ['Source and destination storage locations must be different.'],
                ]);
            }

            /** @var InventoryItem $item */
            $item = InventoryItem::where('id', (int) $data['inventory_item_id'])->lockForUpdate()->firstOrFail();

            // Lock locations in ascending ID order to prevent deadlocks
            $locIds = [$fromLocId, $toLocId];
            sort($locIds);
            $lockedLocs = StorageLocation::whereIn('id', $locIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            /** @var StorageLocation $fromLoc */
            $fromLoc = $lockedLocs->get($fromLocId) ?? StorageLocation::findOrFail($fromLocId);
            /** @var StorageLocation $toLoc */
            $toLoc = $lockedLocs->get($toLocId) ?? StorageLocation::findOrFail($toLocId);

            $qtyRaw = (float) ($data['quantity'] ?? 0);
            if ($qtyRaw <= 0) {
                throw ValidationException::withMessages(['quantity' => ['Transfer quantity must be greater than zero.']]);
            }
            $baseQty = $this->convertToBaseQuantity($item, $qtyRaw, $data['unit'] ?? $item->base_unit);
            $reason = ! empty($data['reason']) ? strip_tags((string) $data['reason']) : "Transfer from {$fromLoc->name} to {$toLoc->name}";

            $outMove = $this->applyLockedStockMovement(
                item: $item,
                location: $fromLoc,
                movementType: StockMovement::TYPE_TRANSFER_OUT,
                quantityDeltaBase: -$baseQty,
                unitCostMinorPerBase: (int) $item->unit_cost_minor,
                actor: $actor,
                reason: $reason,
                notes: isset($data['notes']) ? strip_tags((string) $data['notes']) : null,
                sourceType: 'transfer',
                sourceId: null,
            );

            $inMove = $this->applyLockedStockMovement(
                item: $item,
                location: $toLoc,
                movementType: StockMovement::TYPE_TRANSFER_IN,
                quantityDeltaBase: $baseQty,
                unitCostMinorPerBase: (int) $item->unit_cost_minor,
                actor: $actor,
                reason: $reason,
                notes: isset($data['notes']) ? strip_tags((string) $data['notes']) : null,
                sourceType: 'transfer',
                sourceId: (string) $outMove->id,
            );

            return ['out' => $outMove, 'in' => $inMove];
        }, 3);
    }

    /**
     * Core locked method that mutates `InventoryStockBalance` and `InventoryItem`, recalculates Weighted Average Cost
     * on `goods_received` movements, enforces negative-stock protections, appends an immutable `StockMovement`,
     * and dispatches low-stock operational notifications when `current_stock <= reorder_level`.
     *
     * MUST be called inside a `DB::transaction()` with `$item` already locked (`lockForUpdate()`).
     */
    public function applyLockedStockMovement(
        InventoryItem $item,
        StorageLocation $location,
        string $movementType,
        float $quantityDeltaBase,
        int $unitCostMinorPerBase,
        ?User $actor = null,
        ?string $reason = null,
        ?string $notes = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $idempotencyKey = null,
        ?string $uniqueSourceKey = null,
        bool $allowNegativeOverride = false,
        ?int $batchId = null,
        bool $updateWeightedAverageCost = false
    ): StockMovement {
        if ($idempotencyKey) {
            $existingByIdem = StockMovement::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existingByIdem) {
                return $existingByIdem->load(['item', 'location', 'actor']);
            }
        }

        if ($uniqueSourceKey) {
            $existingBySource = StockMovement::where('unique_source_key', $uniqueSourceKey)->lockForUpdate()->first();
            if ($existingBySource) {
                return $existingBySource->load(['item', 'location', 'actor']);
            }
        }

        $balanceRow = $this->lockOrCreateLocationBalance($item, $location);
        $beforeLoc = round((float) $balanceRow->quantity, 4);
        $afterLoc = round($beforeLoc + $quantityDeltaBase, 4);

        $isNegativeOverrideUsed = false;
        if ($quantityDeltaBase < 0 && $afterLoc < -0.0001) {
            $policyAllowsOverride = (bool) ($this->settings->all()['operations']['pos_allow_negative_stock_with_override'] ?? false);
            $canActorOverride = $actor && $actor->hasPermission('inventory.negative_override');

            if ($item->allow_negative_stock) {
                $isNegativeOverrideUsed = true;
            } elseif ($allowNegativeOverride) {
                if (! $canActorOverride) {
                    abort(403, 'You do not have permission to authorize a negative stock override.');
                }
                if (! $policyAllowsOverride) {
                    throw ValidationException::withMessages([
                        'stock' => ['Negative stock override is currently disabled by hotel policy.'],
                    ]);
                }
                if (! filled($reason)) {
                    throw ValidationException::withMessages([
                        'negative_stock_reason' => ['A reason is required when authorizing a negative stock override.'],
                    ]);
                }
                $isNegativeOverrideUsed = true;
            } else {
                throw ValidationException::withMessages([
                    'stock' => ["Insufficient stock for [{$item->name}] at [{$location->name}]: available {$beforeLoc} {$item->base_unit}, required ".abs($quantityDeltaBase)." {$item->base_unit}."],
                ]);
            }
        }

        // Update Weighted Average Cost when receiving positive stock with cost
        $effectiveUnitCostMinor = max(0, $unitCostMinorPerBase);
        if ($updateWeightedAverageCost && $quantityDeltaBase > 0 && $effectiveUnitCostMinor > 0) {
            $oldTotalStock = max(0.0, (float) $item->current_stock);
            $oldCostMinor = max(0, (int) $item->unit_cost_minor);
            $newTotalStock = $oldTotalStock + $quantityDeltaBase;

            $newWeightedCostMinor = $newTotalStock > 0
                ? (int) round((($oldTotalStock * $oldCostMinor) + ($quantityDeltaBase * $effectiveUnitCostMinor)) / $newTotalStock)
                : $effectiveUnitCostMinor;

            $item->unit_cost_minor = $newWeightedCostMinor;
            $item->unit_cost = AvailabilityService::toMajor($newWeightedCostMinor);
            $item->last_purchase_unit_cost_minor = $effectiveUnitCostMinor;
        } else {
            $effectiveUnitCostMinor = (int) $item->unit_cost_minor;
        }

        $balanceRow->update(['quantity' => $afterLoc]);

        // Recompute aggregate current_stock across all storage locations for this item
        $totalStock = (float) InventoryStockBalance::where('inventory_item_id', $item->id)->lockForUpdate()->sum('quantity');
        $item->current_stock = round($totalStock, 4);
        $item->save();

        $totalCostMinor = (int) round(abs($quantityDeltaBase) * $effectiveUnitCostMinor);
        if ($quantityDeltaBase < 0) {
            $totalCostMinor = -$totalCostMinor;
        }

        $movement = StockMovement::create([
            'movement_reference' => $this->generateUniqueMovementReference(),
            'idempotency_key' => $idempotencyKey,
            'unique_source_key' => $uniqueSourceKey,
            'inventory_item_id' => $item->id,
            'storage_location_id' => $location->id,
            'batch_id' => $batchId,
            'movement_type' => $movementType,
            'quantity_delta' => $quantityDeltaBase,
            'balance_before' => $beforeLoc,
            'balance_after' => $afterLoc,
            'unit' => $item->base_unit,
            'currency_code' => $item->currency_code,
            'unit_cost_minor' => $effectiveUnitCostMinor,
            'total_cost_minor' => $totalCostMinor,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'is_negative_override' => $isNegativeOverrideUsed,
            'reason' => $reason,
            'notes' => $notes,
            'actor_id' => $actor?->id,
            'occurred_at' => now(),
        ]);

        // Low-stock operational notification check when stock is consumed or reduced
        if ($quantityDeltaBase < 0 && $item->is_active && (float) $item->current_stock <= (float) $item->reorder_level) {
            OperationalNotification::create([
                'type' => 'inventory.low_stock',
                'severity' => (float) $item->current_stock <= 0 ? 'critical' : 'warning',
                'title' => ((float) $item->current_stock <= 0 ? 'Depleted Stock: ' : 'Low Stock Alert: ').$item->name,
                'message' => "{$item->name} ({$item->sku}) balance is {$item->current_stock} {$item->base_unit} (reorder threshold: {$item->reorder_level} {$item->base_unit}).",
                'target_permission' => 'inventory.view',
                'subject_type' => InventoryItem::class,
                'subject_id' => $item->id,
                'metadata' => [
                    'sku' => $item->sku,
                    'current_stock' => $item->current_stock,
                    'reorder_level' => $item->reorder_level,
                    'unit' => $item->base_unit,
                ],
            ]);
        }

        $this->audit->log('inventory.stock_movement_recorded', $movement, [
            'movement_reference' => $movement->movement_reference,
            'sku' => $item->sku,
            'location' => $location->code,
            'movement_type' => $movementType,
            'quantity_delta' => $quantityDeltaBase,
            'balance_before' => $beforeLoc,
            'balance_after' => $afterLoc,
            'is_negative_override' => $isNegativeOverrideUsed,
        ], 'success', $actor?->id);

        if ($isNegativeOverrideUsed) {
            $this->audit->log('inventory.negative_stock_override', $movement, [
                'movement_reference' => $movement->movement_reference,
                'sku' => $item->sku,
                'location' => $location->code,
                'balance_after' => $afterLoc,
                'reason' => $reason,
            ], 'success', $actor?->id);
        }

        return $movement->load(['item', 'location', 'actor']);
    }

    public function lockOrCreateLocationBalance(InventoryItem $item, StorageLocation $location): InventoryStockBalance
    {
        $balance = InventoryStockBalance::where('inventory_item_id', $item->id)
            ->where('storage_location_id', $location->id)
            ->lockForUpdate()
            ->first();

        if (! $balance) {
            InventoryStockBalance::create([
                'inventory_item_id' => $item->id,
                'storage_location_id' => $location->id,
                'quantity' => 0.0000,
            ]);
            $balance = InventoryStockBalance::where('inventory_item_id', $item->id)
                ->where('storage_location_id', $location->id)
                ->lockForUpdate()
                ->firstOrFail();
        }

        return $balance;
    }

    /**
     * Scan for batches expiring within `$withinDays` and create operational notifications if not already alerted today.
     */
    public function checkExpiringBatches(int $withinDays = 7): int
    {
        $cutoff = now()->addDays($withinDays)->format('Y-m-d');
        $expiring = InventoryBatch::with(['item', 'location'])
            ->where('remaining_quantity', '>', 0)
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', $cutoff)
            ->get();

        $count = 0;
        foreach ($expiring as $batch) {
            $alreadyNotified = OperationalNotification::where('type', 'inventory.expiring_batch')
                ->where('subject_type', InventoryBatch::class)
                ->where('subject_id', $batch->id)
                ->exists();

            if (! $alreadyNotified) {
                OperationalNotification::create([
                    'type' => 'inventory.expiring_batch',
                    'severity' => $batch->expiry_date->format('Y-m-d') <= now()->format('Y-m-d') ? 'critical' : 'warning',
                    'title' => "Expiring Batch: {$batch->item?->name} ({$batch->batch_number})",
                    'message' => "Batch {$batch->batch_number} ({$batch->remaining_quantity} {$batch->item?->base_unit}) at {$batch->location?->name} expires on {$batch->expiry_date->format('Y-m-d')}.",
                    'target_permission' => 'inventory.view',
                    'subject_type' => InventoryBatch::class,
                    'subject_id' => $batch->id,
                ]);
                $count++;
            }
        }

        return $count;
    }

    private function generateUniqueMovementReference(): string
    {
        $date = now()->format('Ymd');
        for ($i = 0; $i < 10; $i++) {
            $ref = 'STM-'.$date.'-'.strtoupper(Str::random(7));
            if (! StockMovement::where('movement_reference', $ref)->exists()) {
                return $ref;
            }
        }

        return 'STM-'.$date.'-'.strtoupper(Str::random(9));
    }
}
