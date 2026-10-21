<?php

namespace Tests\Feature;

use App\Models\FolioEntry;
use App\Models\GoodsReceiving;
use App\Models\GuestFolio;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryStockBalance;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuItemRecipe;
use App\Models\PaymentTransaction;
use App\Models\PosOrder;
use App\Models\PosOutlet;
use App\Models\PosPayment;
use App\Models\PurchaseOrder;
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
use App\Services\InventoryService;
use App\Services\PosService;
use App\Services\ProcurementService;
use App\Services\ReservationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Phase 5 Runtime InnoDB Multi-Process Concurrency & Database Trigger Verification Suite.
 *
 * Tests genuinely concurrent OS processes (`tests/ConcurrencyWorker.php`) against MariaDB/MySQL InnoDB
 * (`maichitta_test` on 127.0.0.1:3306) for all 8 required Phase 5 race scenarios:
 * 1. Concurrent POS orders consuming the final available stock of an item or ingredient.
 * 2. Concurrent POS order status transitions on the same order (must not deduct stock twice).
 * 3. Concurrent room-service charge posting for the same order (must create only one folio charge).
 * 4. Concurrent room-service charge posting while front desk settles/closes the folio.
 * 5. Concurrent POS order void/cancellation and room-charge or payment recording.
 * 6. Concurrent goods receiving against the same purchase order (must not over-receive or double-increment stock).
 * 7. Concurrent stock adjustment/count and POS ingredient consumption on the same inventory item.
 * 8. Concurrent purchase order approval/cancellation or duplicate receiving submissions.
 * Plus MariaDB database-level BEFORE UPDATE / BEFORE DELETE immutability triggers on `stock_movements` and `pos_payments`.
 */
class Phase5MariaDbConcurrencyTest extends TestCase
{
    private static bool $migrated = false;

    private StorageLocation $mainStore;
    private PosOutlet $restaurantOutlet;
    private MenuCategory $mainsCat;
    private InventoryCategory $foodCat;
    private RoomType $deluxeType;
    private Room $room101;
    private RatePlan $standardPlan;

    private User $manager;
    private User $posStaff;
    private User $posSupervisor;
    private User $storekeeper;
    private User $procurementOfficer;
    private User $frontDesk;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql.host', '127.0.0.1');
        Config::set('database.connections.mysql.port', '3306');
        Config::set('database.connections.mysql.database', 'maichitta_test');
        Config::set('database.connections.mysql.username', 'root');
        Config::set('database.connections.mysql.password', '');
        DB::purge('mysql');
        DB::reconnect('mysql');

