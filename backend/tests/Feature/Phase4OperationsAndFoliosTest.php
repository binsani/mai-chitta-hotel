<?php

namespace Tests\Feature;

use App\Models\FolioEntry;
use App\Models\GuestFolio;
use App\Models\HousekeepingTask;
use App\Models\MaintenanceWorkOrder;
use App\Models\OperationalNotification;
use App\Models\RatePlan;
use App\Models\RatePlanRoomType;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use App\Services\FolioService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use LogicException;
use Tests\TestCase;

class Phase4OperationsAndFoliosTest extends TestCase
{
    use RefreshDatabase;

    private RoomType $deluxeType;
    private Room $room101;
    private Room $room102;
    private RatePlan $standardPlan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withHeaders(['Referer' => 'http://localhost:8000', 'Origin' => 'http://localhost:8000']);
        Notification::fake();

        Setting::updateOrCreate(['group' => 'booking', 'key' => 'online_booking_enabled'], ['value' => true, 'is_public' => true]);
        Setting::updateOrCreate(['group' => 'booking', 'key' => 'tax_percentage'], ['value' => '7.50', 'is_public' => true]);
        Setting::updateOrCreate(['group' => 'booking', 'key' => 'service_charge_percentage'], ['value' => '5.00', 'is_public' => true]);
        Setting::updateOrCreate(['group' => 'booking', 'key' => 'block_dirty_room_checkin'], ['value' => true, 'is_public' => false]);
        Setting::updateOrCreate(['group' => 'booking', 'key' => 'require_inspected_room_for_checkin'], ['value' => false, 'is_public' => false]);
        Setting::updateOrCreate(['group' => 'operations', 'key' => 'auto_create_checkout_cleaning_task'], ['value' => true, 'is_public' => false]);
        Setting::updateOrCreate(['group' => 'operations', 'key' => 'folio_adjustment_approval_threshold_minor'], ['value' => 500000, 'is_public' => false]); // 5,000 NGN
        Cache::forget('settings.all');

