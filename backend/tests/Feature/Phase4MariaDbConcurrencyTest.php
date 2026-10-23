<?php

namespace Tests\Feature;

use App\Models\FolioEntry;
use App\Models\FolioPaymentAllocation;
use App\Models\GuestFolio;
use App\Models\PaymentTransaction;
use App\Models\RatePlan;
use App\Models\RatePlanRoomType;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use App\Services\HousekeepingService;
use App\Services\ReservationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Phase 4 Runtime InnoDB Multi-Process Concurrency & Database Trigger Verification Suite.
 *
 * Tests genuinely concurrent OS processes (`tests/ConcurrencyWorker.php`) against MariaDB/MySQL InnoDB
 * (`maichitta_test` on 127.0.0.1:3306) for:
 * - Concurrent housekeeping task claiming & completion races
 * - Concurrent maintenance room-blocking vs reservation booking race
 * - Concurrent duplicate accommodation charge synchronization race
 * - Concurrent manual folio charge posting, payment allocation, entry reversal, and folio settlement races
 * - MariaDB database-level BEFORE UPDATE / BEFORE DELETE immutability triggers on `folio_entries` and `folio_payment_allocations`
 */
class Phase4MariaDbConcurrencyTest extends TestCase
{
    private static bool $migrated = false;

    private RoomType $deluxeType;

    private Room $room101;

    private Room $room102;

    private RatePlan $standardPlan;

    private User $manager;

    private User $accountant;

    private User $hk1;

    private User $hk2;

