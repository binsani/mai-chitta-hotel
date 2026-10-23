<?php

namespace Tests\Feature;

use App\Models\BookingHold;
use App\Models\PaymentRefund;
use App\Models\PaymentTransaction;
use App\Models\RatePlan;
use App\Models\RatePlanRoomType;
use App\Models\Reservation;
use App\Models\ReservationNight;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use App\Services\ReservationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Runtime InnoDB Concurrency & Database-Level Constraint Verification Suite.
 *
 * Connects to the real MariaDB/MySQL InnoDB database (`maichitta_test` on 127.0.0.1:3306)
 * and spawns genuinely parallel OS processes (`tests/ConcurrencyWorker.php`) with a synchronized
 * microsecond barrier so two independent database connections race for the exact same InnoDB row locks.
 */
class Phase3MariaDbConcurrencyTest extends TestCase
{
    private static bool $migrated = false;

    private RoomType $deluxeType;

    private RoomType $suiteType;

    private Room $room101;

    private Room $room102;

    private Room $room201;

    private RatePlan $standardPlan;

    private User $manager;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();

        if (env('RUN_MARIADB_CONCURRENCY_TESTS') !== '1') {
            $this->markTestSkipped('Set RUN_MARIADB_CONCURRENCY_TESTS=1 to run the MariaDB concurrency suite.');
        }

        // Point default connection to MariaDB/MySQL InnoDB `maichitta_test`
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

        // Clean Phase 3 & Phase 4 tables between tests while preserving schema & triggers
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
        // Re-install BEFORE DELETE triggers after test cleanup
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

        if (Role::count() === 0) {
            $this->seed(RolesAndPermissionsSeeder::class);
        }

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

