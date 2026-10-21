<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceiving;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Supplier;
use App\Services\AuditLogger;
use App\Services\AvailabilityService;
use App\Services\ProcurementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProcurementController extends Controller
{
    public function __construct(
        private ProcurementService $procurement,
        private AuditLogger $audit,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        Gate::authorize('procurement.view');

        $user = $request->user();
        $canViewCosts = $user->hasPermission('procurement.costs.view');

        $pendingRequisitions = PurchaseRequisition::where('status', PurchaseRequisition::STATUS_PENDING_APPROVAL)->count();
        $pendingPoApprovals = PurchaseOrder::where('status', PurchaseOrder::STATUS_PENDING_APPROVAL)->count();
        $openPos = PurchaseOrder::whereIn('status', [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_PARTIALLY_RECEIVED])->count();
        $delayedPos = PurchaseOrder::whereIn('status', [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_PARTIALLY_RECEIVED])
            ->whereNotNull('expected_delivery_date')
            ->where('expected_delivery_date', '<', now()->format('Y-m-d'))
            ->count();
        $discrepancyGrns = GoodsReceiving::where('has_discrepancy', true)->count();

        $totalApprovedSpendMinor = $canViewCosts
            ? (int) PurchaseOrder::whereIn('status', [
                PurchaseOrder::STATUS_APPROVED,
                PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
                PurchaseOrder::STATUS_RECEIVED,
            ])->sum('total_minor')
            : null;

        $totalReceivedSpendMinor = $canViewCosts
            ? (int) GoodsReceiving::sum('total_received_cost_minor')
            : null;

        return response()->json([
            'data' => [
                'pending_requisitions' => $pendingRequisitions,
                'pending_po_approvals' => $pendingPoApprovals,
                'open_purchase_orders' => $openPos,
                'delayed_purchase_orders' => $delayedPos,
                'discrepancy_receivings' => $discrepancyGrns,
                'total_approved_spend_minor' => $totalApprovedSpendMinor,
                'total_approved_spend_amount' => $totalApprovedSpendMinor !== null ? AvailabilityService::toMajor($totalApprovedSpendMinor) : null,
                'total_received_spend_minor' => $totalReceivedSpendMinor,
                'total_received_spend_amount' => $totalReceivedSpendMinor !== null ? AvailabilityService::toMajor($totalReceivedSpendMinor) : null,
            ],
        ]);
    }

    public function suppliers(Request $request): JsonResponse
    {
        Gate::authorize('procurement.view');

        $user = $request->user();
        $suppliers = Supplier::with('supportedItems.inventoryItem')
            ->orderBy('name')
            ->get()
            ->map(fn (Supplier $s) => $s->toArrayForUser($user));

        return response()->json(['data' => $suppliers]);
    }

    public function storeSupplier(Request $request): JsonResponse
    {
        Gate::authorize('procurement.suppliers.manage');

        $v = $request->validate([
            'supplier_code' => ['required', 'string', 'max:40', 'unique:suppliers,supplier_code'],
            'name' => ['required', 'string', 'max:160'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'address' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'suspended', 'inactive'])],
            'tax_id' => ['nullable', 'string', 'max:64'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'bank_account_number' => ['nullable', 'string', 'max:40'],
            'bank_account_name' => ['nullable', 'string', 'max:160'],
            'payment_terms' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $supplier = Supplier::create([
            'supplier_code' => strtoupper(trim(strip_tags($v['supplier_code']))),
            'name' => trim(strip_tags($v['name'])),
            'contact_person' => isset($v['contact_person']) ? strip_tags($v['contact_person']) : null,
            'phone' => isset($v['phone']) ? strip_tags($v['phone']) : null,
            'email' => $v['email'] ?? null,
            'address' => isset($v['address']) ? strip_tags($v['address']) : null,
            'status' => $v['status'] ?? 'active',
            'tax_id' => isset($v['tax_id']) ? strip_tags($v['tax_id']) : null,
            'bank_name' => isset($v['bank_name']) ? strip_tags($v['bank_name']) : null,
            'bank_account_number' => isset($v['bank_account_number']) ? strip_tags($v['bank_account_number']) : null,
            'bank_account_name' => isset($v['bank_account_name']) ? strip_tags($v['bank_account_name']) : null,
            'payment_terms' => isset($v['payment_terms']) ? strip_tags($v['payment_terms']) : null,
            'notes' => isset($v['notes']) ? strip_tags($v['notes']) : null,
            'created_by' => $request->user()->id,
        ]);

        $this->audit->log('procurement.supplier_created', $supplier, [
            'supplier_code' => $supplier->supplier_code,
            'name' => $supplier->name,
        ]);

        return response()->json([
            'message' => 'Supplier created.',
            'data' => $supplier->toArrayForUser($request->user()),
        ], 201);
    }

    public function updateSupplier(Request $request, Supplier $supplier): JsonResponse
    {
        Gate::authorize('procurement.suppliers.manage');

        $v = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'address' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'suspended', 'inactive'])],
            'tax_id' => ['nullable', 'string', 'max:64'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'bank_account_number' => ['nullable', 'string', 'max:40'],
            'bank_account_name' => ['nullable', 'string', 'max:160'],
            'payment_terms' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $supplier->update($v);
        $this->audit->log('procurement.supplier_updated', $supplier, ['supplier_code' => $supplier->supplier_code]);

        return response()->json([
            'message' => 'Supplier updated.',
            'data' => $supplier->fresh()->toArrayForUser($request->user()),
        ]);
    }

    public function requisitions(Request $request): JsonResponse
    {
        Gate::authorize('procurement.view');

        $list = PurchaseRequisition::with(['location', 'items.inventoryItem', 'requester', 'reviewer'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->query('status')))
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $list]);
    }

    public function storeRequisition(Request $request): JsonResponse
    {
        Gate::authorize('procurement.create');

        $v = $request->validate([
            'storage_location_id' => ['nullable', 'integer', 'exists:storage_locations,id'],
            'priority' => ['sometimes', 'string', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'required_by_date' => ['nullable', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.inventory_item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'items.*.quantity_requested' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit' => ['nullable', 'string', 'max:24'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        $req = $this->procurement->createRequisition($v, $request->user());

        return response()->json([
            'message' => 'Purchase requisition submitted.',
            'data' => $req,
        ], 201);
    }

    public function reviewRequisition(Request $request, PurchaseRequisition $purchaseRequisition): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['procurement.requisitions.approve', 'procurement.approve'])) {
            abort(403, 'You do not have permission to approve or reject purchase requisitions.');
        }

        $v = $request->validate([
            'decision' => ['required', 'string', Rule::in(['approve', 'reject'])],
            'review_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $updated = $this->procurement->reviewRequisition(
            $purchaseRequisition,
            $v['decision'],
            $request->user(),
            $v['review_notes'] ?? null
        );

        return response()->json([
            'message' => "Purchase requisition {$updated->status}.",
            'data' => $updated,
        ]);
    }

    public function purchaseOrders(Request $request): JsonResponse
    {
        Gate::authorize('procurement.view');

        $user = $request->user();
        $list = PurchaseOrder::with(['supplier', 'location', 'items.inventoryItem', 'creator', 'approver', 'receivings'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->query('status')))
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', (int) $request->query('supplier_id')))
            ->orderByDesc('id')
            ->get()
            ->map(fn (PurchaseOrder $po) => $po->toArrayForUser($user));

        return response()->json(['data' => $list]);
    }

    public function storePurchaseOrder(Request $request): JsonResponse
    {
        Gate::authorize('procurement.create');

        $v = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'purchase_requisition_id' => ['nullable', 'integer', 'exists:purchase_requisitions,id'],
            'storage_location_id' => ['required', 'integer', 'exists:storage_locations,id'],
            'expected_delivery_date' => ['nullable', 'date_format:Y-m-d'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.inventory_item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'items.*.unit' => ['nullable', 'string', 'max:24'],
            'items.*.conversion_to_base' => ['nullable', 'numeric', 'min:0.0001'],
            'items.*.quantity_ordered' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_cost_minor' => ['nullable', 'integer', 'min:0'],
        ]);

        $po = $this->procurement->createPurchaseOrder($v, $request->user());

        return response()->json([
            'message' => 'Purchase order created and queued for approval.',
            'data' => $po->toArrayForUser($request->user()),
        ], 201);
    }

    public function reviewPurchaseOrder(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        Gate::authorize('procurement.approve');

        $v = $request->validate([
            'decision' => ['required', 'string', Rule::in(['approve', 'cancel'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $updated = $this->procurement->reviewPurchaseOrder(
            $purchaseOrder,
            $v['decision'],
            $request->user(),
            $v['reason'] ?? null
        );

        return response()->json([
            'message' => "Purchase order {$updated->status}.",
            'data' => $updated->toArrayForUser($request->user()),
        ]);
    }

    public function receiveGoods(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        Gate::authorize('procurement.receive');

        $v = $request->validate([
            'idempotency_key' => ['nullable', 'string', 'max:96'],
            'storage_location_id' => ['nullable', 'integer', 'exists:storage_locations,id'],
            'supplier_delivery_note' => ['nullable', 'string', 'max:80'],
            'supplier_invoice_reference' => ['nullable', 'string', 'max:80'],
            'allow_over_delivery' => ['sometimes', 'boolean'],
            'discrepancy_notes' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required', 'integer', 'exists:purchase_order_items,id'],
            'items.*.quantity_received' => ['required', 'numeric', 'min:0'],
            'items.*.quantity_rejected' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_cost_minor' => ['nullable', 'integer', 'min:0'],
            'items.*.batch_number' => ['nullable', 'string', 'max:64'],
            'items.*.expiry_date' => ['nullable', 'date_format:Y-m-d'],
            'items.*.discrepancy_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $grn = $this->procurement->receiveGoods($purchaseOrder, $v, $request->user());

        return response()->json([
            'message' => 'Goods receiving committed and stock updated.',
            'data' => $grn->toArrayForUser($request->user()),
        ], 201);
    }

    public function receivings(Request $request): JsonResponse
    {
        Gate::authorize('procurement.view');

        $user = $request->user();
        $list = GoodsReceiving::with(['purchaseOrder', 'supplier', 'location', 'items.inventoryItem', 'receiver'])
            ->when($request->filled('purchase_order_id'), fn ($q) => $q->where('purchase_order_id', (int) $request->query('purchase_order_id')))
            ->orderByDesc('id')
            ->get()
            ->map(fn (GoodsReceiving $g) => $g->toArrayForUser($user));

        return response()->json(['data' => $list]);
    }
}