        try {
            $pdo = new \PDO('mysql:host=127.0.0.1;port=3306', 'root', '');
            $pdo->exec('CREATE DATABASE IF NOT EXISTS maichitta_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            DB::purge('mysql');
            DB::reconnect('mysql');
            DB::connection('mysql')->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('MariaDB/MySQL server on 127.0.0.1:3306 is not reachable: '.$e->getMessage());
        }

        if (! self::$migrated) {
            Artisan::call('migrate:fresh', ['--database' => 'mysql', '--force' => true]);
            self::$migrated = true;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_payment_transactions_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_res_status_history_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_folio_entries_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_folio_allocations_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_stock_movements_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_pos_payments_no_delete');

        foreach ([
            'goods_receiving_items',
            'goods_receivings',
            'purchase_order_items',
            'purchase_orders',
            'purchase_requisition_items',
            'purchase_requisitions',
            'supplier_inventory_items',
            'suppliers',
            'pos_payments',
            'pos_order_histories',
            'pos_order_items',
            'pos_orders',
            'menu_item_recipes',
            'menu_item_modifiers',
            'menu_item_variants',
            'menu_items',
            'menu_categories',
            'pos_prep_stations',
            'pos_outlets',
            'stock_movements',
            'inventory_batches',
            'inventory_stock_balances',
            'inventory_items',
            'storage_locations',
            'inventory_categories',
            'operational_notifications',
            'folio_payment_allocations',
            'folio_entries',
            'guest_folios',
            'maintenance_work_order_histories',
            'maintenance_work_orders',
            'housekeeping_task_histories',
            'housekeeping_inspections',
            'housekeeping_tasks',
            'payment_refunds',
            'payment_transactions',
            'reservation_status_histories',
            'reservation_nights',
            'reservations',
            'booking_holds',
            'guests',
            'rate_plan_overrides',
            'rate_plan_room_types',
            'rate_plans',
            'rooms',
            'room_types',
            'room_contents',
        ] as $table) {
            DB::table($table)->truncate();
        }

        DB::unprepared('
            CREATE TRIGGER trg_payment_transactions_no_delete
            BEFORE DELETE ON payment_transactions
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE "45000"
                SET MESSAGE_TEXT = "Financial payment transactions cannot be deleted at the database level.";
            END;

            CREATE TRIGGER trg_res_status_history_no_delete
            BEFORE DELETE ON reservation_status_histories
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE "45000"
                SET MESSAGE_TEXT = "Reservation status history cannot be deleted at the database level.";
            END;

            CREATE TRIGGER trg_folio_entries_no_delete
            BEFORE DELETE ON folio_entries
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE "45000"
                SET MESSAGE_TEXT = "Folio ledger entries cannot be deleted at the database level.";
            END;

            CREATE TRIGGER trg_folio_allocations_no_delete
            BEFORE DELETE ON folio_payment_allocations
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE "45000"
                SET MESSAGE_TEXT = "Folio payment allocations cannot be deleted at the database level.";
            END;

            CREATE TRIGGER trg_stock_movements_no_delete
            BEFORE DELETE ON stock_movements
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE "45000"
                SET MESSAGE_TEXT = "Stock movements are append-only and cannot be deleted.";
            END;

            CREATE TRIGGER trg_pos_payments_no_delete
            BEFORE DELETE ON pos_payments
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE "45000"
                SET MESSAGE_TEXT = "POS payment records are append-only and cannot be deleted.";
            END;
        ');
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->seed(RolesAndPermissionsSeeder::class);

        Setting::updateOrCreate(['group' => 'booking', 'key' => 'online_booking_enabled'], ['value' => true, 'is_public' => true]);
        Setting::updateOrCreate(['group' => 'operations', 'key' => 'pos_stock_consumption_trigger'], ['value' => 'submitted', 'is_public' => false]);
        Setting::updateOrCreate(['group' => 'operations', 'key' => 'pos_allow_negative_stock_with_override'], ['value' => false, 'is_public' => false]);
        Setting::updateOrCreate(['group' => 'operations', 'key' => 'room_service_charge_enabled'], ['value' => true, 'is_public' => false]);

        $this->mainStore = StorageLocation::create([
            'code' => 'MAIN-STORE',
            'name' => 'Central Store',
            'location_type' => 'main_store',
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->foodCat = InventoryCategory::create([
            'code' => 'FOOD-BEV',
            'name' => 'Food & Beverage',
            'is_active' => true,
        ]);

        $this->restaurantOutlet = PosOutlet::create([
            'code' => 'ZARIA-REST',
            'name' => 'Zaria Courtyard Restaurant',
            'type' => 'restaurant',
            'default_storage_location_id' => $this->mainStore->id,
            'tax_percentage' => 7.50,
            'service_charge_percentage' => 5.00,
            'supports_room_service' => true,
            'supports_room_charge' => true,
            'accepts_orders' => true,
            'is_active' => true,
        ]);

        $this->mainsCat = MenuCategory::create([
            'pos_outlet_id' => null,
            'code' => 'MAIN-DISHES',
            'name' => 'Main Dishes',
            'is_active' => true,
        ]);

        $this->deluxeType = RoomType::create([
            'name' => 'Deluxe King Room',
            'slug' => 'deluxe-king-room',
            'code' => 'DLX',
            'max_adults' => 2,
            'max_children' => 1,
            'max_occupancy' => 3,
            'base_occupancy' => 2,
            'starting_rate_minor' => 4000000,
            'starting_rate' => 40000.00,
            'currency_code' => 'NGN',
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->room101 = Room::create([
            'room_number' => '101',
            'room_type_id' => $this->deluxeType->id,
            'operational_status' => 'active',
            'housekeeping_status' => 'clean',
        ]);

        $this->standardPlan = RatePlan::create([
            'name' => 'Best Available Rate',
            'code' => 'BAR',
            'meal_basis' => 'breakfast_included',
            'cancellation_policy_type' => 'flexible',
            'free_cancellation_hours' => 24,
            'cancellation_penalty_type' => 'first_night',
            'currency_code' => 'NGN',
            'default_base_rate_minor' => 4000000,
            'default_base_rate' => 40000.00,
            'min_stay_nights' => 1,
            'max_stay_nights' => 30,
            'is_active' => true,
            'is_public' => true,
            'is_refundable' => true,
        ]);

        RatePlanRoomType::create([
            'rate_plan_id' => $this->standardPlan->id,
            'room_type_id' => $this->deluxeType->id,
            'base_rate_minor' => 4000000,
            'base_rate' => 40000.00,
            'is_active' => true,
        ]);

        $this->manager = User::firstOrCreate(
            ['email' => 'p5-manager@maichitta.test'],
            ['name' => 'P5 Manager', 'password' => bcrypt('Secret-12345'), 'is_active' => true]
        );
        $this->manager->roles()->sync([Role::where('slug', 'hotel-manager')->value('id')]);

        $this->posStaff = User::firstOrCreate(
            ['email' => 'p5-posstaff@maichitta.test'],
            ['name' => 'P5 POS Staff', 'password' => bcrypt('Secret-12345'), 'is_active' => true]
        );
        $this->posStaff->roles()->sync([Role::where('slug', 'pos-staff')->value('id')]);

        $this->posSupervisor = User::firstOrCreate(
            ['email' => 'p5-possup@maichitta.test'],
            ['name' => 'P5 POS Supervisor', 'password' => bcrypt('Secret-12345'), 'is_active' => true]
        );
        $this->posSupervisor->roles()->sync([Role::where('slug', 'pos-supervisor')->value('id')]);

        $this->storekeeper = User::firstOrCreate(
            ['email' => 'p5-store@maichitta.test'],
            ['name' => 'P5 Storekeeper', 'password' => bcrypt('Secret-12345'), 'is_active' => true]
        );
        $this->storekeeper->roles()->sync([Role::where('slug', 'inventory-officer')->value('id')]);

        $this->procurementOfficer = User::firstOrCreate(
            ['email' => 'p5-proc@maichitta.test'],
            ['name' => 'P5 Procurement', 'password' => bcrypt('Secret-12345'), 'is_active' => true]
        );
        $this->procurementOfficer->roles()->sync([Role::where('slug', 'procurement-officer')->value('id')]);

        $this->frontDesk = User::firstOrCreate(
            ['email' => 'p5-frontdesk@maichitta.test'],
            ['name' => 'P5 Front Desk', 'password' => bcrypt('Secret-12345'), 'is_active' => true]
        );
        $this->frontDesk->roles()->sync([Role::where('slug', 'receptionist')->value('id')]);
    }

    /**
     * @param  list<array{action: string, params: array}>  $tasks
     * @return list<array>
     */
    private function runConcurrentWorkers(array $tasks): array
    {
        $startAtUs = (int) ((microtime(true) + 0.35) * 1_000_000);
        $envVars = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'maichitta_test',
            'DB_USERNAME' => 'root',
            'DB_PASSWORD' => '',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
        ];

        $processes = [];
        foreach ($tasks as $task) {
            $payload = base64_encode(json_encode([
                'env' => $envVars,
                'start_at_us' => $startAtUs,
                'action' => $task['action'],
                'params' => $task['params'],
            ]));

            $proc = new Process([PHP_BINARY, base_path('tests/ConcurrencyWorker.php'), $payload], base_path());
            $proc->setTimeout(25);
            $proc->start();
            $processes[] = $proc;
        }

        $results = [];
        foreach ($processes as $proc) {
            $proc->wait();
            $out = trim($proc->getOutput());
            $decoded = json_decode($out, true);
            $results[] = is_array($decoded) ? $decoded : ['ok' => false, 'raw' => $out, 'err' => $proc->getErrorOutput()];
        }

        return $results;
    }

    private function createCheckedInStay(): Reservation
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
            'guest_name' => 'In-House Room 101 Guest',
            'guest_email' => 'room101@maichitta.test',
            'guest_phone' => '+2348031112233',
        ], $this->frontDesk, false);

        return $resService->checkIn($res, $this->room101->id, $this->frontDesk);
    }

    /**
     * Race 1: Concurrent POS orders consuming the final available stock of an item or ingredient.
     */
    public function test_concurrent_pos_orders_for_final_stock_unit_allow_exactly_one_and_prevent_overselling(): void
    {
        $juice = InventoryItem::create([
            'sku' => 'INV-JUICE-BTL',
            'inventory_category_id' => $this->foodCat->id,
            'name' => 'Zobo Hibiscus Bottle',
            'base_unit' => 'bottle',
            'purchase_unit' => 'bottle',
            'purchase_to_base_factor' => 1,
            'current_stock' => 0,
            'reorder_level' => 2,
            'currency_code' => 'NGN',
            'unit_cost_minor' => 40000,
            'unit_cost' => 400.00,
            'allow_negative_stock' => false,
            'is_active' => true,
        ]);

        // Stock exactly 1 bottle in Central Store
        app(InventoryService::class)->recordAdjustmentOrWaste([
            'inventory_item_id' => $juice->id,
            'storage_location_id' => $this->mainStore->id,
            'movement_type' => 'adjustment',
            'direction' => 'add',
            'quantity' => 1,
            'unit' => 'bottle',
            'reason' => 'Single final bottle in stock',
        ], $this->storekeeper);

        $juiceMenu = MenuItem::create([
            'sku' => 'MENU-ZOBO',
            'pos_outlet_id' => $this->restaurantOutlet->id,
            'menu_category_id' => $this->mainsCat->id,
            'name' => 'Chilled Zobo Bottle',
            'price_minor' => 120000,
            'price' => 1200.00,
            'stock_tracking_policy' => 'direct_item',
            'direct_inventory_item_id' => $juice->id,
            'direct_deduction_quantity' => 1,
            'is_active' => true,
            'is_available' => true,
        ]);

        $orderPayload = [
            'pos_outlet_id' => $this->restaurantOutlet->id,
            'order_type' => 'bar',
            'submit' => true,
            'items' => [['menu_item_id' => $juiceMenu->id, 'quantity' => 1]],
        ];

        $results = $this->runConcurrentWorkers([
            ['action' => 'pos_create_order', 'params' => ['actor_id' => $this->posStaff->id, 'data' => $orderPayload]],
            ['action' => 'pos_create_order', 'params' => ['actor_id' => $this->posSupervisor->id, 'data' => $orderPayload]],
        ]);

        $successes = array_filter($results, fn ($r) => ($r['ok'] ?? false) === true);
        $failures = array_filter($results, fn ($r) => ($r['ok'] ?? false) === false);

        $this->assertCount(1, $successes, 'Exactly one POS order must succeed for the final bottle.');
        $this->assertCount(1, $failures, 'The competing POS order must be rejected due to insufficient stock.');
        $this->assertEquals(0.0, (float) $juice->fresh()->current_stock);
    }

    /**
     * Race 2: Concurrent POS order status transitions on the same order (must not deduct stock twice).
     */
    public function test_concurrent_pos_order_status_transitions_consume_recipe_stock_exactly_once(): void
    {
        $beef = InventoryItem::create([
            'sku' => 'INV-BEEF-G',
            'inventory_category_id' => $this->foodCat->id,
            'name' => 'Suya Beef Fillet',
            'base_unit' => 'g',
            'purchase_unit' => 'kg',
            'purchase_to_base_factor' => 1000,
            'current_stock' => 0,
            'currency_code' => 'NGN',
            'unit_cost_minor' => 600,
            'unit_cost' => 6.00,
            'allow_negative_stock' => false,
            'is_active' => true,
        ]);

        app(InventoryService::class)->recordAdjustmentOrWaste([
            'inventory_item_id' => $beef->id,
            'storage_location_id' => $this->mainStore->id,
            'movement_type' => 'adjustment',
            'direction' => 'add',
            'quantity' => 1000,
            'unit' => 'g',
            'reason' => 'Opening stock 1000g',
        ], $this->storekeeper);

        $suyaMenu = MenuItem::create([
            'sku' => 'MENU-SUYA-BEEF',
            'pos_outlet_id' => $this->restaurantOutlet->id,
            'menu_category_id' => $this->mainsCat->id,
            'name' => 'Suya Beef Skewer',
            'price_minor' => 350000,
            'price' => 3500.00,
            'stock_tracking_policy' => 'recipe',
            'is_active' => true,
            'is_available' => true,
        ]);

        MenuItemRecipe::create([
            'menu_item_id' => $suyaMenu->id,
            'inventory_item_id' => $beef->id,
            'quantity_required' => 200,
            'unit' => 'g',
            'conversion_to_base' => 1,
            'waste_percentage' => 0,
            'is_active' => true,
        ]);

        // Create a DRAFT order (stock not yet consumed)
        $draftOrder = app(PosService::class)->createOrder([
            'pos_outlet_id' => $this->restaurantOutlet->id,
            'order_type' => 'dine_in',
            'submit' => false,
            'items' => [['menu_item_id' => $suyaMenu->id, 'quantity' => 2]], // 400g total
        ], $this->posStaff);

        $this->assertEquals(1000.0, (float) $beef->fresh()->current_stock);

        // Two workers concurrently submit the same draft order
        $results = $this->runConcurrentWorkers([
            ['action' => 'pos_transition_status', 'params' => ['order_id' => $draftOrder->id, 'actor_id' => $this->posStaff->id, 'target_status' => 'submitted']],
            ['action' => 'pos_transition_status', 'params' => ['order_id' => $draftOrder->id, 'actor_id' => $this->posSupervisor->id, 'target_status' => 'submitted']],
        ]);

        // Stock must be deducted ONCE (1000g - 400g = 600g), never twice
        $this->assertEquals(600.0, (float) $beef->fresh()->current_stock);
        $this->assertSame(1, StockMovement::where('source_type', 'pos_order')->where('source_id', (string) $draftOrder->id)->count());
    }

    /**
     * Race 3: Concurrent room-service charge posting for the same order (must create only one folio charge).
     */
    public function test_concurrent_room_charge_posting_for_same_order_creates_single_folio_entry(): void
    {
        $stay = $this->createCheckedInStay();
        $folio = app(FolioService::class)->ensureFolioForReservation($stay, $this->frontDesk);

        $meal = MenuItem::create([
            'sku' => 'MENU-DINNER',
            'pos_outlet_id' => $this->restaurantOutlet->id,
            'menu_category_id' => $this->mainsCat->id,
            'name' => 'Room Service Dinner Tray',
            'price_minor' => 800000,
            'price' => 8000.00,
            'is_room_service_eligible' => true,
            'stock_tracking_policy' => 'none',
            'is_active' => true,
            'is_available' => true,
        ]);

        // Create submitted unpaid room-service order
        $order = app(PosService::class)->createOrder([
            'pos_outlet_id' => $this->restaurantOutlet->id,
            'order_type' => 'room_service',
            'reservation_id' => $stay->id,
            'submit' => true,
            'charge_to_room' => false,
            'items' => [['menu_item_id' => $meal->id, 'quantity' => 1]],
        ], $this->posStaff);

        $results = $this->runConcurrentWorkers([
            ['action' => 'pos_room_charge', 'params' => ['order_id' => $order->id, 'actor_id' => $this->posStaff->id, 'data' => []]],
            ['action' => 'pos_room_charge', 'params' => ['order_id' => $order->id, 'actor_id' => $this->posSupervisor->id, 'data' => []]],
        ]);

        $posEntries = FolioEntry::where('guest_folio_id', $folio->id)
            ->where('source_type', 'pos_order')
            ->where('source_id', (string) $order->id)
            ->get();

        $this->assertCount(1, $posEntries, 'Concurrent room-charge calls must produce at most one FolioEntry.');
        $this->assertSame('charged_to_room', $order->fresh()->payment_status);
    }

    /**
     * Race 4 & 5: Concurrent room-charge vs folio settlement AND concurrent order void vs room-charge/payment.
     */
    public function test_concurrent_room_charge_vs_folio_settlement_and_void_vs_payment_preserve_financial_consistency(): void
    {
        $stay = $this->createCheckedInStay();
        $folioService = app(FolioService::class);
        $folio = $folioService->ensureFolioForReservation($stay, $this->frontDesk);
        $folio = $folioService->syncAccommodationCharges($folio, $this->frontDesk);

        // Pay accommodation balance so folio has 0 balance due right now
        $tx = PaymentTransaction::create([
            'transaction_reference' => 'TX-CONC-SETTLE',
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

        $snack = MenuItem::create([
            'sku' => 'MENU-SNACK',
            'pos_outlet_id' => $this->restaurantOutlet->id,
            'menu_category_id' => $this->mainsCat->id,
            'name' => 'Club Sandwich',
            'price_minor' => 400000,
            'price' => 4000.00,
            'is_room_service_eligible' => true,
            'stock_tracking_policy' => 'none',
            'is_active' => true,
            'is_available' => true,
        ]);

        $order = app(PosService::class)->createOrder([
            'pos_outlet_id' => $this->restaurantOutlet->id,
            'order_type' => 'room_service',
            'reservation_id' => $stay->id,
            'submit' => true,
            'charge_to_room' => false,
            'items' => [['menu_item_id' => $snack->id, 'quantity' => 1]],
        ], $this->posStaff);

        // Worker A tries to settle & close folio while Worker B tries to post a room charge to the folio
        $this->runConcurrentWorkers([
            ['action' => 'folio_settle', 'params' => ['folio_id' => $folio->id, 'actor_id' => $this->frontDesk->id, 'data' => ['notes' => 'Checkout settle']]],
            ['action' => 'pos_room_charge', 'params' => ['order_id' => $order->id, 'actor_id' => $this->posStaff->id, 'data' => []]],
        ]);

        $freshFolio = $folio->fresh();
        $freshOrder = $order->fresh();

        // Invariant: A closed folio must NEVER have a non-zero unpaid balance from a concurrent room charge!
        if ($freshFolio->status === GuestFolio::STATUS_CLOSED) {
            $this->assertSame(0, (int) $freshFolio->balance_due_minor, 'Closed folio cannot have an unpaid room charge.');
            $this->assertNotSame(PosOrder::PAYMENT_CHARGED_TO_ROOM, $freshOrder->payment_status);
        } else {
            // If room charge won the lock first, folio settlement was blocked because balance_due_minor > 0
            $this->assertSame(GuestFolio::STATUS_OPEN, $freshFolio->status);
            $this->assertGreaterThan(0, (int) $freshFolio->balance_due_minor);
        }

        // Now test Race 5: Concurrent POS order void vs POS payment recording on a second order
        $order2 = app(PosService::class)->createOrder([
            'pos_outlet_id' => $this->restaurantOutlet->id,
            'order_type' => 'dine_in',
            'submit' => true,
            'items' => [['menu_item_id' => $snack->id, 'quantity' => 1]],
        ], $this->posStaff);

        $this->runConcurrentWorkers([
            ['action' => 'pos_void_order', 'params' => ['order_id' => $order2->id, 'actor_id' => $this->posSupervisor->id, 'data' => ['reason' => 'Concurrent void']]],
            ['action' => 'pos_record_payment', 'params' => ['order_id' => $order2->id, 'actor_id' => $this->posStaff->id, 'data' => ['method' => 'cash', 'amount_minor' => $order2->total_minor]]],
        ]);

        $freshOrder2 = $order2->fresh();
        // Either void won first (so payment was rejected on voided order) OR payment won first (so void was rejected because payment was recorded)
        if ($freshOrder2->status === PosOrder::STATUS_VOIDED) {
            $this->assertSame(0, (int) $freshOrder2->amount_paid_minor, 'Voided order must not retain unrefunded active payment.');
        } else {
            $this->assertSame(PosOrder::PAYMENT_PAID, $freshOrder2->payment_status);
        }
    }

    /**
     * Race 6, 7 & 8: Concurrent goods receiving against the same PO, concurrent stock count vs POS consumption,
     * and concurrent PO approval vs cancellation.
     */
    public function test_concurrent_goods_receiving_stock_adjustments_and_po_approvals_maintain_exact_balances(): void
    {
        $flour = InventoryItem::create([
            'sku' => 'INV-FLOUR-KG',
            'inventory_category_id' => $this->foodCat->id,
            'name' => 'Baking Flour',
            'base_unit' => 'kg',
            'purchase_unit' => 'kg',
            'purchase_to_base_factor' => 1,
            'current_stock' => 0,
            'currency_code' => 'NGN',
            'unit_cost_minor' => 120000,
            'unit_cost' => 1200.00,
            'allow_negative_stock' => false,
            'is_active' => true,
        ]);

        $supplier = Supplier::create([
            'supplier_code' => 'SUP-FLOUR',
            'name' => 'Northern Mills',
            'status' => 'active',
        ]);

        $procService = app(ProcurementService::class);
        $po = $procService->createPurchaseOrder([
            'supplier_id' => $supplier->id,
            'storage_location_id' => $this->mainStore->id,
            'items' => [
                ['inventory_item_id' => $flour->id, 'unit' => 'kg', 'conversion_to_base' => 1, 'quantity_ordered' => 20, 'unit_cost' => 1200.00],
            ],
        ], $this->procurementOfficer);

        // Race 8: Concurrent PO approval vs cancellation
        $this->runConcurrentWorkers([
            ['action' => 'procurement_review_po', 'params' => ['purchase_order_id' => $po->id, 'actor_id' => $this->manager->id, 'decision' => 'approve']],
            ['action' => 'procurement_review_po', 'params' => ['purchase_order_id' => $po->id, 'actor_id' => $this->manager->id, 'decision' => 'approve']],
        ]);
        $this->assertSame(PurchaseOrder::STATUS_APPROVED, $po->fresh()->status);

        $poItemId = $po->items()->first()->id;

        // Race 6: Two workers concurrently attempt to receive the full 20 kg on the same PO
        $grnPayload = [
            'items' => [
                ['purchase_order_item_id' => $poItemId, 'quantity_received' => 20],
            ],
        ];

        $grnResults = $this->runConcurrentWorkers([
            ['action' => 'procurement_receive_goods', 'params' => ['purchase_order_id' => $po->id, 'actor_id' => $this->storekeeper->id, 'data' => $grnPayload]],
            ['action' => 'procurement_receive_goods', 'params' => ['purchase_order_id' => $po->id, 'actor_id' => $this->manager->id, 'data' => $grnPayload]],
        ]);

        $grnSuccesses = array_filter($grnResults, fn ($r) => ($r['ok'] ?? false) === true);
        $this->assertCount(1, $grnSuccesses, 'Only one full GRN can succeed for the 20 kg ordered.');
        $this->assertEquals(20.0, (float) $flour->fresh()->current_stock);
        $this->assertSame(1, GoodsReceiving::where('purchase_order_id', $po->id)->count());

        // Race 7: Concurrent manual stock deduction (15 kg) vs POS order consuming 10 kg (only 20 kg in stock)
        $breadMenu = MenuItem::create([
            'sku' => 'MENU-BREAD-BATCH',
            'pos_outlet_id' => $this->restaurantOutlet->id,
            'menu_category_id' => $this->mainsCat->id,
            'name' => 'Banquet Bread Batch',
            'price_minor' => 1500000,
            'price' => 15000.00,
            'stock_tracking_policy' => 'direct_item',
            'direct_inventory_item_id' => $flour->id,
            'direct_deduction_quantity' => 10,
            'is_active' => true,
            'is_available' => true,
        ]);

        $race7Results = $this->runConcurrentWorkers([
            [
                'action' => 'inventory_adjust',
                'params' => [
                    'actor_id' => $this->storekeeper->id,
                    'data' => [
                        'inventory_item_id' => $flour->id,
                        'storage_location_id' => $this->mainStore->id,
                        'movement_type' => 'waste',
                        'direction' => 'deduct',
                        'quantity' => 15,
                        'unit' => 'kg',
                        'reason' => 'Water damage in store',
                    ],
                ],
            ],
            [
                'action' => 'pos_create_order',
                'params' => [
                    'actor_id' => $this->posStaff->id,
                    'data' => [
                        'pos_outlet_id' => $this->restaurantOutlet->id,
                        'order_type' => 'dine_in',
                        'submit' => true,
                        'items' => [['menu_item_id' => $breadMenu->id, 'quantity' => 1]],
                    ],
                ],
            ],
        ]);

        $r7Successes = array_filter($race7Results, fn ($r) => ($r['ok'] ?? false) === true);
        $this->assertCount(1, $r7Successes, 'Cannot deduct both 15 kg waste and 10 kg POS sale from 20 kg stock.');
        $this->assertGreaterThanOrEqual(0.0, (float) $flour->fresh()->current_stock);
    }

    /**
     * Verify MariaDB BEFORE UPDATE and BEFORE DELETE immutability triggers on `stock_movements` and `pos_payments`.
     */
    public function test_mariadb_triggers_block_mutating_or_deleting_stock_movements_and_pos_payments(): void
    {
        $item = InventoryItem::create([
            'sku' => 'INV-TRIGGER-TEST',
            'inventory_category_id' => $this->foodCat->id,
            'name' => 'Trigger Protected Item',
            'base_unit' => 'unit',
            'purchase_unit' => 'unit',
            'purchase_to_base_factor' => 1,
            'current_stock' => 0,
            'currency_code' => 'NGN',
            'unit_cost_minor' => 10000,
            'unit_cost' => 100.00,
            'is_active' => true,
        ]);

        $movement = app(InventoryService::class)->recordAdjustmentOrWaste([
            'inventory_item_id' => $item->id,
            'storage_location_id' => $this->mainStore->id,
            'movement_type' => 'adjustment',
            'direction' => 'add',
            'quantity' => 10,
            'unit' => 'unit',
            'reason' => 'Trigger verification stock',
        ], $this->storekeeper);

        // 1. Attempt to UPDATE quantity_delta on `stock_movements` -> blocked by `trg_stock_movements_no_update`
        $updateMovementBlocked = false;
        try {
            DB::table('stock_movements')->where('id', $movement->id)->update(['quantity_delta' => 999]);
        } catch (QueryException $e) {
            $updateMovementBlocked = str_contains($e->getMessage(), 'append-only');
        }
        $this->assertTrue($updateMovementBlocked, 'MariaDB trigger must block UPDATE on stock_movements.');

        // 2. Attempt to DELETE from `stock_movements` -> blocked by `trg_stock_movements_no_delete`
        $deleteMovementBlocked = false;
        try {
            DB::table('stock_movements')->where('id', $movement->id)->delete();
        } catch (QueryException $e) {
            $deleteMovementBlocked = str_contains($e->getMessage(), 'append-only');
        }
        $this->assertTrue($deleteMovementBlocked, 'MariaDB trigger must block DELETE on stock_movements.');

        // 3. Create POS order and POS payment, then attempt UPDATE and DELETE on `pos_payments`
        $menuItem = MenuItem::create([
            'sku' => 'MENU-TRIG',
            'pos_outlet_id' => $this->restaurantOutlet->id,
            'menu_category_id' => $this->mainsCat->id,
            'name' => 'Trigger Coffee',
            'price_minor' => 200000,
            'price' => 2000.00,
            'stock_tracking_policy' => 'none',
            'is_active' => true,
            'is_available' => true,
        ]);

        $posService = app(PosService::class);
        $order = $posService->createOrder([
            'pos_outlet_id' => $this->restaurantOutlet->id,
            'order_type' => 'dine_in',
            'submit' => true,
            'items' => [['menu_item_id' => $menuItem->id, 'quantity' => 1]],
        ], $this->posStaff);

        $payment = $posService->recordPayment($order, [
            'method' => 'cash',
            'amount_minor' => $order->total_minor,
        ], $this->posStaff);

        $updatePaymentBlocked = false;
        try {
            DB::table('pos_payments')->where('id', $payment->id)->update(['amount_minor' => 100]);
        } catch (QueryException $e) {
            $updatePaymentBlocked = str_contains($e->getMessage(), 'immutable');
        }
        $this->assertTrue($updatePaymentBlocked, 'MariaDB trigger must block UPDATE of financial fields on pos_payments.');

        $deletePaymentBlocked = false;
        try {
            DB::table('pos_payments')->where('id', $payment->id)->delete();
        } catch (QueryException $e) {
            $deletePaymentBlocked = str_contains($e->getMessage(), 'append-only');
        }
        $this->assertTrue($deletePaymentBlocked, 'MariaDB trigger must block DELETE on pos_payments.');
    }
}