        $this->suiteType = RoomType::create([
            'name' => 'Ambassador Suite',
            'slug' => 'ambassador-suite',
            'code' => 'STE',
            'max_adults' => 3,
            'max_children' => 2,
            'max_occupancy' => 4,
            'base_occupancy' => 2,
            'starting_rate_minor' => 7500000,
            'starting_rate' => 75000.00,
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

        $this->room201 = Room::create([
            'room_number' => '201',
            'room_type_id' => $this->suiteType->id,
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

        RatePlanRoomType::create([
            'rate_plan_id' => $this->standardPlan->id,
            'room_type_id' => $this->suiteType->id,
            'base_rate_minor' => 7500000,
            'base_rate' => 75000.00,
            'is_active' => true,
        ]);

        $this->manager = User::firstOrCreate(
            ['email' => 'concurrency-manager@maichitta.test'],
            ['name' => 'Concurrency Manager', 'password' => bcrypt('Secret-12345'), 'status' => 'active']
        );
        $this->manager->roles()->syncWithoutDetaching([Role::where('slug', 'hotel-manager')->value('id')]);

        $this->accountant = User::firstOrCreate(
            ['email' => 'concurrency-accountant@maichitta.test'],
            ['name' => 'Concurrency Accountant', 'password' => bcrypt('Secret-12345'), 'status' => 'active']
        );
        $this->accountant->roles()->syncWithoutDetaching([Role::where('slug', 'accountant')->value('id')]);
    }

    /**
     * Spawn multiple genuinely independent PHP CLI processes that connect to MariaDB/InnoDB
     * and execute at the same synchronized barrier timestamp.
     *
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

    public function test_scenario_a_last_room_concurrent_booking_race_and_idempotency_race(): void
    {
        // Only 1 sellable Deluxe room (mark 102 inactive)
        $this->room102->update(['operational_status' => 'inactive']);

        $in = now()->addDays(5)->format('Y-m-d');
        $out = now()->addDays(7)->format('Y-m-d');

        // 1. Two independent processes race to book the last remaining room
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
                        'guest_name' => 'Concurrent Booker 1',
                        'guest_email' => 'booker1@example.test',
                        'guest_phone' => '+2348000000101',
                    ],
                ],
            ],
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
                        'guest_name' => 'Concurrent Booker 2',
                        'guest_email' => 'booker2@example.test',
                        'guest_phone' => '+2348000000102',
                    ],
                ],
            ],
        ]);

        $successes = array_filter($results, fn ($r) => ($r['ok'] ?? false) === true);
        $failures = array_filter($results, fn ($r) => ($r['ok'] ?? false) === false);

        $this->assertCount(1, $successes, 'Exactly 1 concurrent booking for the last room must succeed: '.json_encode($results));
        $this->assertCount(1, $failures, 'The second concurrent booking for the last room must be rejected.');
        $this->assertSame(1, Reservation::whereIn('status', Reservation::INVENTORY_CONSUMING_STATUSES)->count());
        $this->assertSame(2, ReservationNight::where('status', 'active')->count());

        // 2. Concurrent requests with the SAME idempotency_key return the same reservation without duplicating
        $this->room102->update(['operational_status' => 'active']); // open 2nd room so capacity isn't the blocker
        $idemResults = $this->runConcurrentWorkers([
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
                        'guest_name' => 'Idempotent Guest',
                        'guest_email' => 'idem@example.test',
                        'guest_phone' => '+2348000000103',
                        'idempotency_key' => 'concurrent-idem-key-99',
                    ],
                ],
            ],
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
                        'guest_name' => 'Idempotent Guest',
                        'guest_email' => 'idem@example.test',
                        'guest_phone' => '+2348000000103',
                        'idempotency_key' => 'concurrent-idem-key-99',
                    ],
                ],
            ],
        ]);

        $this->assertSame(1, Reservation::where('idempotency_key', 'concurrent-idem-key-99')->count());
        $this->assertSame($idemResults[0]['reference'], $idemResults[1]['reference']);
    }

    public function test_scenario_b_concurrent_hold_race_for_last_inventory(): void
    {
        $this->room102->update(['operational_status' => 'inactive']); // 1 sellable Deluxe room
        $in = now()->addDays(8)->format('Y-m-d');
        $out = now()->addDays(10)->format('Y-m-d');

        $results = $this->runConcurrentWorkers([
            [
                'action' => 'create_hold',
                'params' => [
                    'data' => [
                        'room_type_id' => $this->deluxeType->id,
                        'rate_plan_id' => $this->standardPlan->id,
                        'check_in_date' => $in,
                        'check_out_date' => $out,
                        'adults' => 2,
                        'rooms_count' => 1,
                    ],
                ],
            ],
            [
                'action' => 'create_hold',
                'params' => [
                    'data' => [
                        'room_type_id' => $this->deluxeType->id,
                        'rate_plan_id' => $this->standardPlan->id,
                        'check_in_date' => $in,
                        'check_out_date' => $out,
                        'adults' => 2,
                        'rooms_count' => 1,
                    ],
                ],
            ],
        ]);

        $successes = array_filter($results, fn ($r) => ($r['ok'] ?? false) === true);
        $this->assertCount(1, $successes, 'Only 1 hold may succeed for the final available room.');
        $this->assertSame(1, BookingHold::activeUnexpired()->count());
    }

    public function test_scenario_c_physical_room_concurrent_assignment_race_and_db_unique_constraint(): void
    {
        $in = now()->addDays(11)->format('Y-m-d');
        $out = now()->addDays(13)->format('Y-m-d');

        $resService = app(ReservationService::class);
        $res1 = $resService->createReservation([
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'adults' => 2,
            'rooms_count' => 1,
            'guest_name' => 'Guest One',
            'guest_email' => 'g1@example.test',
            'guest_phone' => '+2348000000201',
        ], $this->manager, isPublic: false);

        $res2 = $resService->createReservation([
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'adults' => 2,
            'rooms_count' => 1,
            'guest_name' => 'Guest Two',
            'guest_email' => 'g2@example.test',
            'guest_phone' => '+2348000000202',
        ], $this->manager, isPublic: false);

        // Concurrently attempt to assign Room 101 to BOTH overlapping reservations
        $results = $this->runConcurrentWorkers([
            [
                'action' => 'assign_room',
                'params' => [
                    'reservation_id' => $res1->id,
                    'room_id' => $this->room101->id,
                    'actor_id' => $this->manager->id,
                ],
            ],
            [
                'action' => 'assign_room',
                'params' => [
                    'reservation_id' => $res2->id,
                    'room_id' => $this->room101->id,
                    'actor_id' => $this->manager->id,
                ],
            ],
        ]);

        $successes = array_filter($results, fn ($r) => ($r['ok'] ?? false) === true);
        $this->assertCount(1, $successes, 'Only one reservation may be assigned Room 101: '.json_encode($results));
        $this->assertSame(1, Reservation::where('assigned_room_id', $this->room101->id)->count());

        // Also verify Scenario G: raw SQL cannot bypass the unique index `(active_room_id, stay_date)` on `reservation_nights`
        $unassignedResId = Reservation::whereNull('assigned_room_id')->value('id');
        $this->expectException(QueryException::class);
        DB::table('reservation_nights')
            ->where('reservation_id', $unassignedResId)
            ->update(['room_id' => $this->room101->id, 'active_room_id' => $this->room101->id]);
    }

    public function test_scenario_d_cross_room_type_amendment_race_without_deadlock(): void
    {
        // Both Deluxe (101 active, 102 active = 2 rooms) and Suite (201 active, plus add 202 active = 2 rooms)
        Room::create([
            'room_number' => '202',
            'room_type_id' => $this->suiteType->id,
            'operational_status' => 'active',
            'housekeeping_status' => 'clean',
        ]);

        $in = now()->addDays(14)->format('Y-m-d');
        $out = now()->addDays(16)->format('Y-m-d');

        $resService = app(ReservationService::class);
        // Res A is in Deluxe; Res B is in Suite
        $resA = $resService->createReservation([
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'adults' => 2,
            'rooms_count' => 1,
            'guest_name' => 'Amend Guest A',
            'guest_email' => 'amenda@example.test',
            'guest_phone' => '+2348000000301',
        ], $this->manager, isPublic: false);

        $resB = $resService->createReservation([
            'room_type_id' => $this->suiteType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'adults' => 2,
            'rooms_count' => 1,
            'guest_name' => 'Amend Guest B',
            'guest_email' => 'amendb@example.test',
            'guest_phone' => '+2348000000302',
        ], $this->manager, isPublic: false);

        // Concurrently amend Res A (Deluxe -> Suite) and Res B (Suite -> Deluxe) in opposite directions!
        // Because both lock RoomTypes in ascending ID order, no AB-BA deadlock occurs.
        $results = $this->runConcurrentWorkers([
            [
                'action' => 'amend_reservation',
                'params' => [
                    'reservation_id' => $resA->id,
                    'actor_id' => $this->manager->id,
                    'data' => ['room_type_id' => $this->suiteType->id, 'amendment_reason' => 'Swap to Suite'],
                ],
            ],
            [
                'action' => 'amend_reservation',
                'params' => [
                    'reservation_id' => $resB->id,
                    'actor_id' => $this->manager->id,
                    'data' => ['room_type_id' => $this->deluxeType->id, 'amendment_reason' => 'Swap to Deluxe'],
                ],
            ],
        ]);

        $this->assertTrue($results[0]['ok'] ?? false, 'Amendment A succeeded without deadlock: '.json_encode($results));
        $this->assertTrue($results[1]['ok'] ?? false, 'Amendment B succeeded without deadlock: '.json_encode($results));
        $this->assertSame($this->suiteType->id, $resA->fresh()->room_type_id);
        $this->assertSame($this->deluxeType->id, $resB->fresh()->room_type_id);
    }

    public function test_scenario_e_out_of_service_race_against_room_booking(): void
    {
        // Only Room 101 is active, and 1 unit is already held on a 2-unit RoomType OR Room 101 has a pending hold
        // Let's keep both Room 101 and Room 102 active, with Room 102 already booked by an existing reservation!
        $in = now()->addDays(17)->format('Y-m-d');
        $out = now()->addDays(19)->format('Y-m-d');

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
            'guest_phone' => '+2348000000400',
        ], $this->manager, isPublic: false);

        // Process 1 books the last room while Process 2 tries to take Room 101 out of service
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
                        'guest_name' => 'Race Guest E',
                        'guest_email' => 'racee@example.test',
                        'guest_phone' => '+2348000000401',
                    ],
                ],
            ],
            [
                'action' => 'room_out_of_service',
                'params' => [
                    'room_id' => $this->room101->id,
                    'actor_id' => $this->manager->id,
                    'data' => [
                        'room_number' => '101',
                        'room_type_id' => $this->deluxeType->id,
                        'operational_status' => 'out_of_service',
                        'housekeeping_status' => 'maintenance',
                        'out_of_service_from' => $in,
                        'out_of_service_until' => $out,
                        'out_of_service_reason' => 'Plumbing',
                    ],
                ],
            ],
        ]);

        // Both operations acquire `RoomType::lockForUpdate()`. Exactly one succeeds and the other is blocked!
        $successes = array_filter($results, fn ($r) => ($r['ok'] ?? false) === true);
        $this->assertCount(1, $successes, 'Cannot both confirm the last room AND mark that room out of service: '.json_encode($results));
    }

    public function test_scenario_f_expiry_race_expired_hold_cannot_convert_while_new_booking_succeeds(): void
    {
        $this->room102->update(['operational_status' => 'inactive']); // 1 sellable room
        $in = now()->addDays(20)->format('Y-m-d');
        $out = now()->addDays(22)->format('Y-m-d');

        $resService = app(ReservationService::class);
        $hold = $resService->createHold([
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'adults' => 2,
            'rooms_count' => 1,
        ]);

        // Set hold expires_at to 1 second in the past (simulating hold expiring right as conversion & another booking race)
        BookingHold::where('id', $hold->id)->update(['expires_at' => now()->subSecond()]);

        $results = $this->runConcurrentWorkers([
            [
                'action' => 'convert_hold',
                'params' => [
                    'data' => [
                        'hold_token' => $hold->hold_token,
                        'guest_name' => 'Expired Hold Guest',
                        'guest_email' => 'expired@example.test',
                        'guest_phone' => '+2348000000501',
                    ],
                ],
            ],
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
                        'guest_name' => 'Fresh Booker',
                        'guest_email' => 'fresh@example.test',
                        'guest_phone' => '+2348000000502',
                    ],
                ],
            ],
        ]);

        // Expired hold conversion MUST fail, and Fresh Booker MUST succeed!
        $this->assertFalse($results[0]['ok'] ?? true, 'Expired hold conversion must fail.');
        $this->assertTrue($results[1]['ok'] ?? false, 'Fresh booking must succeed as expired hold releases inventory.');
        $this->assertSame(1, Reservation::count());
        $this->assertSame('Fresh Booker', Reservation::first()->guest_name);
    }

    public function test_concurrent_duplicate_webhooks_and_concurrent_refund_and_reversal_races_plus_db_triggers(): void
    {
        $in = now()->addDays(23)->format('Y-m-d');
        $out = now()->addDays(24)->format('Y-m-d');

        $resService = app(ReservationService::class);
        $res = $resService->createReservation([
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'adults' => 2,
            'rooms_count' => 1,
            'guest_name' => 'Webhook Race Guest',
            'guest_email' => 'whrace@example.test',
            'guest_phone' => '+2348000000601',
        ], $this->manager, isPublic: false);

        $payload = [
            'event' => 'charge.success',
            'data' => [
                'id' => 888999,
                'status' => 'success',
                'reference' => 'PSK-RACE-888999',
                'amount' => $res->total_minor,
                'currency' => 'NGN',
                'metadata' => ['reservation_reference' => $res->reference],
            ],
        ];
        $raw = json_encode($payload);
        $secret = 'sk_test_race_secret';
        $sig = hash_hmac('sha512', $raw, $secret);
        $mockVerify = [
            'status' => true,
            'data' => $payload['data'],
        ];

        // 1. Simultaneous duplicate webhook callbacks (Payment Test #8)
        $whResults = $this->runConcurrentWorkers([
            [
                'action' => 'webhook_paystack',
                'params' => ['secret_key' => $secret, 'raw' => $raw, 'sig' => $sig, 'payload' => $payload, 'mock_verify' => $mockVerify],
            ],
            [
                'action' => 'webhook_paystack',
                'params' => ['secret_key' => $secret, 'raw' => $raw, 'sig' => $sig, 'payload' => $payload, 'mock_verify' => $mockVerify],
            ],
        ]);

        // Only 1 PaymentTransaction row is created in the database
        $this->assertSame(1, PaymentTransaction::where('reservation_id', $res->id)->count(), 'Simultaneous webhooks must create only 1 ledger row: '.json_encode($whResults));
        $tx = PaymentTransaction::where('reservation_id', $res->id)->firstOrFail();

        // 2. Concurrent refund requests (Payment Test #12): two 70% refund requests race simultaneously
        $seventyPercentMajor = round($res->total_amount * 0.7, 2);
        $refundRace = $this->runConcurrentWorkers([
            [
                'action' => 'request_refund',
                'params' => [
                    'reservation_id' => $res->id,
                    'actor_id' => $this->manager->id,
                    'data' => ['amount' => $seventyPercentMajor, 'payout_method' => 'bank_transfer', 'reason' => 'Race refund 1'],
                ],
            ],
            [
                'action' => 'request_refund',
                'params' => [
                    'reservation_id' => $res->id,
                    'actor_id' => $this->manager->id,
                    'data' => ['amount' => $seventyPercentMajor, 'payout_method' => 'bank_transfer', 'reason' => 'Race refund 2'],
                ],
            ],
        ]);

        $refundSuccesses = array_filter($refundRace, fn ($r) => ($r['ok'] ?? false) === true);
        $this->assertCount(1, $refundSuccesses, 'Concurrent 70% refund requests must allow only 1 to succeed.');
        $pendingRefund = PaymentRefund::where('reservation_id', $res->id)->firstOrFail();

        // 3. Refund approval racing with payment reversal (Payment Test #13)
        $approvalVsReversal = $this->runConcurrentWorkers([
            [
                'action' => 'approve_refund',
                'params' => [
                    'refund_id' => $pendingRefund->id,
                    'actor_id' => $this->accountant->id,
                ],
            ],
            [
                'action' => 'reverse_payment',
                'params' => [
                    'transaction_id' => $tx->id,
                    'actor_id' => $this->accountant->id,
                ],
            ],
        ]);

        // Because a 70% refund already exists, the full reversal is blocked and the refund approval succeeds!
        $this->assertTrue($approvalVsReversal[0]['ok'] ?? false, 'Refund approval succeeded.');
        $this->assertFalse($approvalVsReversal[1]['ok'] ?? true, 'Full payment reversal blocked when refund exists.');

        // 4. Verify MariaDB BEFORE UPDATE and BEFORE DELETE triggers block raw Query Builder mutation/deletion on payment_transactions
        try {
            DB::table('payment_transactions')->where('id', $tx->id)->update(['amount_minor' => 1]);
            $this->fail('Expected MariaDB trigger to reject raw UPDATE on payment_transactions.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable at the database level', $e->getMessage());
        }

        try {
            DB::table('payment_transactions')->where('id', $tx->id)->delete();
            $this->fail('Expected MariaDB trigger to reject raw DELETE on payment_transactions.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('cannot be deleted at the database level', $e->getMessage());
        }
    }
}