    protected function setUp(): void
    {
        parent::setUp();

        if (env('RUN_MARIADB_CONCURRENCY_TESTS') !== '1') {
            $this->markTestSkipped('Set RUN_MARIADB_CONCURRENCY_TESTS=1 to run the MariaDB concurrency suite.');
        }

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
        foreach ([
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
        ');
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->seed(RolesAndPermissionsSeeder::class);

        Setting::updateOrCreate(['group' => 'booking', 'key' => 'online_booking_enabled'], ['value' => true, 'is_public' => true]);
        Setting::updateOrCreate(['group' => 'booking', 'key' => 'require_payment_for_online_booking'], ['value' => false, 'is_public' => true]);
        Setting::updateOrCreate(['group' => 'booking', 'key' => 'tax_percentage'], ['value' => '7.50', 'is_public' => true]);
        Setting::updateOrCreate(['group' => 'booking', 'key' => 'service_charge_percentage'], ['value' => '5.00', 'is_public' => true]);

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

        $this->room102 = Room::create([
            'room_number' => '102',
            'room_type_id' => $this->deluxeType->id,
            'operational_status' => 'active',
            'housekeeping_status' => 'clean',
        ]);

        $this->standardPlan = RatePlan::create([
            'name' => 'Best Available Rate',
            'code' => 'BAR',
            'meal_Basis' => 'breakfast_included',
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
            ['email' => 'p4-manager@maichitta.test'],
            ['name' => 'P4 Manager', 'password' => bcrypt('Secret-12345'), 'is_active' => true]
        );
        $this->manager->roles()->sync([Role::where('slug', 'hotel-manager')->value('id')]);

        $this->accountant = User::firstOrCreate(
            ['email' => 'p4-accountant@maichitta.test'],
            ['name' => 'P4 Accountant', 'password' => bcrypt('Secret-12345'), 'is_active' => true]
        );
        $this->accountant->roles()->sync([Role::where('slug', 'accountant')->value('id')]);

        $this->hk1 = User::firstOrCreate(
            ['email' => 'p4-hk1@maichitta.test'],
            ['name' => 'Housekeeper Alpha', 'password' => bcrypt('Secret-12345'), 'is_active' => true]
        );
        $this->hk1->roles()->sync([Role::where('slug', 'housekeeper')->value('id')]);

        $this->hk2 = User::firstOrCreate(
            ['email' => 'p4-hk2@maichitta.test'],
            ['name' => 'Housekeeper Beta', 'password' => bcrypt('Secret-12345'), 'is_active' => true]
        );
        $this->hk2->roles()->sync([Role::where('slug', 'housekeeper')->value('id')]);
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
            'MAIL_MAILER' => 'array',
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

    public function test_concurrent_housekeeping_task_claiming_and_completion_race(): void
    {
        $hkService = app(HousekeepingService::class);
        $task = $hkService->createTask([
            'room_id' => $this->room101->id,
            'task_type' => 'checkout_cleaning',
            'priority' => 'high',
            'scheduled_date' => now()->format('Y-m-d'),
            'title' => 'Concurrent Claim Test Task',
        ], $this->manager);

        // Two housekeepers race to claim & start the same pending unassigned task simultaneously
        $claimResults = $this->runConcurrentWorkers([
            [
                'action' => 'hk_start_task',
                'params' => ['task_id' => $task->id, 'actor_id' => $this->hk1->id],
            ],
            [
                'action' => 'hk_start_task',
                'params' => ['task_id' => $task->id, 'actor_id' => $this->hk2->id],
            ],
        ]);

        $claimSuccesses = array_filter($claimResults, fn ($r) => ($r['ok'] ?? false) === true);
        $this->assertCount(1, $claimSuccesses, 'Only one housekeeper may claim and start the pending task: '.json_encode($claimResults));
        $this->assertSame('in_progress', $task->fresh()->status);

        // Next, both workers race to mark the task completed simultaneously -> only the assigned housekeeper succeeds!
        $completeResults = $this->runConcurrentWorkers([
            [
                'action' => 'hk_complete_task',
                'params' => ['task_id' => $task->id, 'actor_id' => $this->hk1->id],
            ],
            [
                'action' => 'hk_complete_task',
                'params' => ['task_id' => $task->id, 'actor_id' => $this->hk2->id],
            ],
        ]);

        $completeSuccesses = array_filter($completeResults, fn ($r) => ($r['ok'] ?? false) === true);
        $this->assertCount(1, $completeSuccesses, 'Only the assigned housekeeper can complete the task: '.json_encode($completeResults));
        $this->assertSame('completed', $task->fresh()->status);
        $this->assertSame('inspecting', $this->room101->fresh()->housekeeping_status);
    }

    public function test_concurrent_maintenance_out_of_service_block_vs_last_room_booking_race(): void
    {
        $in = now()->addDays(6)->format('Y-m-d');
        $out = now()->addDays(8)->format('Y-m-d');

        // Pre-book Room 102 so Room 101 is the only remaining sellable Deluxe room for [$in, $out)
        $resService = app(ReservationService::class);
        $resService->createReservation([
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'assigned_room_id' => $this->room102->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'adults' => 2,
            'rooms_count' => 1,
            'guest_name' => 'Existing Room 102 Guest',
            'guest_email' => 'existing102@example.test',
            'guest_phone' => '+2348000000700',
        ], $this->manager, isPublic: false);

        // Worker 1 tries to book the last room while Worker 2 tries to create a maintenance work order that places Room 101 out of service
        $results = $this->runConcurrentWorkers([
            [
                'action' => 'create_reservation',
                'params' => [
                    'data' => [
                        'room_type_id' => $this->deluxeType->id,
                        'rate_plan_id' => $this->standardPlan->id,
                        'check_in_date' => $in,
                        'check_out_date' => $out,
                        'adults' => 2,
                        'rooms_count' => 1,
                        'guest_name' => 'Maintenance Race Guest',
                        'guest_email' => 'mrg@example.test',
                        'guest_phone' => '+2348000000701',
                    ],
                ],
            ],
            [
                'action' => 'maint_create_blocking_oos',
                'params' => [
                    'actor_id' => $this->manager->id,
                    'data' => [
                        'room_id' => $this->room101->id,
                        'category' => 'plumbing',
                        'priority' => 'emergency',
                        'title' => 'Burst pipe in bathroom',
                        'description' => 'Emergency plumbing repair.',
                        'blocks_room' => true,
                        'mark_out_of_service' => true,
                        'blocked_from' => $in,
                        'blocked_until' => $out,
                    ],
                ],
            ],
        ]);

        // Both lock RoomType::lockForUpdate() -> cannot both confirm the last room AND mark that last room out_of_service!
        $successes = array_filter($results, fn ($r) => ($r['ok'] ?? false) === true);
        $this->assertCount(1, $successes, 'Cannot both book the final room AND place it out of service for maintenance: '.json_encode($results));
    }

    public function test_concurrent_folio_accommodation_sync_charges_allocations_reversals_settlement_and_mariadb_triggers(): void
    {
        $in = now()->addDays(3)->format('Y-m-d');
        $out = now()->addDays(5)->format('Y-m-d'); // 2 nights @ 40,000 = 80,000 + 6,000 tax + 4,000 fee = 90,000 NGN (9,000,000 minor)

        $resService = app(ReservationService::class);
        $res = $resService->createReservation([
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'assigned_room_id' => $this->room101->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'adults' => 2,
            'rooms_count' => 1,
            'guest_name' => 'Folio Concurrency Guest',
            'guest_email' => 'foliorace@example.test',
            'guest_phone' => '+2348000000801',
        ], $this->manager, isPublic: false);

        $folio = GuestFolio::where('reservation_id', $res->id)->firstOrFail();

        // 1. Two workers concurrently trigger `syncAccommodationCharges` on the same folio -> zero duplicate nights!
        $syncRace = $this->runConcurrentWorkers([
            [
                'action' => 'folio_sync_accommodation',
                'params' => ['folio_id' => $folio->id, 'actor_id' => $this->manager->id],
            ],
            [
                'action' => 'folio_sync_accommodation',
                'params' => ['folio_id' => $folio->id, 'actor_id' => $this->manager->id],
            ],
        ]);

        $this->assertTrue($syncRace[0]['ok'] ?? false);
        $this->assertTrue($syncRace[1]['ok'] ?? false);
        $this->assertSame(2, FolioEntry::where('guest_folio_id', $folio->id)->where('entry_type', 'accommodation')->count());
        $this->assertSame(9000000, $folio->fresh()->total_charges_minor);

        // 2. Two workers concurrently post different manual charges (5,000 NGN and 3,000 NGN) to the same folio
        // Row lock on `GuestFolio` serializes the postings so neither charge is lost in `total_charges_minor`!
        $chargeRace = $this->runConcurrentWorkers([
            [
                'action' => 'folio_post_charge',
                'params' => [
                    'folio_id' => $folio->id,
                    'actor_id' => $this->manager->id,
                    'data' => ['category' => 'laundry', 'description' => 'Concurrent Charge 1', 'quantity' => 1, 'unit_amount' => 5000.00],
                ],
            ],
            [
                'action' => 'folio_post_charge',
                'params' => [
                    'folio_id' => $folio->id,
                    'actor_id' => $this->manager->id,
                    'data' => ['category' => 'minibar', 'description' => 'Concurrent Charge 2', 'quantity' => 1, 'unit_amount' => 3000.00],
                ],
            ],
        ]);

        $this->assertTrue($chargeRace[0]['ok'] ?? false);
        $this->assertTrue($chargeRace[1]['ok'] ?? false);
        $this->assertSame(9800000, $folio->fresh()->total_charges_minor); // 90,000 + 5,000 + 3,000 = 98,000 NGN

        // 3. Two workers concurrently attempt to reverse the SAME posted manual charge -> only 1 reversal succeeds!
        $manualEntryId = (int) $chargeRace[0]['entry_id'];
        $reverseRace = $this->runConcurrentWorkers([
            [
                'action' => 'folio_reverse_entry',
                'params' => ['entry_id' => $manualEntryId, 'actor_id' => $this->accountant->id],
            ],
            [
                'action' => 'folio_reverse_entry',
                'params' => ['entry_id' => $manualEntryId, 'actor_id' => $this->accountant->id],
            ],
        ]);

        $revSuccesses = array_filter($reverseRace, fn ($r) => ($r['ok'] ?? false) === true);
        $this->assertCount(1, $revSuccesses, 'Only 1 concurrent reversal of a folio entry may succeed.');
        $this->assertSame(9300000, $folio->fresh()->total_charges_minor); // 98,000 - 5,000 = 93,000 NGN

        // 4. Concurrent payment allocation race: create an unallocated PaymentTransaction of 93,000 NGN
        $tx = PaymentTransaction::create([
            'transaction_reference' => 'PAY-CONC-93000',
            'reservation_id' => $res->id,
            'type' => 'payment',
            'method' => 'bank_transfer',
            'provider' => 'manual',
            'currency_code' => 'NGN',
            'amount_minor' => 9300000,
            'amount' => 93000.00,
            'status' => 'completed',
            'receipt_number' => 'RCP-CONC-1',
            'paid_at' => now(),
            'recorded_by' => $this->accountant->id,
        ]);

        // Two workers race to allocate the full 93,000 NGN from `$tx` simultaneously -> only 1 succeeds!
        $allocRace = $this->runConcurrentWorkers([
            [
                'action' => 'folio_allocate_payment',
                'params' => [
                    'folio_id' => $folio->id,
                    'payment_transaction_id' => $tx->id,
                    'amount_minor' => 9300000,
                    'actor_id' => $this->accountant->id,
                ],
            ],
            [
                'action' => 'folio_allocate_payment',
                'params' => [
                    'folio_id' => $folio->id,
                    'payment_transaction_id' => $tx->id,
                    'amount_minor' => 9300000,
                    'actor_id' => $this->accountant->id,
                ],
            ],
        ]);

        $allocSuccesses = array_filter($allocRace, fn ($r) => ($r['ok'] ?? false) === true);
        $this->assertCount(1, $allocSuccesses, 'Payment cannot be over-allocated across concurrent workers: '.json_encode($allocRace));
        $this->assertSame(9300000, $folio->fresh()->total_payments_minor);
        $this->assertSame(0, $folio->fresh()->balance_due_minor);

        // 5. Concurrent folio settlement attempts: two workers race to settle & close the zero-balance folio -> only 1 succeeds!
        $settleRace = $this->runConcurrentWorkers([
            [
                'action' => 'folio_settle',
                'params' => ['folio_id' => $folio->id, 'actor_id' => $this->manager->id],
            ],
            [
                'action' => 'folio_settle',
                'params' => ['folio_id' => $folio->id, 'actor_id' => $this->accountant->id],
            ],
        ]);

        $settleSuccesses = array_filter($settleRace, fn ($r) => ($r['ok'] ?? false) === true);
        $this->assertCount(1, $settleSuccesses, 'Only 1 concurrent folio settlement may succeed: '.json_encode($settleRace));
        $this->assertSame('closed', $folio->fresh()->status);

        // 6. Verify MariaDB BEFORE UPDATE and BEFORE DELETE triggers block raw SQL mutation/deletion on `folio_entries` and `folio_payment_allocations`
        try {
            DB::table('folio_entries')->where('id', $manualEntryId)->update(['total_amount_minor' => 100]);
            $this->fail('Expected MariaDB trigger to reject raw UPDATE on posted folio_entries.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable at the database level', $e->getMessage());
        }

        try {
            DB::table('folio_entries')->where('id', $manualEntryId)->delete();
            $this->fail('Expected MariaDB trigger to reject raw DELETE on folio_entries.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('cannot be deleted at the database level', $e->getMessage());
        }

        $allocId = FolioPaymentAllocation::where('guest_folio_id', $folio->id)->value('id');
        try {
            DB::table('folio_payment_allocations')->where('id', $allocId)->update(['allocated_amount_minor' => 100]);
            $this->fail('Expected MariaDB trigger to reject raw UPDATE on folio_payment_allocations.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable at the database level', $e->getMessage());
        }
    }
}
