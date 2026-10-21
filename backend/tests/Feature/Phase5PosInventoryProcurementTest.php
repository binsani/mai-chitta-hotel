<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FolioEntry;
use App\Models\GoodsReceiving;
use App\Models\GuestFolio;
use App\Models\InventoryBatch;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryStockBalance;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuItemModifier;
use App\Models\MenuItemRecipe;
use App\Models\MenuItemVariant;
use App\Models\PaymentTransaction;
use App\Models\PosOrder;
use App\Models\PosOutlet;
use App\Models\PosPayment;
use App\Models\PosPrepStation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\RatePlan;
use App\Models\RatePlanRoomType;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\StorageLocation;
use App\Models\Supplier;
use App\Models\User;
use App\Services\FolioService;
use App\Services\ReservationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase5PosInventoryProcurementTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private User $posCashier;
    private User $posSupervisor;
    private User $storekeeper;
    private User $procurementOfficer;
    private User $frontDesk;
    private User $accountant;

    private StorageLocation $mainStore;
    private StorageLocation $kitchenStore;
    private PosOutlet $restaurantOutlet;
    private PosOutlet $roomServiceOutlet;
    private PosPrepStation $hotKitchen;
    private MenuCategory $mainsCat;
    private InventoryCategory $foodCat;

    private RoomType $deluxeType;
    private Room $room101;
    private Room $room102;
    private RatePlan $standardPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->manager = $this->createUserWithRole('hotel-manager', 'manager@maichitta.test');
        $this->posCashier = $this->createUserWithRole('pos-staff', 'cashier@maichitta.test');
        $this->posSupervisor = $this->createUserWithRole('pos-supervisor', 'pos-supervisor@maichitta.test');
        $this->storekeeper = $this->createUserWithRole('inventory-officer', 'storekeeper@maichitta.test');
        $this->procurementOfficer = $this->createUserWithRole('procurement-officer', 'procurement@maichitta.test');
        $this->frontDesk = $this->createUserWithRole('receptionist', 'frontdesk@maichitta.test');
        $this->accountant = $this->createUserWithRole('accountant', 'accountant@maichitta.test');

        $this->mainStore = StorageLocation::create([
            'code' => 'MAIN-STORE',
            'name' => 'Central Store',
            'location_type' => 'main_store',
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->kitchenStore = StorageLocation::create([
            'code' => 'KITCHEN-STORE',
            'name' => 'Kitchen Store',
            'location_type' => 'kitchen',
            'is_default' => false,
            'is_active' => true,
        ]);

        $this->foodCat = InventoryCategory::create([
            'code' => 'FOOD-DRY',
            'name' => 'Dry Food & Grains',
            'is_active' => true,
        ]);

        $this->restaurantOutlet = PosOutlet::create([
            'code' => 'ZARIA-REST',
            'name' => 'Zaria Courtyard Restaurant',
            'type' => 'restaurant',
            'default_storage_location_id' => $this->kitchenStore->id,
            'tax_percentage' => 7.50,
            'service_charge_percentage' => 5.00,
            'supports_room_service' => true,
            'supports_room_charge' => true,
            'accepts_orders' => true,
            'is_active' => true,
        ]);

        $this->roomServiceOutlet = PosOutlet::create([
            'code' => 'ROOM-SVC',
            'name' => 'In-Room Dining',
            'type' => 'room_service',
            'default_storage_location_id' => $this->kitchenStore->id,
            'tax_percentage' => 7.50,
            'service_charge_percentage' => 10.00,
            'supports_room_service' => true,
            'supports_room_charge' => true,
            'accepts_orders' => true,
            'is_active' => true,
        ]);

        $this->hotKitchen = PosPrepStation::create([
            'pos_outlet_id' => $this->restaurantOutlet->id,
            'code' => 'HOT-KITCHEN',
            'name' => 'Hot Kitchen',
            'is_active' => true,
        ]);

        $this->mainsCat = MenuCategory::create([
            'pos_outlet_id' => null,
            'code' => 'MAIN-COURSES',
            'name' => 'Main Courses',
            'is_active' => true,
        ]);

        $this->deluxeType = RoomType::create([
            'code' => 'DLX',
            'name' => 'Deluxe King',
            'slug' => 'deluxe-king',
            'base_occupancy' => 2,
            'max_occupancy' => 3,
            'max_adults' => 2,
            'max_children' => 1,
            'default_rate_minor' => 4500000,
            'default_rate' => 45000.00,
            'currency_code' => 'NGN',
            'is_public' => true,
            'is_active' => true,
        ]);

        $this->room101 = Room::create([
            'room_type_id' => $this->deluxeType->id,
            'room_number' => '101',
            'floor' => '1',
            'operational_status' => 'active',
            'housekeeping_status' => 'clean',
            'is_active' => true,
        ]);

        $this->room102 = Room::create([
            'room_type_id' => $this->deluxeType->id,
            'room_number' => '102',
            'floor' => '1',
            'operational_status' => 'active',
            'housekeeping_status' => 'clean',
            'is_active' => true,
        ]);

        $this->standardPlan = RatePlan::create([
            'code' => 'STD-BAR',
            'name' => 'Best Available Rate',
            'pricing_Rule_type' => 'flat',
            'meal_plan' => 'room_only',
            'is_refundable' => true,
            'is_public' => true,
            'is_active' => true,
        ]);

        RatePlanRoomType::create([
            'rate_plan_id' => $this->standardPlan->id,
            'room_type_id' => $this->deluxeType->id,
            'base_rate_minor' => 4500000,
            'base_rate' => 45000.00,
            'extra_adult_rate_minor' => 500000,
            'extra_child_rate_minor' => 250000,
        ]);
    }

    private function createUserWithRole(string $roleSlug, string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'is_active' => true]);
        $role = Role::where('slug', $roleSlug)->firstOrFail();
        $user->roles()->sync([$role->id => ['created_at' => now()]]);

        return $user;
    }

    private function createCheckedInStay(Room $room, string $guestName = 'Alhaji Musa Ibrahim'): Reservation
    {
        $resService = app(ReservationService::class);
        $res = $resService->createReservation([
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'check_in_date' => now()->format('Y-m-d'),
            'check_out_date' => now()->addDays(2)->format('Y-m-d'),
            'adults' => 2,
            'children' => 0,
            'rooms_count' => 1,
            'source' => 'walk_in',
            'status' => Reservation::STATUS_CONFIRMED,
            'guest_name' => $guestName,
            'guest_email' => strtolower(str_replace(' ', '.', $guestName)).'@example.test',
            'guest_phone' => '+2348030001122',
        ], $this->frontDesk, false);

        return $resService->checkIn($res, $room->id, $this->frontDesk);
    }

    public function test_pos_order_snapshots_menu_variant_and_modifier_prices_and_calculates_exact_minor_unit_totals(): void
    {
        $jollof = MenuItem::create([
            'sku' => 'MENU-JOLLOF',
            'pos_outlet_id' => $this->restaurantOutlet->id,
            'menu_category_id' => $this->mainsCat->id,
            'pos_prep_station_id' => $this->hotKitchen->id,
            'name' => 'Zaria Special Jollof Rice',
            'currency_code' => 'NGN',
            'price_minor' => 450000, // 4,500 NGN
            'price' => 4500.00,
            'stock_tracking_policy' => 'none',
            'is_active' => true,
            'is_available' => true,
        ]);

        $largeVariant = MenuItemVariant::create([
            'menu_item_id' => $jollof->id,
            'code' => 'LARGE',
            'name' => 'Large Platter',
            'price_minor' => 600000, // 6,000 NGN
            'price' => 6000.00,
            'recipe_multiplier' => 1.5,
            'is_active' => true,
        ]);

        $extraPlantain = MenuItemModifier::create([
            'menu_item_id' => $jollof->id,
            'name' => 'Extra Fried Plantain',
            'price_minor' => 80000, // 800 NGN
            'price' => 800.00,
            'is_active' => true,
        ]);

        // Cashier creates order with 2x Large Platter + Extra Plantain -> unit = 6,800 NGN -> subtotal = 13,600 NGN (1,360,000 minor)
        // Tax 7.5% = 102,000 minor; Service charge 5% = 68,000 minor; Total = 1,530,000 minor (15,300.00 NGN)
        $resp = $this->actingAs($this->posCashier)->postJson('/api/v1/admin/pos/orders', [
            'pos_outlet_id' => $this->restaurantOutlet->id,
            'order_type' => 'dine_in',
            'table_or_location' => 'Table 4',
            'covers' => 2,
            'submit' => true,
            'items' => [
                [
                    'menu_item_id' => $jollof->id,
                    'menu_item_variant_id' => $largeVariant->id,
                    'modifier_ids' => [$extraPlantain->id],
                    'quantity' => 2,
                    'special_instructions' => 'Less spicy',
                ],
            ],
        ]);

        $resp->assertCreated();
        $orderId = $resp->json('data.id');
        $this->assertSame(1360000, $resp->json('data.subtotal_minor'));
        $this->assertSame(102000, $resp->json('data.tax_minor'));
        $this->assertSame(68000, $resp->json('data.service_charge_minor'));
        $this->assertSame(1530000, $resp->json('data.total_minor'));
        $this->assertSame('submitted', $resp->json('data.status'));

        // Later menu price change must NOT alter the existing order's snapshotted prices or totals
        $this->actingAs($this->manager)->putJson("/api/v1/admin/pos/menu-items/{$jollof->id}", [
            'price_minor' => 950000,
        ])->assertOk();
        $largeVariant->update(['price_minor' => 1200000, 'price' => 12000.00]);

        $fetched = $this->actingAs($this->posCashier)->getJson("/api/v1/admin/pos/orders/{$orderId}")->assertOk();
        $this->assertSame(1360000, $fetched->json('data.subtotal_minor'));
        $this->assertSame(1530000, $fetched->json('data.total_minor'));
        $this->assertSame(600000, $fetched->json('data.items.0.unit_price_minor'));
        $this->assertSame(80000, $fetched->json('data.items.0.modifiers_unit_minor'));
    }

    public function test_pos_state_machine_enforces_valid_transitions_and_blocks_editing_submitted_orders(): void
    {
        $tea = MenuItem::create([
            'sku' => 'MENU-TEA',
            'pos_outlet_id' => $this->restaurantOutlet->id,
            'menu_category_id' => $this->mainsCat->id,
            'name' => 'Zaria Spiced Tea',
            'price_minor' => 150000,
            'price' => 1500.00,
            'stock_tracking_policy' => 'none',
            'is_active' => true,
            'is_available' => true,
        ]);

        $orderResp = $this->actingAs($this->posCashier)->postJson('/api/v1/admin/pos/orders', [
            'pos_outlet_id' => $this->restaurantOutlet->id,
            'order_type' => 'dine_in',
            'submit' => true,
            'items' => [['menu_item_id' => $tea->id, 'quantity' => 1]],
        ])->assertCreated();

        $orderId = $orderResp->json('data.id');

        // Submitted order cannot be silently edited via PUT
        $this->actingAs($this->posCashier)->putJson("/api/v1/admin/pos/orders/{$orderId}", [
            'items' => [['menu_item_id' => $tea->id, 'quantity' => 5]],
        ])->assertStatus(422);

        // Transition submitted -> preparing -> ready -> served
        $this->actingAs($this->posCashier)->postJson("/api/v1/admin/pos/orders/{$orderId}/status", [
            'status' => 'preparing',
        ])->assertOk();

        $this->actingAs($this->posCashier)->postJson("/api/v1/admin/pos/orders/{$orderId}/status", [
            'status' => 'ready',
        ])->assertOk();

        $this->actingAs($this->posCashier)->postJson("/api/v1/admin/pos/orders/{$orderId}/status", [
            'status' => 'served',
        ])->assertOk();

        // Served order cannot transition backward to preparing
        $this->actingAs($this->posCashier)->postJson("/api/v1/admin/pos/orders/{$orderId}/status", [
            'status' => 'preparing',
        ])->assertStatus(422);
    }

    public function test_unauthorized_discounts_voids_and_refunds_are_rejected_while_supervisor_succeeds(): void
    {
        $suya = MenuItem::create([
            'sku' => 'MENU-SUYA',
            'pos_outlet_id' => $this->restaurantOutlet->id,
            'menu_category_id' => $this->mainsCat->id,
            'name' => 'Beef Suya Platter',
            'price_minor' => 500000, // 5,000 NGN
            'price' => 5000.00,
            'stock_tracking_policy' => 'none',
            'is_active' => true,
            'is_available' => true,
        ]);

        $orderResp = $this->actingAs($this->posCashier)->postJson('/api/v1/admin/pos/orders', [
            'pos_outlet_id' => $this->restaurantOutlet->id,
            'order_type' => 'dine_in',
            'submit' => true,
            'items' => [['menu_item_id' => $suya->id, 'quantity' => 2]], // 1,000,000 subtotal
        ])->assertCreated();

        $orderId = $orderResp->json('data.id');

        // Cashier does NOT hold `pos.discount` or `pos.void` or `pos.refund`
        $this->actingAs($this->posCashier)->postJson("/api/v1/admin/pos/orders/{$orderId}/discount", [
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'discount_reason' => 'VIP guest discount',
        ])->assertForbidden();

        $this->actingAs($this->posCashier)->postJson("/api/v1/admin/pos/orders/{$orderId}/void", [
            'reason' => 'Guest walked out',
        ])->assertForbidden();

        // POS Supervisor holds `pos.discount` -> applies 10% discount
        // Subtotal 1,000,000 - 10% (100,000) = 900,000 net. Tax 7.5% = 67,500; Service 5% = 45,000; Total = 1,012,500
        $discResp = $this->actingAs($this->posSupervisor)->postJson("/api/v1/admin/pos/orders/{$orderId}/discount", [
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'discount_reason' => 'Manager approved courtesy discount',
        ])->assertOk();

        $this->assertSame(100000, $discResp->json('data.discount_minor'));
        $this->assertSame(1012500, $discResp->json('data.total_minor'));

        // Cashier records payment
        $payResp = $this->actingAs($this->posCashier)->postJson("/api/v1/admin/pos/orders/{$orderId}/payments", [
            'method' => 'pos_card',
            'amount_minor' => 1012500,
            'idempotency_key' => 'pay-order-1',
        ])->assertCreated();

        $paymentId = $payResp->json('data.id');

        // Cashier cannot refund POS payment
        $this->actingAs($this->posCashier)->postJson("/api/v1/admin/pos/payments/{$paymentId}/refund", [
            'reason' => 'Duplicate card swipe',
        ])->assertForbidden();

        // Supervisor cannot void a paid order without refunding/reversing payment first
        $this->actingAs($this->posSupervisor)->postJson("/api/v1/admin/pos/orders/{$orderId}/void", [
            'reason' => 'Order cancelled after payment',
        ])->assertStatus(422);

        // Supervisor refunds payment -> order becomes refundable/voidable
        $this->actingAs($this->posSupervisor)->postJson("/api/v1/admin/pos/payments/{$paymentId}/refund", [
            'reason' => 'Approved customer refund',
        ])->assertCreated();

        $this->actingAs($this->posSupervisor)->postJson("/api/v1/admin/pos/orders/{$orderId}/void", [
            'reason' => 'Order voided after refund',
        ])->assertOk();
    }

    public function test_room_service_enforces_checked_in_stay_prevents_cross_folio_idor_posts_idempotently_and_reverses_on_void(): void
    {
        $stay101 = $this->createCheckedInStay($this->room101, 'Hajiya Amina Bello');
        $stay102 = $this->createCheckedInStay($this->room102, 'Dr Kabir Usman');

        $folioService = app(FolioService::class);
        $folio101 = $folioService->ensureFolioForReservation($stay101, $this->frontDesk);
        $folio102 = $folioService->ensureFolioForReservation($stay102, $this->frontDesk);

        // Also create a `confirmed` (NOT checked-in) reservation
        $unCheckedRes = app(ReservationService::class)->createReservation([
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'check_in_date' => now()->addDays(5)->format('Y-m-d'),
            'check_out_date' => now()->addDays(7)->format('Y-m-d'),
            'adults' => 1,
            'children' => 0,
            'rooms_count' => 1,
            'source' => 'phone',
            'status' => Reservation::STATUS_CONFIRMED,
            'guest_name' => 'Future Guest',
            'guest_email' => 'future@example.test',
            'guest_phone' => '+2348039998877',
        ], $this->frontDesk, false);

        $soup = MenuItem::create([
            'sku' => 'MENU-PEPPERSOUP',
            'pos_outlet_id' => $this->roomServiceOutlet->id,
            'menu_category_id' => $this->mainsCat->id,
            'name' => 'Catfish Pepper Soup',
            'price_minor' => 600000, // 6,000 NGN
            'price' => 6000.00,
            'is_room_service_eligible' => true,
            'stock_tracking_policy' => 'none',
            'is_active' => true,
            'is_available' => true,
        ]);

        // 1. Reject room-service order for a reservation that is NOT checked_in
        $this->actingAs($this->posCashier)->postJson('/api/v1/admin/pos/orders', [
            'pos_outlet_id' => $this->roomServiceOutlet->id,
            'order_type' => 'room_service',
            'reservation_id' => $unCheckedRes->id,
            'items' => [['menu_item_id' => $soup->id, 'quantity' => 1]],
        ])->assertStatus(422);

        // 2. Reject cross-room mismatch (stay101 is in room 101, client submits room 102)
        $this->actingAs($this->posCashier)->postJson('/api/v1/admin/pos/orders', [
            'pos_outlet_id' => $this->roomServiceOutlet->id,
            'order_type' => 'room_service',
            'reservation_id' => $stay101->id,
            'room_id' => $this->room102->id,
            'items' => [['menu_item_id' => $soup->id, 'quantity' => 1]],
        ])->assertStatus(422);

        // 3. Reject cross-folio IDOR mismatch (stay101 with folio102)
        $this->actingAs($this->posCashier)->postJson('/api/v1/admin/pos/orders', [
            'pos_outlet_id' => $this->roomServiceOutlet->id,
            'order_type' => 'room_service',
            'reservation_id' => $stay101->id,
            'guest_folio_id' => $folio102->id,
            'items' => [['menu_item_id' => $soup->id, 'quantity' => 1]],
        ])->assertStatus(422);

        // 4. Valid room-service order with immediate room charge to stay101
        // Price: 600,000 + Tax 7.5% (45,000) + Service 10% (60,000) = 705,000 minor
        $validResp = $this->actingAs($this->posCashier)->postJson('/api/v1/admin/pos/orders', [
            'pos_outlet_id' => $this->roomServiceOutlet->id,
            'order_type' => 'room_service',
            'reservation_id' => $stay101->id,
            'charge_to_room' => true,
            'items' => [['menu_item_id' => $soup->id, 'quantity' => 1]],
        ])->assertCreated();

        $orderId = $validResp->json('data.id');
        $folioEntryId = $validResp->json('data.folio_entry_id');
        $this->assertNotNull($folioEntryId);
        $this->assertSame('charged_to_room', $validResp->json('data.payment_status'));
        $this->assertSame(705000, $validResp->json('data.total_minor'));

        // Verify folio101 received exactly one pos_charge entry for 705,000 minor
        $posEntries = FolioEntry::where('guest_folio_id', $folio101->id)
            ->where('source_type', 'pos_order')
            ->where('source_id', (string) $orderId)
            ->get();
        $this->assertCount(1, $posEntries);
        $this->assertSame(705000, $posEntries->first()->total_amount_minor);

        // 5. Duplicate room-charge call is idempotent (does not create a second folio charge)
        $this->actingAs($this->posCashier)->postJson("/api/v1/admin/pos/orders/{$orderId}/room-charge", [])
            ->assertOk();
        $this->assertSame(1, FolioEntry::where('guest_folio_id', $folio101->id)->where('source_type', 'pos_order')->count());

        // 6. Voiding the room-charged order automatically reverses the GuestFolio entry via FolioService::reversePostedEntry
        $folioBalanceBeforeVoid = $folio101->fresh()->balance_due_minor;
        $this->actingAs($this->posSupervisor)->postJson("/api/v1/admin/pos/orders/{$orderId}/void", [
            'reason' => 'Guest cancelled room-service order',
        ])->assertOk();

        $reversalEntry = FolioEntry::where('reversed_entry_id', $folioEntryId)->first();
        $this->assertNotNull($reversalEntry);
        $this->assertSame(-705000, $reversalEntry->total_amount_minor);
        $this->assertSame($folioBalanceBeforeVoid - 705000, $folio101->fresh()->balance_due_minor);
    }

    public function test_closed_folio_blocks_room_charge_posting_until_reopened(): void
    {
        $stay = $this->createCheckedInStay($this->room101, 'Closed Folio Guest');
        $folioService = app(FolioService::class);
        $folio = $folioService->ensureFolioForReservation($stay, $this->frontDesk);
        $folio = $folioService->syncAccommodationCharges($folio, $this->frontDesk);

        // Pay and settle folio
        $tx = PaymentTransaction::create([
            'transaction_reference' => 'TX-SETTLE-101',
            'reservation_id' => $stay->id,
            'guest_id' => $stay->guest_id,
            'type' => 'payment',
            'method' => 'cash',
            'currency_code' => 'NGN',
            'amount_minor' => $folio->balance_due_minor,
            'amount' => $folio->balance_due_amount,
            'status' => 'completed',
            'verification_status' => 'verified',
            'recorded_by' => $this->frontDesk->id,
            'paid_at' => now(),
        ]);
        $folioService->allocatePaymentToFolio($folio, $tx, $tx->amount_minor, $this->frontDesk);
        $folioService->settleAndCloseFolio($folio, ['notes' => 'Settled early'], $this->frontDesk);

        $water = MenuItem::create([
            'sku' => 'MENU-WATER',
            'pos_outlet_id' => $this->restaurantOutlet->id,
            'menu_category_id' => $this->mainsCat->id,
            'name' => 'Bottled Water',
            'price_minor' => 50000,
            'price' => 500.00,
            'stock_tracking_policy' => 'none',
            'is_active' => true,
            'is_available' => true,
        ]);

        // Attempt to charge to closed folio -> must fail with 422
        $this->actingAs($this->posCashier)->postJson('/api/v1/admin/pos/orders', [
            'pos_outlet_id' => $this->restaurantOutlet->id,
            'order_type' => 'dine_in',
            'reservation_id' => $stay->id,
            'charge_to_room' => true,
            'items' => [['menu_item_id' => $water->id, 'quantity' => 2]],
        ])->assertStatus(422);
    }

    public function test_recipe_bom_consumption_deducts_stock_once_prevents_negative_stock_and_handles_void_restock_vs_waste(): void
    {
        // Create Chicken Breast inventory item in g (purchase unit kg = 1000g)
        $chicken = InventoryItem::create([
            'sku' => 'INV-CHICKEN-G',
            'inventory_category_id' => $this->foodCat->id,
            'name' => 'Boneless Chicken Breast',
            'base_unit' => 'g',
            'purchase_unit' => 'kg',
            'purchase_to_base_factor' => 1000,
            'current_stock' => 0,
            'reorder_level' => 500,
            'currency_code' => 'NGN',
            'costing_method' => 'weighted_average',
            'unit_cost_minor' => 450, // 4.50 NGN per gram (4,500 NGN/kg)
            'unit_cost' => 4.50,
            'allow_negative_stock' => false,
            'is_active' => true,
        ]);

        // Add initial stock of 1,000g to Kitchen Store
        $this->actingAs($this->storekeeper)->postJson('/api/v1/admin/inventory/movements', [
            'inventory_item_id' => $chicken->id,
            'storage_location_id' => $this->kitchenStore->id,
            'movement_type' => 'adjustment',
            'direction' => 'add',
            'quantity' => 1000,
            'unit' => 'g',
            'unit_cost' => 4.50,
            'reason' => 'Initial opening kitchen stock',
        ])->assertCreated();

        $this->assertEquals(1000.0, (float) $chicken->fresh()->current_stock);

        // Create Grilled Chicken menu item with recipe: 250g per portion
        $grilledChicken = MenuItem::create([
            'sku' => 'MENU-GRILL-CHK',
            'pos_outlet_id' => $this->restaurantOutlet->id,
            'menu_category_id' => $this->mainsCat->id,
            'name' => 'Grilled Zaria Chicken',
            'price_minor' => 550000,
            'price' => 5500.00,
            'stock_tracking_policy' => 'recipe',
            'is_active' => true,
            'is_available' => true,
        ]);

        MenuItemRecipe::create([
            'menu_item_id' => $grilledChicken->id,
            'inventory_item_id' => $chicken->id,
            'quantity_required' => 250,
            'unit' => 'g',
            'conversion_to_base' => 1,
            'waste_percentage' => 0,
            'is_active' => true,
        ]);

        // 1. Order 2 portions -> deducts 500g at submission -> 500g remaining
        $order1 = $this->actingAs($this->posCashier)->postJson('/api/v1/admin/pos/orders', [
            'pos_outlet_id' => $this->restaurantOutlet->id,
            'order_type' => 'dine_in',
            'submit' => true,
            'items' => [['menu_item_id' => $grilledChicken->id, 'quantity' => 2]],
        ])->assertCreated();

        $order1Id = $order1->json('data.id');
        $this->assertEquals(500.0, (float) $chicken->fresh()->current_stock);

        // Transitioning order1 through preparing -> ready -> served must NOT deduct stock a second time
        $this->actingAs($this->posCashier)->postJson("/api/v1/admin/pos/orders/{$order1Id}/status", ['status' => 'preparing'])->assertOk();
        $this->actingAs($this->posCashier)->postJson("/api/v1/admin/pos/orders/{$order1Id}/status", ['status' => 'ready'])->assertOk();
        $this->actingAs($this->posCashier)->postJson("/api/v1/admin/pos/orders/{$order1Id}/status", ['status' => 'served'])->assertOk();
        $this->assertEquals(500.0, (float) $chicken->fresh()->current_stock);

        // 2. Attempting to order 3 portions (750g required, only 500g available) fails with 422
        $this->actingAs($this->posCashier)->postJson('/api/v1/admin/pos/orders', [
            'pos_outlet_id' => $this->restaurantOutlet->id,
            'order_type' => 'dine_in',
            'submit' => true,
            'items' => [['menu_item_id' => $grilledChicken->id, 'quantity' => 3]],
        ])->assertStatus(422);
        $this->assertEquals(500.0, (float) $chicken->fresh()->current_stock);

        // 3. Voiding order1 with `stock_action = 'restock'` restores the 500g back to 1,000g via a `restock_return` movement
        $this->actingAs($this->posSupervisor)->postJson("/api/v1/admin/pos/orders/{$order1Id}/void", [
            'reason' => 'Cancelled before cooking started',
            'stock_action' => 'restock',
        ])->assertOk();

        $this->assertEquals(1000.0, (float) $chicken->fresh()->current_stock);
        $this->assertSame(1, StockMovement::where('movement_type', StockMovement::TYPE_RESTOCK_RETURN)->count());
    }

    public function test_procurement_separation_of_duties_partial_grn_weighted_average_cost_and_duplicate_grn_protection(): void
    {
        $rice = InventoryItem::create([
            'sku' => 'INV-RICE-KG',
            'inventory_category_id' => $this->foodCat->id,
            'name' => 'Parboiled Rice',
            'base_unit' => 'kg',
            'purchase_unit' => 'bag50kg',
            'purchase_to_base_factor' => 50,
            'current_stock' => 0,
            'reorder_level' => 50,
            'currency_code' => 'NGN',
            'costing_method' => 'weighted_average',
            'unit_cost_minor' => 100000, // 1,000 NGN/kg
            'unit_cost' => 1000.00,
            'track_batches' => true,
            'is_active' => true,
        ]);

        // Initial stock: 50 kg @ 1,000 NGN/kg (100,000 minor/kg)
        $this->actingAs($this->storekeeper)->postJson('/api/v1/admin/inventory/movements', [
            'inventory_item_id' => $rice->id,
            'storage_location_id' => $this->mainStore->id,
            'movement_type' => 'adjustment',
            'direction' => 'add',
            'quantity' => 50,
            'unit' => 'kg',
            'unit_cost' => 1000.00,
            'reason' => 'Opening stock',
        ])->assertCreated();

        $supplier = Supplier::create([
            'supplier_code' => 'SUP-ZARIA-FARMS',
            'name' => 'Zaria Agro Mills Ltd',
            'status' => 'active',
            'tax_id' => 'TIN-99887766',
            'bank_name' => 'First Bank',
            'bank_account_number' => '2011223344',
            'bank_account_name' => 'Zaria Agro Mills Ltd',
        ]);

        // 1. Storekeeper creates Purchase Requisition
        $reqResp = $this->actingAs($this->storekeeper)->postJson('/api/v1/admin/procurement/requisitions', [
            'storage_location_id' => $this->mainStore->id,
            'priority' => 'high',
            'reason' => 'Restock rice for weekend banquet',
            'items' => [
                ['inventory_item_id' => $rice->id, 'quantity_requested' => 2, 'unit' => 'bag50kg'],
            ],
        ])->assertCreated();
        $reqId = $reqResp->json('data.id');

        // Storekeeper cannot self-approve their own requisition
        $this->actingAs($this->storekeeper)->postJson("/api/v1/admin/procurement/requisitions/{$reqId}/review", [
            'decision' => 'approve',
        ])->assertForbidden();

        // Manager approves requisition
        $this->actingAs($this->manager)->postJson("/api/v1/admin/procurement/requisitions/{$reqId}/review", [
            'decision' => 'approve',
            'review_notes' => 'Approved for purchase',
        ])->assertOk();

        // 2. Procurement Officer creates Purchase Order for 2 bags (100 kg) @ 75,000 NGN/bag (1,500 NGN/kg)
        $poResp = $this->actingAs($this->procurementOfficer)->postJson('/api/v1/admin/procurement/purchase-orders', [
            'supplier_id' => $supplier->id,
            'purchase_requisition_id' => $reqId,
            'storage_location_id' => $this->mainStore->id,
            'items' => [
                [
                    'inventory_item_id' => $rice->id,
                    'unit' => 'bag50kg',
                    'conversion_to_base' => 50,
                    'quantity_ordered' => 2,
                    'unit_cost' => 75000.00, // 7,500,000 minor per bag -> 150,000 minor per kg
                ],
            ],
        ])->assertCreated();

        $poId = $poResp->json('data.id');
        $poItemId = $poResp->json('data.items.0.id');

        // Creating a PO does NOT increase stock
        $this->assertEquals(50.0, (float) $rice->fresh()->current_stock);

        // Receiving against an unapproved PO is rejected
        $this->actingAs($this->storekeeper)->postJson("/api/v1/admin/procurement/purchase-orders/{$poId}/receive", [
            'items' => [['purchase_order_item_id' => $poItemId, 'quantity_received' => 1]],
        ])->assertStatus(422);

        // Manager approves PO (separation of duties: procurementOfficer created it, manager approves it)
        $this->actingAs($this->manager)->postJson("/api/v1/admin/procurement/purchase-orders/{$poId}/review", [
            'decision' => 'approve',
        ])->assertOk();

        //Approving PO still does NOT increase stock
        $this->assertEquals(50.0, (float) $rice->fresh()->current_stock);

        // 3. Partial Goods Receiving: Receive 1 bag (50 kg) @ 75,000 NGN/bag (1,500 NGN/kg = 150,000 minor/kg)
        // Weighted average calculation:
        // Existing: 50 kg @ 100,000 minor/kg = 5,000,000 minor
        // Incoming: 50 kg @ 150,000 minor/kg = 7,500,000 minor
        // New total: 100 kg @ 12,500,000 / 100 = 125,000 minor/kg (1,250.00 NGN/kg)
        $grn1Resp = $this->actingAs($this->storekeeper)->postJson("/api/v1/admin/procurement/purchase-orders/{$poId}/receive", [
            'idempotency_key' => 'grn-rice-batch-1',
            'supplier_delivery_note' => 'DN-001',
            'items' => [
                [
                    'purchase_order_item_id' => $poItemId,
                    'quantity_received' => 1,
                    'batch_number' => 'BATCH-RICE-01',
                    'expiry_date' => now()->addMonths(6)->format('Y-m-d'),
                ],
            ],
        ])->assertCreated();

        $this->assertEquals(100.0, (float) $rice->fresh()->current_stock);
        $this->assertSame(125000, (int) $rice->fresh()->unit_cost_minor);
        $this->assertSame('partially_received', PurchaseOrder::findOrFail($poId)->status);

        // 4. Duplicate GRN submission with same idempotency_key is idempotent (no double stock)
        $this->actingAs($this->storekeeper)->postJson("/api/v1/admin/procurement/purchase-orders/{$poId}/receive", [
            'idempotency_key' => 'grn-rice-batch-1',
            'items' => [
                ['purchase_order_item_id' => $poItemId, 'quantity_received' => 1],
            ],
        ])->assertCreated();
        $this->assertEquals(100.0, (float) $rice->fresh()->current_stock);

        // 5. Over-delivery (trying to receive 2 more bags when only 1 bag remains outstanding) is rejected without override
        $this->actingAs($this->storekeeper)->postJson("/api/v1/admin/procurement/purchase-orders/{$poId}/receive", [
            'items' => [
                ['purchase_order_item_id' => $poItemId, 'quantity_received' => 2],
            ],
        ])->assertStatus(422);

        // 6. Receive remaining 1 bag -> PO transitions to `received` and total stock reaches 150 kg
        $this->actingAs($this->storekeeper)->postJson("/api/v1/admin/procurement/purchase-orders/{$poId}/receive", [
            'idempotency_key' => 'grn-rice-batch-2',
            'items' => [
                [
                    'purchase_order_item_id' => $poItemId,
                    'quantity_received' => 1,
                    'batch_number' => 'BATCH-RICE-02',
                    'expiry_date' => now()->addMonths(8)->format('Y-m-d'),
                ],
            ],
        ])->assertCreated();

        $this->assertEquals(150.0, (float) $rice->fresh()->current_stock);
        $this->assertSame('received', PurchaseOrder::findOrFail($poId)->status);
    }

    public function test_supplier_sensitive_bank_and_tax_fields_and_inventory_costs_are_redacted_for_unauthorized_roles(): void
    {
        $item = InventoryItem::create([
            'sku' => 'INV-OIL-L',
            'inventory_category_id' => $this->foodCat->id,
            'name' => 'Vegetable Oil',
            'base_unit' => 'l',
            'purchase_unit' => 'l',
            'purchase_to_base_factor' => 1,
            'current_stock' => 20,
            'currency_code' => 'NGN',
            'unit_cost_minor' => 280000,
            'unit_cost' => 2800.00,
            'is_active' => true,
        ]);

        $supplier = Supplier::create([
            'supplier_code' => 'SUP-SECRET',
            'name' => 'Kaduna Beverages Supply',
            'status' => 'active',
            'tax_id' => 'TIN-SECRET-12345',
            'bank_name' => 'Zenith Bank',
            'bank_account_number' => '1019998887',
            'bank_account_name' => 'Kaduna Beverages Supply',
        ]);

        // POS Supervisor has `inventory.view`, but NOT `inventory.costs.view`
        $itemsForSupervisor = $this->actingAs($this->posSupervisor)->getJson('/api/v1/admin/inventory/items')->assertOk();
        $this->assertNull($itemsForSupervisor->json('data.0.unit_cost_minor'));
        $this->assertNull($itemsForSupervisor->json('data.0.stock_valuation_minor'));

        // Storekeeper has `procurement.view`, but NOT `procurement.suppliers.sensitive`
        $suppliersForStorekeeper = $this->actingAs($this->storekeeper)->getJson('/api/v1/admin/procurement/suppliers')->assertOk();
        $this->assertArrayNotHasKey('bank_account_number', $suppliersForStorekeeper->json('data.0'));
        $this->assertArrayNotHasKey('tax_id', $suppliersForStorekeeper->json('data.0'));

        // Accountant / Hotel Manager has `inventory.costs.view` and `procurement.suppliers.sensitive`
        $itemsForAccountant = $this->actingAs($this->accountant)->getJson('/api/v1/admin/inventory/items')->assertOk();
        $this->assertSame(280000, $itemsForAccountant->json('data.0.unit_cost_minor'));

        $suppliersForAccountant = $this->actingAs($this->accountant)->getJson('/api/v1/admin/procurement/suppliers')->assertOk();
        $this->assertSame('1019998887', $suppliersForAccountant->json('data.0.bank_account_number'));
        $this->assertSame('TIN-SECRET-12345', $suppliersForAccountant->json('data.0.tax_id'));
    }

    public function test_stock_counts_location_transfers_negative_stock_override_pos_reports_and_audit_trail(): void
    {
        $malt = InventoryItem::create([
            'sku' => 'INV-MALT-CAN',
            'inventory_category_id' => $this->foodCat->id,
            'name' => 'Malt Drink 33cl Can',
            'base_unit' => 'can',
            'purchase_unit' => 'crate24',
            'purchase_to_base_factor' => 24,
            'current_stock' => 0,
            'reorder_level' => 12,
            'currency_code' => 'NGN',
            'unit_cost_minor' => 50000,
            'unit_cost' => 500.00,
            'allow_negative_stock' => false,
            'is_active' => true,
        ]);

        // 1. Stock count reconciliation in Main Store sets stock to 48 cans
        $this->actingAs($this->storekeeper)->postJson('/api/v1/admin/inventory/stock-counts', [
            'inventory_item_id' => $malt->id,
            'storage_location_id' => $this->mainStore->id,
            'counted_quantity' => 48,
            'unit' => 'can',
            'reason' => 'Physical opening count',
        ])->assertCreated();

        $this->assertEquals(48.0, (float) $malt->fresh()->current_stock);

        // 2. Transfer 10 cans from Main Store to Kitchen Store
        $this->actingAs($this->storekeeper)->postJson('/api/v1/admin/inventory/transfers', [
            'inventory_item_id' => $malt->id,
            'from_location_id' => $this->mainStore->id,
            'to_location_id' => $this->kitchenStore->id,
            'quantity' => 10,
            'unit' => 'can',
            'reason' => 'Daily bar & kitchen issue',
        ])->assertCreated();

        // Total hotel stock remains 48 cans (38 in Main Store, 10 in Kitchen Store)
        $this->assertEquals(48.0, (float) $malt->fresh()->current_stock);
        $this->assertEquals(38.0, (float) InventoryStockBalance::where('inventory_item_id', $malt->id)->where('storage_location_id', $this->mainStore->id)->value('quantity'));
        $this->assertEquals(10.0, (float) InventoryStockBalance::where('inventory_item_id', $malt->id)->where('storage_location_id', $this->kitchenStore->id)->value('quantity'));

        // 3. Direct-item POS menu item linked to Malt Can (deducts from Kitchen Store)
        $maltMenu = MenuItem::create([
            'sku' => 'MENU-MALT',
            'pos_outlet_id' => $this->restaurantOutlet->id,
            'menu_category_id' => $this->mainsCat->id,
            'name' => 'Chilled Malt Can',
            'price_minor' => 100000, // 1,000 NGN
            'price' => 1000.00,
            'stock_tracking_policy' => 'direct_item',
            'direct_inventory_item_id' => $malt->id,
            'direct_deduction_quantity' => 1,
            'is_active' => true,
            'is_available' => true,
        ]);

        // Cashier tries to sell 12 cans from Kitchen Store (only 10 in Kitchen Store) -> blocked with 422
        $this->actingAs($this->posCashier)->postJson('/api/v1/admin/pos/orders', [
            'pos_outlet_id' => $this->restaurantOutlet->id,
            'order_type' => 'bar',
            'submit' => true,
            'items' => [['menu_item_id' => $maltMenu->id, 'quantity' => 12]],
        ])->assertStatus(422);

        // Enable hotel policy `operations.pos_allow_negative_stock_with_override = true`
        $this->actingAs($this->manager)->putJson('/api/v1/admin/settings', [
            'operations' => ['pos_allow_negative_stock_with_override' => true],
        ])->assertOk();

        // Cashier still cannot use negative override (lacks `inventory.negative_override`)
        $this->actingAs($this->posCashier)->postJson('/api/v1/admin/pos/orders', [
            'pos_outlet_id' => $this->restaurantOutlet->id,
            'order_type' => 'bar',
            'submit' => true,
            'allow_negative_stock_override' => true,
            'negative_stock_reason' => 'Physical cans transferred from main store pending entry',
            'items' => [['menu_item_id' => $maltMenu->id, 'quantity' => 12]],
        ])->assertForbidden();

        // POS Supervisor holds `inventory.negative_override` -> succeeds with mandatory reason and logs audit event
        $overrideOrder = $this->actingAs($this->posSupervisor)->postJson('/api/v1/admin/pos/orders', [
            'pos_outlet_id' => $this->restaurantOutlet->id,
            'order_type' => 'bar',
            'submit' => true,
            'allow_negative_stock_override' => true,
            'negative_stock_reason' => 'Physical cans transferred from main store pending entry',
            'items' => [['menu_item_id' => $maltMenu->id, 'quantity' => 12]],
        ])->assertCreated();

        $this->assertEquals(36.0, (float) $malt->fresh()->current_stock);
        $this->assertTrue(AuditLog::where('action', 'inventory.negative_stock_override')->exists());

        // 4. Verify POS shift/daily summary report endpoint
        $report = $this->actingAs($this->posSupervisor)->getJson('/api/v1/admin/pos/reports/summary')->assertOk();
        $this->assertSame(1, $report->json('data.totals.active_orders_count'));
        $this->assertSame(1350000, $report->json('data.totals.total_sales_minor')); // 12,000 + 7.5% tax (900) + 5% service (600) = 13,500 NGN
    }
}
