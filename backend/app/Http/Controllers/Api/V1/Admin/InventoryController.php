<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\MenuItemRecipe;
use App\Models\StockMovement;
use App\Models\StorageLocation;
use App\Services\AuditLogger;
use App\Services\AvailabilityService;
use App\Services\InventoryService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class InventoryController extends Controller
{
    public function __construct(
        private InventoryService $inventory,
        private SettingsService $settings,
        private AuditLogger $audit,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        Gate::authorize('inventory.view');

        $this->inventory->checkExpiringBatches(7);

        $user = $request->user();
        $canViewCosts = $user->hasPermission('inventory.costs.view');

        $items = InventoryItem::with(['category', 'balances.location'])->orderBy('name')->get();
        $lowStockItems = $items->filter(fn (InventoryItem $i) => $i->is_low_stock)->values();

        $totalValuationMinor = 0;
        if ($canViewCosts) {
            $totalValuationMinor = (int) $items->sum('stock_valuation_minor');
        }

        $recentMovements = StockMovement::with(['item', 'location', 'actor'])
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->map(fn (StockMovement $m) => $m->toArrayForUser($user));

        $wasteMinor30d = null;
        if ($canViewCosts) {
            $wasteMinor30d = abs((int) StockMovement::where('movement_type', StockMovement::TYPE_WASTE)
                ->where('occurred_at', '>=', now()->subDays(30))
                ->sum('total_cost_minor'));
        }

        return response()->json([
            'data' => [
                'summary' => [
                    'total_items' => $items->count(),
                    'active_items' => $items->where('is_active', true)->count(),
                    'low_stock_count' => $lowStockItems->count(),
                    'depleted_count' => $items->where('is_active', true)->filter(fn (InventoryItem $i) => (float) $i->current_stock <= 0)->count(),
                    'total_valuation_minor' => $canViewCosts ? $totalValuationMinor : null,
                    'total_valuation_amount' => $canViewCosts ? AvailabilityService::toMajor($totalValuationMinor) : null,
                    'waste_30d_minor' => $wasteMinor30d,
                    'waste_30d_amount' => $wasteMinor30d !== null ? AvailabilityService::toMajor($wasteMinor30d) : null,
                ],
                'categories' => InventoryCategory::orderBy('sort_order')->orderBy('name')->get(),
                'locations' => StorageLocation::orderBy('id')->get(),
                'low_stock_items' => $lowStockItems->map(fn (InventoryItem $i) => $i->toArrayForUser($user)),
                'recent_movements' => $recentMovements,
            ],
        ]);
    }

    public function categories(Request $request): JsonResponse
    {
        Gate::authorize('inventory.view');

        return response()->json([
            'data' => InventoryCategory::orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        Gate::authorize('inventory.create');

        $v = $request->validate([
            'code' => ['required', 'string', 'max:40', 'unique:inventory_categories,code'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $cat = InventoryCategory::create([
            'code' => strtoupper(trim(strip_tags($v['code']))),
            'name' => trim(strip_tags($v['name'])),
            'description' => isset($v['description']) ? strip_tags($v['description']) : null,
            'is_active' => $v['is_active'] ?? true,
            'sort_order' => (int) ($v['sort_order'] ?? 0),
        ]);

        $this->audit->log('inventory.category_created', $cat, ['code' => $cat->code, 'name' => $cat->name]);

        return response()->json(['message' => 'Inventory category created.', 'data' => $cat], 201);
    }

    public function locations(Request $request): JsonResponse
    {
        Gate::authorize('inventory.view');

        return response()->json([
            'data' => StorageLocation::orderBy('id')->get(),
        ]);
    }

    public function storeLocation(Request $request): JsonResponse
    {
        Gate::authorize('inventory.create');

        $v = $request->validate([
            'code' => ['required', 'string', 'max:40', 'unique:storage_locations,code'],
            'name' => ['required', 'string', 'max:120'],
            'location_type' => ['sometimes', 'string', Rule::in(['main_store', 'kitchen', 'bar', 'outlet', 'housekeeping', 'general'])],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $loc = StorageLocation::create([
            'code' => strtoupper(trim(strip_tags($v['code']))),
            'name' => trim(strip_tags($v['name'])),
            'location_type' => $v['location_type'] ?? 'store',
            'description' => isset($v['description']) ? strip_tags($v['description']) : null,
            'is_default' => $v['is_default'] ?? false,
            'is_active' => $v['is_active'] ?? true,
        ]);

        $this->audit->log('inventory.location_created', $loc, ['code' => $loc->code, 'name' => $loc->name]);

        return response()->json(['message' => 'Storage location created.', 'data' => $loc], 201);
    }

    public function items(Request $request): JsonResponse
    {
        Gate::authorize('inventory.view');

        $user = $request->user();
        $items = InventoryItem::with(['category', 'balances.location', 'batches'])
            ->when($request->filled('inventory_category_id'), fn ($q) => $q->where('inventory_category_id', (int) $request->query('inventory_category_id')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = '%'.trim((string) $request->query('search')).'%';
                $q->where(fn ($sq) => $sq->where('name', 'like', $s)->orWhere('sku', 'like', $s));
            })
            ->orderBy('name')
            ->get()
            ->map(fn (InventoryItem $i) => $i->toArrayForUser($user));

        return response()->json(['data' => $items]);
    }

    public function storeItem(Request $request): JsonResponse
    {
        Gate::authorize('inventory.create');

        $v = $request->validate([
            'sku' => ['required', 'string', 'max:48', 'unique:inventory_items,sku'],
            'inventory_category_id' => ['nullable', 'integer', 'exists:inventory_categories,id'],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'base_unit' => ['required', 'string', 'max:24'],
            'purchase_unit' => ['nullable', 'string', 'max:24'],
            'purchase_to_base_factor' => ['nullable', 'numeric', 'min:0.0001'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'track_batches' => ['sometimes', 'boolean'],
            'allow_negative_stock' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $currency = $this->settings->all()['hotel']['currency'] ?? 'NGN';
        $unitCostMinor = AvailabilityService::toMinor($v['unit_cost'] ?? 0);

        $item = InventoryItem::create([
            'sku' => strtoupper(trim(strip_tags($v['sku']))),
            'inventory_category_id' => $v['inventory_category_id'] ?? null,
            'name' => trim(strip_tags($v['name'])),
            'description' => isset($v['description']) ? strip_tags($v['description']) : null,
            'base_unit' => strtolower(trim(strip_tags($v['base_unit']))),
            'purchase_unit' => isset($v['purchase_unit']) && filled($v['purchase_unit'])
                ? strtolower(trim(strip_tags($v['purchase_unit'])))
                : strtolower(trim(strip_tags($v['base_unit']))),
            'purchase_to_base_factor' => (float) ($v['purchase_to_base_factor'] ?? 1.0),
            'current_stock' => 0,
            'reorder_level' => (float) ($v['reorder_level'] ?? 0),
            'currency_code' => $currency,
            'costing_method' => 'weighted_average',
            'unit_cost_minor' => $unitCostMinor,
            'unit_cost' => AvailabilityService::toMajor($unitCostMinor),
            'track_batches' => (bool) ($v['track_batches'] ?? false),
            'allow_negative_stock' => (bool) ($v['allow_negative_stock'] ?? false),
            'is_active' => (bool) ($v['is_active'] ?? true),
            'created_by' => $request->user()->id,
        ]);

        $this->audit->log('inventory.item_created', $item, ['sku' => $item->sku, 'name' => $item->name]);

        return response()->json([
            'message' => 'Inventory item created.',
            'data' => $item->load(['category', 'balances.location'])->toArrayForUser($request->user()),
        ], 201);
    }

    public function updateItem(Request $request, InventoryItem $inventoryItem): JsonResponse
    {
        Gate::authorize('inventory.edit');

        $v = $request->validate([
            'inventory_category_id' => ['nullable', 'integer', 'exists:inventory_categories,id'],
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'purchase_unit' => ['sometimes', 'required', 'string', 'max:24'],
            'purchase_to_base_factor' => ['sometimes', 'numeric', 'min:0.0001'],
            'reorder_level' => ['sometimes', 'numeric', 'min:0'],
            'track_batches' => ['sometimes', 'boolean'],
            'allow_negative_stock' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // Prevent direct current_stock overwrite via updateItem
        $inventoryItem->update($v);

        $this->audit->log('inventory.item_updated', $inventoryItem, ['sku' => $inventoryItem->sku]);

        return response()->json([
            'message' => 'Inventory item updated.',
            'data' => $inventoryItem->fresh(['category', 'balances.location'])->toArrayForUser($request->user()),
        ]);
    }

    public function recordMovement(Request $request): JsonResponse
    {
        $type = (string) $request->input('movement_type', StockMovement::TYPE_ADJUSTMENT);
        if ($type === StockMovement::TYPE_WASTE) {
            if (! $request->user()->hasAnyPermission(['inventory.waste', 'inventory.adjust'])) {
                abort(403, 'You do not have permission to record stock waste.');
            }
        } else {
            Gate::authorize('inventory.adjust');
        }

        $v = $request->validate([
            'inventory_item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'storage_location_id' => ['required', 'integer', 'exists:storage_locations,id'],
            'movement_type' => ['required', 'string', Rule::in([
                StockMovement::TYPE_ADJUSTMENT,
                StockMovement::TYPE_WASTE,
                StockMovement::TYPE_SUPPLIER_RETURN,
                StockMovement::TYPE_RESTOCK_RETURN,
            ])],
            'direction' => ['nullable', 'string', Rule::in(['add', 'deduct'])],
            'quantity' => ['required', 'numeric'],
            'unit' => ['nullable', 'string', 'max:24'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'max:96'],
            'allow_negative_override' => ['sometimes', 'boolean'],
        ]);

        $movement = $this->inventory->recordAdjustmentOrWaste($v, $request->user());

        return response()->json([
            'message' => 'Stock movement recorded.',
            'data' => $movement->toArrayForUser($request->user()),
        ], 201);
    }

    public function recordStockCount(Request $request): JsonResponse
    {
        Gate::authorize('inventory.adjust');

        $v = $request->validate([
            'inventory_item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'storage_location_id' => ['required', 'integer', 'exists:storage_locations,id'],
            'counted_quantity' => ['required', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:24'],
            'reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'max:96'],
        ]);

        $movement = $this->inventory->recordStockCount($v, $request->user());

        return response()->json([
            'message' => 'Stock count reconciled.',
            'data' => $movement->toArrayForUser($request->user()),
        ], 201);
    }

    public function transfer(Request $request): JsonResponse
    {
        Gate::authorize('inventory.adjust');

        $v = $request->validate([
            'inventory_item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'from_location_id' => ['required', 'integer', 'exists:storage_locations,id'],
            'to_location_id' => ['required', 'integer', 'exists:storage_locations,id'],
            'quantity' => ['required', 'numeric', 'min:0.0001'],
            'unit' => ['nullable', 'string', 'max:24'],
            'reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $pair = $this->inventory->transferStock($v, $request->user());

        return response()->json([
            'message' => 'Stock transferred between storage locations.',
            'data' => [
                'out' => $pair['out']->toArrayForUser($request->user()),
                'in' => $pair['in']->toArrayForUser($request->user()),
            ],
        ], 201);
    }

    public function movements(Request $request): JsonResponse
    {
        Gate::authorize('inventory.view');

        $user = $request->user();
        $paginated = StockMovement::with(['item', 'location', 'actor'])
            ->when($request->filled('inventory_item_id'), fn ($q) => $q->where('inventory_item_id', (int) $request->query('inventory_item_id')))
            ->when($request->filled('storage_location_id'), fn ($q) => $q->where('storage_location_id', (int) $request->query('storage_location_id')))
            ->when($request->filled('movement_type'), fn ($q) => $q->where('movement_type', (string) $request->query('movement_type')))
            ->orderByDesc('id')
            ->paginate((int) ($request->query('per_page', 30)));

        $paginated->getCollection()->transform(fn (StockMovement $m) => $m->toArrayForUser($user));

        return response()->json($paginated);
    }

    public function recipes(Request $request): JsonResponse
    {
        Gate::authorize('inventory.view');

        $recipes = MenuItemRecipe::with(['menuItem', 'variant', 'inventoryItem'])
            ->when($request->filled('menu_item_id'), fn ($q) => $q->where('menu_item_id', (int) $request->query('menu_item_id')))
            ->orderBy('menu_item_id')
            ->get();

        return response()->json(['data' => $recipes]);
    }

    public function storeRecipe(Request $request): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['inventory.edit', 'pos.menu'])) {
            abort(403, 'You do not have permission to manage menu recipes.');
        }

        $v = $request->validate([
            'menu_item_id' => ['required', 'integer', 'exists:menu_items,id'],
            'menu_item_variant_id' => ['nullable', 'integer', 'exists:menu_item_variants,id'],
            'inventory_item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'quantity_required' => ['required', 'numeric', 'min:0.0001'],
            'unit' => ['nullable', 'string', 'max:24'],
            'conversion_to_base' => ['nullable', 'numeric', 'min:0.0001'],
            'waste_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'version' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var InventoryItem $invItem */
        $invItem = InventoryItem::findOrFail((int) $v['inventory_item_id']);
        $unit = isset($v['unit']) && filled($v['unit']) ? strtolower(trim($v['unit'])) : $invItem->base_unit;
        $conv = isset($v['conversion_to_base']) ? (float) $v['conversion_to_base'] : null;

        // Validate unit conversion compatibility
        $baseOne = $this->inventory->convertToBaseQuantity($invItem, 1.0, $unit, $conv);

        $recipe = MenuItemRecipe::updateOrCreate(
            [
                'menu_item_id' => (int) $v['menu_item_id'],
                'menu_item_variant_id' => $v['menu_item_variant_id'] ?? null,
                'inventory_item_id' => $invItem->id,
            ],
            [
                'quantity_required' => (float) $v['quantity_required'],
                'unit' => $unit,
                'conversion_to_base' => $conv ?? $baseOne,
                'waste_percentage' => (float) ($v['waste_percentage'] ?? 0.0),
                'version' => (int) ($v['version'] ?? 1),
                'is_active' => true,
                'notes' => isset($v['notes']) ? strip_tags($v['notes']) : null,
            ]
        );

        $this->audit->log('inventory.recipe_saved', $recipe, [
            'menu_item_id' => $recipe->menu_item_id,
            'inventory_item_id' => $recipe->inventory_item_id,
            'quantity_required' => $recipe->quantity_required,
            'unit' => $recipe->unit,
        ]);

        return response()->json([
            'message' => 'Menu recipe saved.',
            'data' => $recipe->load(['menuItem', 'variant', 'inventoryItem']),
        ], 201);
    }

    public function destroyRecipe(Request $request, MenuItemRecipe $menuItemRecipe): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['inventory.edit', 'pos.menu'])) {
            abort(403, 'You do not have permission to delete menu recipes.');
        }

        $this->audit->log('inventory.recipe_deleted', $menuItemRecipe, [
            'menu_item_id' => $menuItemRecipe->menu_item_id,
            'inventory_item_id' => $menuItemRecipe->inventory_item_id,
        ]);

        $menuItemRecipe->delete();

        return response()->json(['message' => 'Menu recipe removed.']);
    }
}