        $this->deluxeType = RoomType::create([
            'name' => 'Deluxe King Room',
            'slug' => 'deluxe-king-room',
            'code' => 'DLX',
            'max_adults' => 2,
            'max_children' => 1,
            'max_occupancy' => 3,
            'base_occupancy' => 2,
            'bed_configuration' => '1 King Bed',
            'starting_rate_minor' => 4000000,
            'starting_rate' => 40000.00,
            'currency_code' => 'NGN',
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->room101 = Room::create([
            'room_number' => '101',
            'room_type_id' => $this->deluxeType->id,
            'floor' => '1st Floor',
            'operational_status' => 'active',
            'housekeeping_status' => 'clean',
        ]);

        $this->room102 = Room::create([
            'room_number' => '102',
            'room_type_id' => $this->deluxeType->id,
            'floor' => '1st Floor',
            'operational_status' => 'active',
            'housekeeping_status' => 'clean',
        ]);

        $this->standardPlan = RatePlan::create([
            'name' => 'Flexible Standard Rate',
            'code' => 'BAR',
            'meal_Basis' => 'breakfast_included',
            'cancellation_policy_type' => 'flexible',
            'free_cancellation_hours' => 0,
            'cancellation_penalty_type' => 'first_night',
            'cancellation_penalty_value' => 0,
            'currency_code' => 'NGN',
            'default_base_rate_minor' => 4000000, // 40,000 NGN
            'default_base_rate' => 40000.00,
            'min_stay_nights' => 1,
            'max_stay_nights' => 14,
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
    }

    private function userWithRole(string $slug, array $attrs = []): User
    {
        $user = User::factory()->create($attrs + ['password' => 'Secret-pass-123']);
        $user->roles()->attach(Role::where('slug', $slug)->value('id'), ['created_at' => now()]);

        return $user;
    }

    public function test_checkout_triggers_dirty_room_and_cleaning_task_and_full_housekeeping_inspection_workflow(): void
    {
        $receptionist = $this->userWithRole('receptionist');
        $hkSupervisor = $this->userWithRole('housekeeping-supervisor');
        $housekeeper1 = $this->userWithRole('housekeeper', ['name' => 'Aisha Cleaner']);
        $housekeeper2 = $this->userWithRole('housekeeper', ['name' => 'Bello Cleaner']);

        $in = now()->format('Y-m-d');
        $out = now()->addDays(2)->format('Y-m-d');

        // 1. Create reservation, check in, and check out
        $resResp = $this->actingAs($receptionist)->postJson('/api/v1/admin/reservations', [
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'assigned_room_id' => $this->room101->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'adults' => 2,
            'rooms_count' => 1,
            'source' => 'walk_in',
            'guest_name' => 'Alhaji Sani',
            'guest_email' => 'sani@example.test',
            'guest_phone' => '+2348000000101',
        ])->assertCreated();

        $resId = $resResp->json('data.id');

        $this->actingAs($receptionist)->postJson("/api/v1/admin/reservations/{$resId}/check-in", [])
            ->assertOk();

        $this->actingAs($receptionist)->postJson("/api/v1/admin/reservations/{$resId}/check-out", [])
            ->assertOk();

        // Verify Room 101 is now dirty (`awaiting_cleaning`) and a `checkout_cleaning` HousekeepingTask was created automatically
        $this->assertSame('dirty', $this->room101->fresh()->housekeeping_status);
        $this->assertSame('awaiting_cleaning', $this->room101->fresh()->readiness_status);

        $task = HousekeepingTask::where('room_id', $this->room101->id)
            ->where('reservation_id', $resId)
            ->where('task_type', 'checkout_cleaning')
            ->first();
        $this->assertNotNull($task);
        $this->assertSame('pending', $task->status);

        // 2. Trying to check a new guest into dirty Room 101 is blocked!
        $res2Resp = $this->actingAs($receptionist)->postJson('/api/v1/admin/reservations', [
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'assigned_room_id' => $this->room101->id,
            'check_in_date' => $out,
            'check_out_date' => now()->addDays(4)->format('Y-m-d'),
            'adults' => 1,
            'rooms_count' => 1,
            'source' => 'walk_in',
            'guest_name' => 'Next Guest',
            'guest_email' => 'next@example.test',
            'guest_phone' => '+2348000000102',
        ])->assertCreated();
        $res2Id = $res2Resp->json('data.id');

        $this->actingAs($receptionist)->postJson("/api/v1/admin/reservations/{$res2Id}/check-in", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assigned_room_id']);

        // 3. Housekeeper 1 cannot assign tasks (`housekeeping.assign` required)
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAs($housekeeper1)->postJson("/api/v1/admin/housekeeping/tasks/{$task->id}/assign", [
            'assigned_to' => $housekeeper1->id,
        ])->assertForbidden();

        // 4. Housekeeping Supervisor assigns task to Housekeeper 1 (`pending` -> `assigned`)
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAs($hkSupervisor)->postJson("/api/v1/admin/housekeeping/tasks/{$task->id}/assign", [
            'assigned_to' => $housekeeper1->id,
            'notes' => 'Priority departure turnover',
        ])->assertOk()
            ->assertJsonPath('data.status', 'assigned');

        // 5. IDOR check: Housekeeper 2 cannot start or complete a task assigned to Housekeeper 1
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAs($housekeeper2)->postJson("/api/v1/admin/housekeeping/tasks/{$task->id}/complete", [
            'completion_notes' => 'Tried to hijack task',
        ])->assertUnprocessable()->assertJsonValidationErrors(['assigned_to']);

        // 6. Housekeeper 1 starts (`assigned` -> `in_progress`) and completes (`in_progress` -> `completed`) the task
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAs($housekeeper1)->postJson("/api/v1/admin/housekeeping/tasks/{$task->id}/start", [])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        $this->actingAs($housekeeper1)->postJson("/api/v1/admin/housekeeping/tasks/{$task->id}/complete", [
            'completion_notes' => 'Changed linens and sanitized bathroom.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'completed');

        // Room is now in `inspecting` (`awaiting_inspection`), still blocked from check-in!
        $this->assertSame('inspecting', $this->room101->fresh()->housekeeping_status);
        $this->assertSame('awaiting_inspection', $this->room101->fresh()->readiness_status);

        // Housekeeper 1 cannot self-inspect (`housekeeping.inspect` required)
        $this->actingAs($housekeeper1)->postJson("/api/v1/admin/housekeeping/tasks/{$task->id}/inspect", [
            'outcome' => 'passed',
        ])->assertForbidden();

        // 7. Supervisor fails the first inspection (`completed` -> `failed_inspection`)
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $failResp = $this->actingAs($hkSupervisor)->postJson("/api/v1/admin/housekeeping/tasks/{$task->id}/inspect", [
            'outcome' => 'failed',
            'notes' => 'Mirror still has smudges; please re-clean.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'failed_inspection');

        // Room immediately returns to `dirty` and a corrective follow-up task (`inspection_followup`) is created!
        $this->assertSame('dirty', $this->room101->fresh()->housekeeping_status);
        $followupTask = HousekeepingTask::where('parent_task_id', $task->id)->first();
        $this->assertNotNull($followupTask);
        $this->assertSame('inspection_followup', $followupTask->task_type);
        $this->assertSame('assigned', $followupTask->status);
        $this->assertSame($housekeeper1->id, $followupTask->assigned_to);

        // 8. Housekeeper 1 completes the corrective task, and Supervisor passes inspection (`completed` -> `inspected`)
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAs($housekeeper1)->postJson("/api/v1/admin/housekeeping/tasks/{$followupTask->id}/complete", [
            'completion_notes' => 'Mirror polished and room re-checked.',
        ])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAs($hkSupervisor)->postJson("/api/v1/admin/housekeeping/tasks/{$followupTask->id}/inspect", [
            'outcome' => 'passed',
            'notes' => 'Spotless and ready for guest.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'inspected');

        $this->assertSame('inspected', $this->room101->fresh()->housekeeping_status);
        $this->assertSame('ready', $this->room101->fresh()->readiness_status);
        $this->assertNotNull($this->room101->fresh()->last_inspected_at);

        // 9. Now Receptionist can check Next Guest into Room 101!
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAs($receptionist)->postJson("/api/v1/admin/reservations/{$res2Id}/check-in", [])
            ->assertOk()
            ->assertJsonPath('data.status', 'checked_in');
    }

    public function test_maintenance_work_order_lifecycle_arrival_conflict_cost_permissions_and_safe_return_to_service(): void
    {
        $receptionist = $this->userWithRole('receptionist');
        $maintSupervisor = $this->userWithRole('maintenance-supervisor');
        $technician1 = $this->userWithRole('maintenance-technician', ['name' => 'Musa Tech']);
        $technician2 = $this->userWithRole('maintenance-technician', ['name' => 'Dauda Tech']);
        $hkSupervisor = $this->userWithRole('housekeeping-supervisor');

        $in = now()->addDays(2)->format('Y-m-d');
        $out = now()->addDays(4)->format('Y-m-d');

        // 1. Create a confirmed reservation assigned to Room 101
        $resResp = $this->actingAs($receptionist)->postJson('/api/v1/admin/reservations', [
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'assigned_room_id' => $this->room101->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'adults' => 2,
            'rooms_count' => 1,
            'source' => 'phone',
            'guest_name' => 'Prof. Abdullahi',
            'guest_email' => 'prof@example.test',
            'guest_phone' => '+2348000000201',
        ])->assertCreated();
        $resId = $resResp->json('data.id');

        // 2. Receptionist reports an urgent room-blocking maintenance issue on Room 101
        // It must NOT invalidate the confirmed reservation, but must flag `has_arrival_conflict = true` and create a front-desk alert!
        $woResp = $this->actingAs($receptionist)->postJson('/api/v1/admin/maintenance/work-orders', [
            'room_id' => $this->room101->id,
            'category' => 'hvac',
            'priority' => 'emergency',
            'title' => 'Split AC compressor failure',
            'description' => 'AC blowing warm air and leaking water.',
            'blocks_room' => true,
            'blocked_from' => now()->format('Y-m-d'),
        ])->assertCreated();

        $woId = $woResp->json('data.id');
        $this->assertTrue($woResp->json('data.blocks_room'));
        $this->assertTrue($woResp->json('data.has_arrival_conflict'));
        $this->assertContains($resId, $woResp->json('data.conflicting_reservation_ids'));
        $this->assertSame('confirmed', Reservation::find($resId)->status);
        $this->assertSame('maintenance', $this->room101->fresh()->housekeeping_status);
        $this->assertSame('blocked_by_maintenance', $this->room101->fresh()->readiness_status);
        $this->assertTrue(OperationalNotification::where('type', 'maintenance.arrival_conflict')->exists());

        // 3. Trying to check in to Room 101 while blocked by maintenance is rejected
        $this->actingAs($receptionist)->postJson("/api/v1/admin/reservations/{$resId}/check-in", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assigned_room_id']);

        // 4. Maintenance Supervisor assigns Technician 1 (`reported` -> `assigned`)
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAs($maintSupervisor)->postJson("/api/v1/admin/maintenance/work-orders/{$woId}/assign", [
            'assigned_to' => $technician1->id,
        ])->assertOk()
            ->assertJsonPath('data.status', 'assigned');

        // 5. IDOR check: Technician 2 cannot update a work order assigned to Technician 1
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAs($technician2)->putJson("/api/v1/admin/maintenance/work-orders/{$woId}", [
            'status' => 'in_progress',
        ])->assertUnprocessable()->assertJsonValidationErrors(['assigned_to']);

        // 6. Technician 1 transitions `assigned` -> `in_progress` -> `awaiting_parts` -> `resolved` and records costs
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAs($technician1)->putJson("/api/v1/admin/maintenance/work-orders/{$woId}", [
            'status' => 'in_progress',
            'diagnosis_notes' => 'Capacitor and contactor burnt.',
        ])->assertOk()->assertJsonPath('data.status', 'in_progress');

        $this->actingAs($technician1)->putJson("/api/v1/admin/maintenance/work-orders/{$woId}", [
            'status' => 'awaiting_parts',
            'parts_wait_notes' => 'Ordered 45uF capacitor from Sabon Gari supplier.',
        ])->assertOk()->assertJsonPath('data.status', 'awaiting_parts');

        // Resolving without resolution_notes fails validation
        $this->actingAs($technician1)->putJson("/api/v1/admin/maintenance/work-orders/{$woId}", [
            'status' => 'resolved',
        ])->assertUnprocessable()->assertJsonValidationErrors(['resolution_notes']);

        // Resolving with resolution_notes and costs succeeds
        $techResolveResp = $this->actingAs($technician1)->putJson("/api/v1/admin/maintenance/work-orders/{$woId}", [
            'status' => 'resolved',
            'resolution_notes' => 'Replaced capacitor and contactor; tested cooling for 45 mins.',
            'parts_cost' => 18500.00,
            'labor_cost' => 6500.00,
            'vendor_name' => 'Zaria Cooling Supplies Ltd',
        ])->assertOk()->assertJsonPath('data.status', 'resolved');

        // Cost visibility check: Technician 1 does NOT have `maintenance.costs.view`, so cost fields are hidden from their response!
        $this->assertArrayNotHasKey('total_cost', $techResolveResp->json('data'));

        // Technician 1 cannot close the work order (`maintenance.close` required)
        $this->actingAs($technician1)->putJson("/api/v1/admin/maintenance/work-orders/{$woId}", [
            'status' => 'closed',
        ])->assertForbidden();

        // 7. Verify Safe Return-to-Service: resolving the maintenance work order transitioned Room 101 from `maintenance` to `dirty`
        // (NOT `clean`!), and automatically created a `maintenance_followup` housekeeping task!
        $this->assertSame('dirty', $this->room101->fresh()->housekeeping_status);
        $this->assertSame('awaiting_cleaning', $this->room101->fresh()->readiness_status);
        $woModel = MaintenanceWorkOrder::findOrFail($woId);
        $this->assertNotNull($woModel->followup_housekeeping_task_id);

        // 8. Maintenance Supervisor (has `maintenance.close` and `maintenance.costs.view`) can view costs and close the work order
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $supCloseResp = $this->actingAs($maintSupervisor)->putJson("/api/v1/admin/maintenance/work-orders/{$woId}", [
            'status' => 'closed',
            'notes' => 'Verified repair and invoice.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.total_cost', 25000);

        $this->assertSame(2500000, $supCloseResp->json('data.total_cost_minor'));

        // 9. Room 101 still cannot be checked into until the post-maintenance housekeeping task is completed and inspected!
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAs($receptionist)->postJson("/api/v1/admin/reservations/{$resId}/check-in", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assigned_room_id']);

        // Complete and inspect the post-maintenance housekeeping task
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $hkTaskId = $woModel->followup_housekeeping_task_id;
        $this->actingAs($hkSupervisor)->postJson("/api/v1/admin/housekeeping/tasks/{$hkTaskId}/start", [])->assertOk();
        $this->actingAs($hkSupervisor)->postJson("/api/v1/admin/housekeeping/tasks/{$hkTaskId}/complete", [
            'completion_notes' => 'Cleaned AC dust and sanitized room.',
        ])->assertOk();
        $this->actingAs($hkSupervisor)->postJson("/api/v1/admin/housekeeping/tasks/{$hkTaskId}/inspect", [
            'outcome' => 'passed',
            'notes' => 'Room ready after AC repair.',
        ])->assertOk();

        // Now Room 101 is `inspected` (`ready`) and check-in succeeds!
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAs($receptionist)->postJson("/api/v1/admin/reservations/{$resId}/check-in", [])
            ->assertOk()
            ->assertJsonPath('data.status', 'checked_in');
    }

    public function test_guest_folio_accommodation_sync_manual_charges_adjustments_separation_of_duties_immutability_and_settlement(): void
    {
        $receptionist = $this->userWithRole('receptionist');
        $supervisor = $this->userWithRole('front-desk-supervisor');
        $accountant = $this->userWithRole('accountant');

        $in = now()->addDays(1)->format('Y-m-d');
        $out = now()->addDays(3)->format('Y-m-d'); // 2 nights @ 40,000 = 80,000 + 7.5% tax (6,000) + 5% fee (4,000) = 90,000 NGN

        // 1. Create reservation -> automatically initializes GuestFolio and syncs 2 nightly accommodation charges + tax + service charge
        $resResp = $this->actingAs($receptionist)->postJson('/api/v1/admin/reservations', [
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'assigned_room_id' => $this->room101->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'adults' => 2,
            'rooms_count' => 1,
            'source' => 'walk_in',
            'guest_name' => 'Hajiya Zainab',
            'guest_email' => 'hajiya@example.test',
            'guest_phone' => '+2348000000301',
        ])->assertCreated();

        $resId = $resResp->json('data.id');
        $folio = GuestFolio::where('reservation_id', $resId)->firstOrFail();

        // Verify idempotent accommodation sync: calling syncAccommodation again does NOT duplicate nightly charges
        $this->actingAs($receptionist)->postJson("/api/v1/admin/folios/{$folio->id}/sync-accommodation", [])
            ->assertOk();

        $folio->refresh();
        $this->assertSame(9000000, $folio->total_charges_minor); // 90,000.00 NGN
        $this->assertSame(9000000, $folio->balance_due_minor);
        $this->assertSame(2, FolioEntry::where('guest_folio_id', $folio->id)->where('entry_type', 'accommodation')->count());

        // 2. Receptionist posts a manual laundry charge of 4,500 NGN (with idempotency key)
        $chargeResp = $this->actingAs($receptionist)->postJson("/api/v1/admin/folios/{$folio->id}/charges", [
            'category' => 'laundry',
            'description' => 'Express dry cleaning (3 garments)',
            'quantity' => 3,
            'unit_amount' => 1500.00,
            'idempotency_key' => 'laundry-idem-001',
        ])->assertCreated();

        $laundryEntryId = $chargeResp->json('data.id');

        // Duplicate charge submission with same idempotency_key returns existing entry without double-charging
        $this->actingAs($receptionist)->postJson("/api/v1/admin/folios/{$folio->id}/charges", [
            'category' => 'laundry',
            'description' => 'Express dry cleaning (3 garments)',
            'quantity' => 3,
            'unit_amount' => 1500.00,
            'idempotency_key' => 'laundry-idem-001',
        ])->assertCreated()->assertJsonPath('data.id', $laundryEntryId);

        $folio->refresh();
        $this->assertSame(9450000, $folio->total_charges_minor); // 94,500.00 NGN

        // 3. Verify posted FolioEntry immutability: direct update or deletion throws LogicException
        $laundryEntry = FolioEntry::findOrFail($laundryEntryId);
        try {
            $laundryEntry->update(['total_amount_minor' => 100]);
            $this->fail('Expected LogicException when mutating a posted FolioEntry.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        try {
            $laundryEntry->delete();
            $this->fail('Expected LogicException when deleting a FolioEntry.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('cannot be deleted', $e->getMessage());
        }

        // 4. Separation of duties on adjustments:
        // Receptionist (no `folios.adjust`) requests a 2,500 NGN discount -> created as `pending_approval` (does not reduce balance yet)
        $adjReq = $this->actingAs($receptionist)->postJson("/api/v1/admin/folios/{$folio->id}/adjustments", [
            'entry_type' => 'discount',
            'description' => 'Courtesy welcome discount',
            'amount' => 2500.00,
            'reason' => 'Guest waited 15 mins at check-in',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending_approval');

        $adjEntryId = $adjReq->json('data.id');
        $this->assertSame(9450000, $folio->fresh()->balance_due_minor);

        // Receptionist cannot approve the adjustment
        $this->actingAs($receptionist)->postJson("/api/v1/admin/folio-entries/{$adjEntryId}/review", [
            'decision' => 'approve',
        ])->assertForbidden();

        // Front Desk Supervisor (has `folios.adjust`) approves the 2,500 NGN discount -> status becomes `posted` and balance reduces to 92,000 NGN
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAs($supervisor)->postJson("/api/v1/admin/folio-entries/{$adjEntryId}/review", [
            'decision' => 'approve',
            'review_notes' => 'Approved within supervisor threshold',
        ])->assertOk()
            ->assertJsonPath('data.status', 'posted');

        $folio->refresh();
        $this->assertSame(250000, $folio->total_credits_minor);
        $this->assertSame(9200000, $folio->balance_due_minor); // 92,000.00 NGN

        // 5. Supervisor requests a large credit adjustment (10,000 NGN > 5,000 NGN threshold):
        // Because Supervisor does not hold `finance.approve` or `folios.refund`, it is queued as `pending_approval`
        // AND Supervisor is blocked from self-approving their own request!
        $largeAdjResp = $this->actingAs($supervisor)->postJson("/api/v1/admin/folios/{$folio->id}/adjustments", [
            'entry_type' => 'allowance',
            'description' => 'Large goodwill allowance',
            'amount' => 10000.00,
            'reason' => 'Above supervisor threshold test',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending_approval');

        $largeAdjId = $largeAdjResp->json('data.id');

        // Supervisor trying to self-approve their own pending adjustment is rejected with 422!
        $this->actingAs($supervisor)->postJson("/api/v1/admin/folio-entries/{$largeAdjId}/review", [
            'decision' => 'approve',
        ])->assertUnprocessable()->assertJsonValidationErrors(['decision']);

        // Accountant (different user with `folios.adjust` & `finance.approve`) rejects the 10,000 NGN adjustment
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAs($accountant)->postJson("/api/v1/admin/folio-entries/{$largeAdjId}/review", [
            'decision' => 'reject',
            'review_notes' => 'Excessive allowance; rejected.',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

        // 6. Reverse a posted entry via append-only contra entry:
        // Accountant reverses the 4,500 NGN laundry charge -> creates a `reversal` entry (-4,500 NGN) and reduces charges back to 90,000 NGN (balance due = 87,500 NGN)
        $this->actingAs($accountant)->postJson("/api/v1/admin/folio-entries/{$laundryEntryId}/reverse", [
            'reason' => 'Posted to wrong room',
        ])->assertCreated()
            ->assertJsonPath('data.entry_type', 'reversal')
            ->assertJsonPath('data.total_amount_minor', -450000);

        // Attempting to reverse the same entry a second time is rejected
        $this->actingAs($accountant)->postJson("/api/v1/admin/folio-entries/{$laundryEntryId}/reverse", [
            'reason' => 'Duplicate reversal attempt',
        ])->assertUnprocessable()->assertJsonValidationErrors(['entry']);

        $folio->refresh();
        $this->assertSame(9000000, $folio->total_charges_minor);
        $this->assertSame(250000, $folio->total_credits_minor);
        $this->assertSame(8750000, $folio->balance_due_minor); // 87,500.00 NGN

        // 7. Settlement Rules:
        // Attempting to settle & close folio while balance_due_minor > 0 (87,500 NGN) without exception fails with 422
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAs($receptionist)->postJson("/api/v1/admin/folios/{$folio->id}/settle", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['balance_due']);

        // Receptionist attempting to use `allow_exception = true` is forbidden (403)
        $this->actingAs($receptionist)->postJson("/api/v1/admin/folios/{$folio->id}/settle", [
            'allow_exception' => true,
            'settlement_exception_reason' => 'Unauthorized exception attempt',
        ])->assertForbidden();

        // 8. Record verified payment of 87,500 NGN -> auto-allocates to folio and reduces balance_due_minor to 0!
        $this->actingAs($receptionist)->postJson("/api/v1/admin/reservations/{$resId}/payments", [
            'amount' => 87500.00,
            'currency_code' => 'NGN',
            'method' => 'pos',
            'provider_reference' => 'POS-FOLIO-001',
        ])->assertCreated();

        $folio->refresh();
        $this->assertSame(8750000, $folio->total_payments_minor);
        $this->assertSame(0, $folio->balance_due_minor);

        // 9. Now Receptionist settles and closes the zero-balance folio (`open` -> `closed`)
        $this->actingAs($receptionist)->postJson("/api/v1/admin/folios/{$folio->id}/settle", [
            'notes' => 'Guest settled full balance at checkout.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'closed');

        // 10. Posting a charge to a closed folio is blocked
        $this->actingAs($receptionist)->postJson("/api/v1/admin/folios/{$folio->id}/charges", [
            'category' => 'minibar',
            'description' => 'Late minibar item',
            'unit_amount' => 1000.00,
        ])->assertUnprocessable()->assertJsonValidationErrors(['folio']);
    }

    public function test_reservation_amendment_cancellation_and_pos_external_charge_boundary_on_folio(): void
    {
        $receptionist = $this->userWithRole('receptionist');
        $in = now()->format('Y-m-d');
        $out = now()->addDays(2)->format('Y-m-d'); // 2 nights

        // 1. Create reservation (2 nights = 90,000 NGN total with tax & fee)
        $resResp = $this->actingAs($receptionist)->postJson('/api/v1/admin/reservations', [
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'assigned_room_id' => $this->room101->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'adults' => 2,
            'rooms_count' => 1,
            'source' => 'walk_in',
            'guest_name' => 'Dr. Bello',
            'guest_email' => 'bello@example.test',
            'guest_phone' => '+2348000000401',
        ])->assertCreated();

        $resId = $resResp->json('data.id');
        $folio = GuestFolio::where('reservation_id', $resId)->firstOrFail();
        $this->assertSame(9000000, $folio->total_charges_minor);

        // 2. Amend reservation from 2 nights to 3 nights (135,000 NGN total) -> folio automatically updates!
        $this->actingAs($receptionist)->putJson("/api/v1/admin/reservations/{$resId}", [
            'check_out_date' => now()->addDays(3)->format('Y-m-d'),
            'amendment_reason' => 'Extended stay to 3 nights',
        ])->assertOk();

        $folio->refresh();
        $this->assertSame(13500000, $folio->total_charges_minor);

        // 3. Test Phase 5 Restaurant / POS external room-charge service boundary (`FolioService::postTrustedExternalCharge`)
        $reservation = Reservation::findOrFail($resId);
        $folioService = app(FolioService::class);

        // Cannot post POS room charge before guest is checked in
        try {
            $folioService->postTrustedExternalCharge($reservation, 'pos_order', 'ORD-1001', [
                'description' => 'Restaurant Dinner — Order #ORD-1001',
                'net_amount_minor' => 1200000,
                'tax_amount_minor' => 90000,
            ], $receptionist);
            $this->fail('Expected ValidationException when posting POS charge to a non-checked-in reservation.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('reservation', $e->errors());
        }

        // Check in guest, then post POS charge idempotently
        $this->actingAs($receptionist)->postJson("/api/v1/admin/reservations/{$resId}/check-in", [])->assertOk();
        $reservation->refresh();

        $posEntry1 = $folioService->postTrustedExternalCharge($reservation, 'pos_order', 'ORD-1001', [
            'description' => 'Restaurant Dinner — Order #ORD-1001',
            'net_amount_minor' => 1200000,
            'tax_amount_minor' => 90000,
        ], $receptionist);

        $posEntryDup = $folioService->postTrustedExternalCharge($reservation, 'pos_order', 'ORD-1001', [
            'description' => 'Restaurant Dinner — Order #ORD-1001',
            'net_amount_minor' => 1200000,
            'tax_amount_minor' => 90000,
        ], $receptionist);

        $this->assertSame($posEntry1->id, $posEntryDup->id);
        $this->assertSame(13500000 + 1290000, $folio->fresh()->total_charges_minor);
    }
}
