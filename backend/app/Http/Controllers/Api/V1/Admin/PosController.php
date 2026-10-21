<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuItemModifier;
use App\Models\MenuItemVariant;
use App\Models\PosOrder;
use App\Models\PosOrderItem;
use App\Models\PosOutlet;
use App\Models\PosPayment;
use App\Models\PosPrepStation;
use App\Models\Reservation;
use App\Services\AuditLogger;
use App\Services\AvailabilityService;
use App\Services\PosService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PosController extends Controller
{
    public function __construct(
        private PosService $pos,
        private SettingsService $settings,
        private AuditLogger $audit,
    ) {}

    public function outlets(Request $request): JsonResponse
    {
        Gate::authorize('pos.view');

        return response()->json([
            'data' => PosOutlet::with(['defaultStorageLocation', 'prepStations', 'menuCategories'])
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function storeOutlet(Request $request): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['pos.menu', 'pos.outlets.manage'])) {
            abort(403, 'You do not have permission to manage POS outlets or menus.');
        }

        $v = $request->validate([
            'code' => ['required', 'string', 'max:40', 'unique:pos_outlets,code'],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', Rule::in(['restaurant', 'bar', 'room_service', 'cafe', 'pool_bar'])],
            'default_storage_location_id' => ['nullable', 'integer', 'exists:storage_locations,id'],
            'tax_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'service_charge_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'supports_room_service' => ['sometimes', 'boolean'],
            'supports_room_charge' => ['sometimes', 'boolean'],
            'accepts_orders' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'operating_hours' => ['nullable', 'array'],
        ]);

        $outlet = PosOutlet::create([
            'code' => strtoupper(trim(strip_tags($v['code']))),
            'name' => trim(strip_tags($v['name'])),
            'type' => $v['type'],
            'default_storage_location_id' => $v['default_storage_location_id'] ?? null,
            'tax_percentage' => $v['tax_percentage'] ?? null,
            'service_charge_percentage' => $v['service_charge_percentage'] ?? null,
            'supports_room_service' => (bool) ($v['supports_room_service'] ?? ($v['type'] === 'room_service')),
            'supports_room_charge' => (bool) ($v['supports_room_charge'] ?? true),
            'accepts_orders' => (bool) ($v['accepts_orders'] ?? true),
            'is_active' => (bool) ($v['is_active'] ?? true),
            'operating_hours' => $v['operating_hours'] ?? null,
        ]);

        $this->audit->log('pos.outlet_created', $outlet, ['code' => $outlet->code, 'name' => $outlet->name]);

        return response()->json(['message' => 'POS outlet created.', 'data' => $outlet->load(['defaultStorageLocation', 'prepStations'])], 201);
    }

    public function updateOutlet(Request $request, PosOutlet $posOutlet): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['pos.menu', 'pos.outlets.manage'])) {
            abort(403, 'You do not have permission to manage POS outlets or menus.');
        }

        $v = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'type' => ['sometimes', 'required', 'string', Rule::in(['restaurant', 'bar', 'room_service', 'cafe', 'pool_bar'])],
            'default_storage_location_id' => ['nullable', 'integer', 'exists:storage_locations,id'],
            'tax_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'service_charge_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'supports_room_service' => ['sometimes', 'boolean'],
            'supports_room_charge' => ['sometimes', 'boolean'],
            'accepts_orders' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'operating_hours' => ['nullable', 'array'],
        ]);

        $posOutlet->update($v);
        $this->audit->log('pos.outlet_updated', $posOutlet, ['code' => $posOutlet->code]);

        return response()->json(['message' => 'POS outlet updated.', 'data' => $posOutlet->fresh(['defaultStorageLocation', 'prepStations'])]);
    }

    public function storePrepStation(Request $request): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['pos.menu', 'pos.outlets.manage'])) {
            abort(403, 'You do not have permission to manage POS outlets or menus.');
        }

        $v = $request->validate([
            'pos_outlet_id' => ['required', 'integer', 'exists:pos_outlets,id'],
            'code' => ['required', 'string', 'max:40', 'unique:pos_prep_stations,code'],
            'name' => ['required', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $station = PosPrepStation::create([
            'pos_outlet_id' => (int) $v['pos_outlet_id'],
            'code' => strtoupper(trim(strip_tags($v['code']))),
            'name' => trim(strip_tags($v['name'])),
            'is_active' => (bool) ($v['is_active'] ?? true),
        ]);

        $this->audit->log('pos.prep_station_created', $station, ['code' => $station->code, 'name' => $station->name]);

        return response()->json(['message' => 'Preparation station created.', 'data' => $station], 201);
    }

    public function menuCategories(Request $request): JsonResponse
    {
        Gate::authorize('pos.view');

        return response()->json([
            'data' => MenuCategory::with('outlet')
                ->when($request->filled('pos_outlet_id'), function ($q) use ($request) {
                    $outletId = (int) $request->query('pos_outlet_id');
                    $q->where(fn ($sq) => $sq->whereNull('pos_outlet_id')->orWhere('pos_outlet_id', $outletId));
                })
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function storeMenuCategory(Request $request): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['pos.menu', 'pos.outlets.manage'])) {
            abort(403, 'You do not have permission to manage POS outlets or menus.');
        }

        $v = $request->validate([
            'pos_outlet_id' => ['nullable', 'integer', 'exists:pos_outlets,id'],
            'code' => ['nullable', 'string', 'max:40', 'unique:menu_categories,code'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $code = isset($v['code']) && filled($v['code'])
            ? strtoupper(trim(strip_tags($v['code'])))
            : strtoupper(Str::slug($v['name']).'-'.Str::random(4));

        $cat = MenuCategory::create([
            'pos_outlet_id' => $v['pos_outlet_id'] ?? null,
            'code' => $code,
            'name' => trim(strip_tags($v['name'])),
            'description' => isset($v['description']) ? strip_tags($v['description']) : null,
            'is_active' => (bool) ($v['is_active'] ?? true),
            'sort_order' => (int) ($v['sort_order'] ?? 0),
        ]);

        $this->audit->log('pos.menu_category_created', $cat, ['name' => $cat->name, 'code' => $cat->code]);

        return response()->json(['message' => 'Menu category created.', 'data' => $cat], 201);
    }

    public function updateMenuCategory(Request $request, MenuCategory $menuCategory): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['pos.menu', 'pos.outlets.manage'])) {
            abort(403, 'You do not have permission to manage POS outlets or menus.');
        }

        $v = $request->validate([
            'pos_outlet_id' => ['nullable', 'integer', 'exists:pos_outlets,id'],
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $menuCategory->update($v);
        $this->audit->log('pos.menu_category_updated', $menuCategory, ['name' => $menuCategory->name]);

        return response()->json(['message' => 'Menu category updated.', 'data' => $menuCategory->fresh()]);
    }

    public function menuItems(Request $request): JsonResponse
    {
        Gate::authorize('pos.view');

        $items = MenuItem::with(['outlet', 'category', 'prepStation', 'variants', 'modifiers', 'recipes.inventoryItem', 'directInventoryItem'])
            ->when($request->filled('pos_outlet_id'), function ($q) use ($request) {
                $outletId = (int) $request->query('pos_outlet_id');
                $q->where(fn ($sq) => $sq->whereNull('pos_outlet_id')->orWhere('pos_outlet_id', $outletId));
            })
            ->when($request->filled('menu_category_id'), fn ($q) => $q->where('menu_category_id', (int) $request->query('menu_category_id')))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $items]);
    }

    public function storeMenuItem(Request $request): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['pos.menu', 'pos.outlets.manage'])) {
            abort(403, 'You do not have permission to manage POS outlets or menus.');
        }

        $v = $request->validate([
            'sku' => ['required', 'string', 'max:48', 'unique:menu_items,sku'],
            'pos_outlet_id' => ['nullable', 'integer', 'exists:pos_outlets,id'],
            'menu_category_id' => ['required', 'integer', 'exists:menu_categories,id'],
            'pos_prep_station_id' => ['nullable', 'integer', 'exists:pos_prep_stations,id'],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'price_minor' => ['nullable', 'integer', 'min:0'],
            'is_room_service_eligible' => ['sometimes', 'boolean'],
            'is_available' => ['sometimes', 'boolean'],
            'is_sold_out' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'stock_tracking_policy' => ['sometimes', 'string', Rule::in(['none', 'direct_item', 'recipe'])],
            'direct_inventory_item_id' => ['nullable', 'integer', 'exists:inventory_items,id'],
            'direct_deduction_quantity' => ['nullable', 'numeric', 'min:0.0001'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $currency = $this->settings->all()['hotel']['currency'] ?? 'NGN';
        $priceMinor = isset($v['price_minor'])
            ? (int) $v['price_minor']
            : AvailabilityService::toMinor($v['price'] ?? 0);

        $item = MenuItem::create([
            'sku' => strtoupper(trim(strip_tags($v['sku']))),
            'pos_outlet_id' => $v['pos_outlet_id'] ?? null,
            'menu_category_id' => $v['menu_category_id'] ?? null,
            'pos_prep_station_id' => $v['pos_prep_station_id'] ?? null,
            'name' => trim(strip_tags($v['name'])),
            'description' => isset($v['description']) ? strip_tags($v['description']) : null,
            'currency_code' => $currency,
            'price_minor' => $priceMinor,
            'price' => AvailabilityService::toMajor($priceMinor),
            'is_room_service_eligible' => (bool) ($v['is_room_service_eligible'] ?? true),
            'is_available' => (bool) ($v['is_available'] ?? true),
            'is_sold_out' => (bool) ($v['is_sold_out'] ?? false),
            'is_active' => (bool) ($v['is_active'] ?? true),
            'stock_tracking_policy' => $v['stock_tracking_policy'] ?? 'none',
            'direct_inventory_item_id' => $v['direct_inventory_item_id'] ?? null,
            'direct_deduction_quantity' => (float) ($v['direct_deduction_quantity'] ?? 1.0),
            'sort_order' => (int) ($v['sort_order'] ?? 0),
        ]);

        $this->audit->log('pos.menu_item_created', $item, [
            'sku' => $item->sku,
            'name' => $item->name,
            'price_minor' => $item->price_minor,
            'stock_tracking_policy' => $item->stock_tracking_policy,
        ]);

        return response()->json([
            'message' => 'Menu item created.',
            'data' => $item->load(['outlet', 'category', 'prepStation', 'variants', 'modifiers', 'recipes.inventoryItem']),
        ], 201);
    }

    public function updateMenuItem(Request $request, MenuItem $menuItem): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['pos.menu', 'pos.outlets.manage'])) {
            abort(403, 'You do not have permission to manage POS outlets or menus.');
        }

        $v = $request->validate([
            'pos_outlet_id' => ['nullable', 'integer', 'exists:pos_outlets,id'],
            'menu_category_id' => ['nullable', 'integer', 'exists:menu_categories,id'],
            'pos_prep_station_id' => ['nullable', 'integer', 'exists:pos_prep_stations,id'],
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'price_minor' => ['nullable', 'integer', 'min:0'],
            'is_room_service_eligible' => ['sometimes', 'boolean'],
            'is_available' => ['sometimes', 'boolean'],
            'is_sold_out' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'stock_tracking_policy' => ['sometimes', 'string', Rule::in(['none', 'direct_item', 'recipe'])],
            'direct_inventory_item_id' => ['nullable', 'integer', 'exists:inventory_items,id'],
            'direct_deduction_quantity' => ['nullable', 'numeric', 'min:0.0001'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        if (isset($v['price_minor']) || isset($v['price'])) {
            $priceMinor = isset($v['price_minor'])
                ? (int) $v['price_minor']
                : AvailabilityService::toMinor($v['price']);
            $v['price_minor'] = $priceMinor;
            $v['price'] = AvailabilityService::toMajor($priceMinor);
        }

        $menuItem->update($v);
        $this->audit->log('pos.menu_item_updated', $menuItem, [
            'sku' => $menuItem->sku,
            'price_minor' => $menuItem->price_minor,
        ]);

        return response()->json([
            'message' => 'Menu item updated.',
            'data' => $menuItem->fresh(['outlet', 'category', 'prepStation', 'variants', 'modifiers', 'recipes.inventoryItem']),
        ]);
    }

    public function storeVariant(Request $request, MenuItem $menuItem): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['pos.menu', 'pos.outlets.manage'])) {
            abort(403, 'You do not have permission to manage POS outlets or menus.');
        }

        $v = $request->validate([
            'code' => ['nullable', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:80'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'price_minor' => ['nullable', 'integer', 'min:0'],
            'recipe_multiplier' => ['nullable', 'numeric', 'min:0.0001'],
            'is_default' => ['sometimes', 'boolean'],
            'is_sold_out' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $priceMinor = isset($v['price_minor'])
            ? (int) $v['price_minor']
            : AvailabilityService::toMinor($v['price'] ?? 0);

        $code = isset($v['code']) && filled($v['code'])
            ? strtoupper(trim(strip_tags($v['code'])))
            : strtoupper(Str::slug($v['name']).'-'.Str::random(3));

        $variant = MenuItemVariant::create([
            'menu_item_id' => $menuItem->id,
            'code' => $code,
            'name' => trim(strip_tags($v['name'])),
            'price_minor' => $priceMinor,
            'price' => AvailabilityService::toMajor($priceMinor),
            'recipe_multiplier' => (float) ($v['recipe_multiplier'] ?? 1.0),
            'is_default' => (bool) ($v['is_default'] ?? false),
            'is_sold_out' => (bool) ($v['is_sold_out'] ?? false),
            'is_active' => (bool) ($v['is_active'] ?? true),
        ]);

        $this->audit->log('pos.menu_variant_created', $variant, [
            'menu_item_sku' => $menuItem->sku,
            'name' => $variant->name,
            'price_minor' => $variant->price_minor,
        ]);

        return response()->json(['message' => 'Menu variant created.', 'data' => $variant], 201);
    }

    public function storeModifier(Request $request, MenuItem $menuItem): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['pos.menu', 'pos.outlets.manage'])) {
            abort(403, 'You do not have permission to manage POS outlets or menus.');
        }

        $v = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'price_minor' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $priceMinor = isset($v['price_minor'])
            ? (int) $v['price_minor']
            : AvailabilityService::toMinor($v['price'] ?? 0);

        $mod = MenuItemModifier::create([
            'menu_item_id' => $menuItem->id,
            'name' => trim(strip_tags($v['name'])),
            'price_minor' => $priceMinor,
            'price' => AvailabilityService::toMajor($priceMinor),
            'is_active' => (bool) ($v['is_active'] ?? true),
        ]);

        $this->audit->log('pos.menu_modifier_created', $mod, [
            'menu_item_sku' => $menuItem->sku,
            'name' => $mod->name,
            'price_minor' => $mod->price_minor,
        ]);

        return response()->json(['message' => 'Menu modifier created.', 'data' => $mod], 201);
    }

    public function inHouseStays(Request $request): JsonResponse
    {
        Gate::authorize('pos.view');

        $stays = Reservation::with(['assignedRoom.roomType', 'guestFolio'])
            ->where('status', Reservation::STATUS_CHECKED_IN)
            ->whereNotNull('assigned_room_id')
            ->orderBy('check_in_date')
            ->get()
            ->map(function (Reservation $r) {
                return [
                    'reservation_id' => $r->id,
                    'reference' => $r->reference,
                    'guest_name' => $r->guest_name,
                    'room_id' => $r->assigned_room_id,
                    'room_number' => $r->assignedRoom?->room_number,
                    'room_type' => $r->assignedRoom?->roomType?->name,
                    'check_in_date' => $r->check_in_date?->format('Y-m-d'),
                    'check_out_date' => $r->check_out_date?->format('Y-m-d'),
                    'guest_folio_id' => $r->guestFolio?->id,
                    'folio_reference' => $r->guestFolio?->folio_reference,
                    'folio_status' => $r->guestFolio?->status ?? 'open',
                    'folio_balance_due_amount' => $r->guestFolio?->balance_due_amount ?? '0.00',
                ];
            });

        return response()->json(['data' => $stays]);
    }

    public function orders(Request $request): JsonResponse
    {
        Gate::authorize('pos.view');

        $orders = PosOrder::with(['outlet', 'items', 'reservation.assignedRoom', 'guestFolio', 'room', 'payments', 'creator'])
            ->when($request->filled('pos_outlet_id'), fn ($q) => $q->where('pos_outlet_id', (int) $request->query('pos_outlet_id')))
            ->when($request->filled('order_type'), fn ($q) => $q->where('order_type', (string) $request->query('order_type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->query('status')))
            ->when($request->filled('payment_status'), fn ($q) => $q->where('payment_status', (string) $request->query('payment_status')))
            ->orderByDesc('id')
            ->limit((int) $request->query('limit', 100))
            ->get();

        return response()->json(['data' => $orders]);
    }

    public function showOrder(Request $request, PosOrder $posOrder): JsonResponse
    {
        Gate::authorize('pos.view');

        return response()->json([
            'data' => $posOrder->load(['outlet', 'items', 'reservation.assignedRoom', 'guestFolio', 'folioEntry', 'room', 'payments.recorder', 'histories.actor']),
        ]);
    }

    public function storeOrder(Request $request): JsonResponse
    {
        Gate::authorize('pos.sell');

        $v = $request->validate([
            'idempotency_key' => ['nullable', 'string', 'max:96'],
            'pos_outlet_id' => ['required', 'integer', 'exists:pos_outlets,id'],
            'order_type' => ['sometimes', 'string', Rule::in(['dine_in', 'bar', 'takeaway', 'room_service'])],
            'table_or_location' => ['nullable', 'string', 'max:64'],
            'covers' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'reservation_id' => ['nullable', 'integer', 'exists:reservations,id'],
            'guest_folio_id' => ['nullable', 'integer'],
            'room_id' => ['nullable', 'integer'],
            'guest_name' => ['nullable', 'string', 'max:160'],
            'delivery_instructions' => ['nullable', 'string', 'max:1000'],
            'special_instructions' => ['nullable', 'string', 'max:1000'],
            'discount_type' => ['nullable', 'string', Rule::in(['percentage', 'fixed'])],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'discount_reason' => ['nullable', 'string', 'max:500'],
            'submit' => ['sometimes', 'boolean'],
            'charge_to_room' => ['sometimes', 'boolean'],
            'allow_negative_stock_override' => ['sometimes', 'boolean'],
            'negative_stock_reason' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', 'integer', 'exists:menu_items,id'],
            'items.*.menu_item_variant_id' => ['nullable', 'integer', 'exists:menu_item_variants,id'],
            'items.*.modifier_ids' => ['nullable', 'array'],
            'items.*.modifier_ids.*' => ['integer', 'exists:menu_item_modifiers,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:500'],
            'items.*.special_instructions' => ['nullable', 'string', 'max:500'],
        ]);

        $order = $this->pos->createOrder($v, $request->user());

        return response()->json([
            'message' => 'POS order created.',
            'data' => $order,
        ], 201);
    }

    public function updateDraftOrder(Request $request, PosOrder $posOrder): JsonResponse
    {
        Gate::authorize('pos.sell');

        $v = $request->validate([
            'table_or_location' => ['nullable', 'string', 'max:64'],
            'covers' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'special_instructions' => ['nullable', 'string', 'max:1000'],
            'delivery_instructions' => ['nullable', 'string', 'max:1000'],
            'discount_type' => ['nullable', 'string', Rule::in(['percentage', 'fixed'])],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'discount_reason' => ['nullable', 'string', 'max:500'],
            'items' => ['nullable', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required_with:items', 'integer', 'exists:menu_items,id'],
            'items.*.menu_item_variant_id' => ['nullable', 'integer', 'exists:menu_item_variants,id'],
            'items.*.modifier_ids' => ['nullable', 'array'],
            'items.*.modifier_ids.*' => ['integer', 'exists:menu_item_modifiers,id'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1', 'max:500'],
            'items.*.special_instructions' => ['nullable', 'string', 'max:500'],
        ]);

        $updated = $this->pos->updateDraftOrder($posOrder, $v, $request->user());

        return response()->json([
            'message' => 'Draft POS order updated.',
            'data' => $updated,
        ]);
    }

    public function transitionOrderStatus(Request $request, PosOrder $posOrder): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['pos.sell', 'pos.prepare', 'pos.room_service.deliver'])) {
            abort(403, 'You do not have permission to update POS order status.');
        }

        $v = $request->validate([
            'status' => ['required', 'string', Rule::in([
                PosOrder::STATUS_SUBMITTED,
                PosOrder::STATUS_PREPARING,
                PosOrder::STATUS_READY,
                PosOrder::STATUS_OUT_FOR_DELIVERY,
                PosOrder::STATUS_SERVED,
                PosOrder::STATUS_DELIVERED,
            ])],
            'notes' => ['nullable', 'string', 'max:500'],
            'allow_negative_stock_override' => ['sometimes', 'boolean'],
            'negative_stock_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $updated = $this->pos->transitionStatus(
            $posOrder,
            $v['status'],
            $request->user(),
            $v['notes'] ?? null,
            $v
        );

        return response()->json([
            'message' => "Order status updated to {$updated->status}.",
            'data' => $updated,
        ]);
    }

    public function applyDiscount(Request $request, PosOrder $posOrder): JsonResponse
    {
        Gate::authorize('pos.discount');

        $v = $request->validate([
            'discount_type' => ['required', 'string', Rule::in(['percentage', 'fixed'])],
            'discount_value' => ['required', 'numeric', 'min:0'],
            'discount_reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $updated = $this->pos->applyDiscount($posOrder, $v, $request->user());

        return response()->json([
            'message' => 'Order discount applied.',
            'data' => $updated,
        ]);
    }

    public function voidOrder(Request $request, PosOrder $posOrder): JsonResponse
    {
        if ($posOrder->status !== PosOrder::STATUS_DRAFT && ! $request->user()->hasPermission('pos.void')) {
            abort(403, 'You do not have permission to void submitted POS orders.');
        }

        $v = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'stock_action' => ['sometimes', 'string', Rule::in(['restock', 'waste'])],
            'reverse_payments' => ['sometimes', 'boolean'],
        ]);

        $updated = $this->pos->voidOrCancelOrder($posOrder, $v, $request->user());

        return response()->json([
            'message' => "Order {$updated->status}.",
            'data' => $updated,
        ]);
    }

    public function recordPayment(Request $request, PosOrder $posOrder): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['pos.pay', 'pos.sell'])) {
            abort(403, 'You do not have permission to record POS payments.');
        }

        $v = $request->validate([
            'idempotency_key' => ['nullable', 'string', 'max:96'],
            'method' => ['required', 'string', Rule::in(['cash', 'pos_card', 'bank_transfer'])],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'amount_minor' => ['nullable', 'integer', 'min:1'],
            'receipt_number' => ['nullable', 'string', 'max:64'],
            'external_reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $payment = $this->pos->recordPayment($posOrder, $v, $request->user());

        return response()->json([
            'message' => 'POS payment recorded.',
            'data' => $payment,
        ], 201);
    }

    public function refundPayment(Request $request, PosPayment $posPayment): JsonResponse
    {
        Gate::authorize('pos.refund');

        $v = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $refund = $this->pos->refundOrReversePayment($posPayment, $v['reason'], $request->user());

        return response()->json([
            'message' => 'POS payment refunded/reversed.',
            'data' => $refund,
        ], 201);
    }

    public function postRoomCharge(Request $request, PosOrder $posOrder): JsonResponse
    {
        Gate::authorize('pos.room_charge.post');

        $v = $request->validate([
            'reservation_id' => ['nullable', 'integer', 'exists:reservations,id'],
            'guest_folio_id' => ['nullable', 'integer'],
            'room_id' => ['nullable', 'integer'],
        ]);

        $updated = $this->pos->postOrderChargeToRoomFolio($posOrder, $v, $request->user());

        return response()->json([
            'message' => 'Order charged to guest room folio.',
            'data' => $updated,
        ]);
    }

    public function reportsSummary(Request $request): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['pos.reports.view', 'pos.view'])) {
            abort(403, 'You do not have permission to view POS reports.');
        }

        $dateFrom = $request->query('date_from', now()->startOfMonth()->format('Y-m-d'));
        $dateTo = $request->query('date_to', now()->format('Y-m-d'));

        $activeOrders = PosOrder::with('outlet')
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo)
            ->whereNotIn('status', [PosOrder::STATUS_DRAFT, PosOrder::STATUS_CANCELLED, PosOrder::STATUS_VOIDED])
            ->get();

        $voidedOrders = PosOrder::whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo)
            ->whereIn('status', [PosOrder::STATUS_CANCELLED, PosOrder::STATUS_VOIDED])
            ->get();

        $salesByOutlet = $activeOrders->groupBy('pos_outlet_id')->map(function ($group) {
            /** @var PosOutlet|null $outlet */
            $outlet = $group->first()?->outlet;
            $grossMinor = (int) $group->sum('total_minor');
            $discountsMinor = (int) $group->sum('discount_minor');
            $roomChargedMinor = (int) $group->where('payment_status', PosOrder::PAYMENT_CHARGED_TO_ROOM)->sum('total_minor');
            $directPaidMinor = (int) $group->sum('amount_paid_minor') - $roomChargedMinor;

            return [
                'outlet_id' => $outlet?->id,
                'outlet_code' => $outlet?->code ?? 'N/A',
                'outlet_name' => $outlet?->name ?? 'Outlet',
                'orders_count' => $group->count(),
                'gross_sales_minor' => $grossMinor,
                'gross_sales_amount' => AvailabilityService::toMajor($grossMinor),
                'discounts_minor' => $discountsMinor,
                'discounts_amount' => AvailabilityService::toMajor($discountsMinor),
                'room_charged_minor' => $roomChargedMinor,
                'room_charged_amount' => AvailabilityService::toMajor($roomChargedMinor),
                'direct_paid_minor' => max(0, $directPaidMinor),
                'direct_paid_amount' => AvailabilityService::toMajor(max(0, $directPaidMinor)),
            ];
        })->values();

        $activeOrderIds = $activeOrders->pluck('id')->all();
        $topItems = [];
        if (! empty($activeOrderIds)) {
            $topItems = PosOrderItem::whereIn('pos_order_id', $activeOrderIds)
                ->get()
                ->groupBy('item_name_snapshot')
                ->map(function ($lines, $name) {
                    $qty = (int) $lines->sum('quantity');
                    $revenueMinor = (int) $lines->sum('line_subtotal_minor');

                    return [
                        'item_name' => $name,
                        'quantity_sold' => $qty,
                        'revenue_minor' => $revenueMinor,
                        'revenue_amount' => AvailabilityService::toMajor($revenueMinor),
                    ];
                })
                ->sortByDesc('revenue_minor')
                ->take(15)
                ->values();
        }

        $payments = PosPayment::whereDate('paid_at', '>=', $dateFrom)
            ->whereDate('paid_at', '<=', $dateTo)
            ->where('status', 'completed')
            ->get();

        $paymentReconciliation = $payments->groupBy('method')->map(function ($group, $method) {
            $collectedMinor = (int) $group->where('type', 'payment')->sum('amount_minor');
            $refundedMinor = abs((int) $group->whereIn('type', ['refund', 'reversal'])->sum('amount_minor'));
            $netMinor = $collectedMinor - $refundedMinor;

            return [
                'method' => $method,
                'collected_minor' => $collectedMinor,
                'collected_amount' => AvailabilityService::toMajor($collectedMinor),
                'refunded_minor' => $refundedMinor,
                'refunded_amount' => AvailabilityService::toMajor($refundedMinor),
                'net_minor' => $netMinor,
                'net_amount' => AvailabilityService::toMajor($netMinor),
            ];
        })->values();

        $totalSalesMinor = (int) $activeOrders->sum('total_minor');
        $totalDiscountsMinor = (int) $activeOrders->sum('discount_minor');
        $totalVoidedMinor = (int) $voidedOrders->sum('total_minor');
        $roomServiceOrders = $activeOrders->where('order_type', 'room_service');
        $roomServiceMinor = (int) $roomServiceOrders->sum('total_minor');

        return response()->json([
            'data' => [
                'period' => ['date_from' => $dateFrom, 'date_to' => $dateTo],
                'totals' => [
                    'active_orders_count' => $activeOrders->count(),
                    'total_sales_minor' => $totalSalesMinor,
                    'total_sales_amount' => AvailabilityService::toMajor($totalSalesMinor),
                    'total_discounts_minor' => $totalDiscountsMinor,
                    'total_discounts_amount' => AvailabilityService::toMajor($totalDiscountsMinor),
                    'voided_orders_count' => $voidedOrders->count(),
                    'total_voided_minor' => $totalVoidedMinor,
                    'total_voided_amount' => AvailabilityService::toMajor($totalVoidedMinor),
                    'room_service_orders_count' => $roomServiceOrders->count(),
                    'room_service_sales_minor' => $roomServiceMinor,
                    'room_service_sales_amount' => AvailabilityService::toMajor($roomServiceMinor),
                ],
                'sales_by_outlet' => $salesByOutlet,
                'top_items' => $topItems,
                'payment_reconciliation' => $paymentReconciliation,
            ],
        ]);
    }
}
