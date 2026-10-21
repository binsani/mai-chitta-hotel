<?php

namespace Tests\Feature;

use App\Models\BookingHold;
use App\Models\Guest;
use App\Models\PaymentRefund;
use App\Models\RatePlan;
use App\Models\RatePlanOverride;
use App\Models\RatePlanRoomType;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class Phase3ReservationsTest extends TestCase
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

        // Enable online booking in settings for public tests
        Setting::updateOrCreate(['group' => 'booking', 'key' => 'online_booking_enabled'], ['value' => true, 'is_public' => true]);
        Setting::updateOrCreate(['group' => 'booking', 'key' => 'tax_percentage'], ['value' => '7.50', 'is_public' => true]);
        Setting::updateOrCreate(['group' => 'booking', 'key' => 'service_charge_percentage'], ['value' => '5.00', 'is_public' => true]);
        \Illuminate\Support\Facades\Cache::forget('settings.all');

        $this->deluxeType = RoomType::create([
            'name' => 'Deluxe King Room',
            'slug' => 'deluxe-king-room',
            'code' => 'DLX',
            'max_adults' => 2,
            'max_children' => 1,
            'max_occupancy' => 3,
            'base_occupancy' => 2,
            'bed_configuration' => '1 King Bed',
            'starting_rate_minor' => 4000000, // NGN 40,000.00
            'starting_rate' => 40000.00,
            'currency_code' => 'NGN',
            'is_active' => true,
            'is_public' => true,
            'housekeeping_notes' => 'CONFIDENTIAL HK NOTE',
            'operational_notes' => 'CONFIDENTIAL OPS NOTE',
        ]);

        $this->room101 = Room::create([
            'room_number' => '101',
            'room_type_id' => $this->deluxeType->id,
            'floor' => '1st Floor',
            'operational_status' => 'active',
            'housekeeping_status' => 'clean',
            'internal_notes' => 'SECRET ROOM 101 INTERNAL NOTE',
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
            'free_cancellation_hours' => 24,
            'cancellation_penalty_type' => 'first_night',
            'cancellation_penalty_value' => 0,
            'cancellation_terms' => 'Free cancellation up to 24 hours before check-in; first night charged thereafter.',
            'currency_code' => 'NGN',
            'default_base_rate_minor' => 4000000,
            'default_base_rate' => 40000.00,
            'extra_adult_rate_minor' => 1000000,
            'extra_adult_rate' => 10000.00,
            'extra_child_rate_minor' => 500000,
            'extra_child_rate' => 5000.00,
            'min_stay_nights' => 1,
            'max_stay_nights' => 14,
            'is_active' => true,
            'is_public' => true,
            'is_refundable' => true,
        ]);

        RatePlanRoomType::create([
            'rate_plan_id' => $this->standardPlan->id,
            'room_type_id' => $this->deluxeType->id,
            'base_rate_minor' => 4000000, // 40,000 NGN
            'base_rate' => 40000.00,
            'extra_adult_rate_minor' => 1000000,
            'extra_adult_rate' => 10000.00,
            'extra_child_rate_minor' => 500000,
            'extra_child_rate' => 5000.00,
            'weekday_rates_minor' => ['fri' => 4500000, 'sat' => 4500000], // Weekend rate 45,000 NGN
            'weekday_rates' => ['fri' => 45000.00, 'sat' => 45000.00],
            'is_active' => true,
        ]);
    }

    private function userWithRole(string $slug, array $attrs = []): User
    {
        $user = User::factory()->create($attrs + ['password' => 'Secret-pass-123']);
        $user->roles()->attach(Role::where('slug', $slug)->value('id'), ['created_at' => now()]);

        return $user;
    }

    public function test_search_with_valid_and_invalid_dates_and_no_internal_notes_leaked(): void
    {
        $checkIn = now()->addDays(5)->format('Y-m-d');
        $checkOut = now()->addDays(7)->format('Y-m-d');

        // Invalid: check-out before or equal to check-in
        $this->getJson("/api/v1/public/availability?check_in_date={$checkIn}&check_out_date={$checkIn}&adults=2")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['check_out_date']);

        // Invalid: past check-in date
        $past = now()->subDays(2)->format('Y-m-d');
        $this->getJson("/api/v1/public/availability?check_in_date={$past}&check_out_date={$checkIn}&adults=2")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['check_in_date']);

        // Valid search
        $res = $this->getJson("/api/v1/public/availability?check_in_date={$checkIn}&check_out_date={$checkOut}&adults=2")
            ->assertOk();

        $res->assertJsonPath('data.search.nights', 2)
            ->assertJsonPath('data.options.0.room_type.slug', 'deluxe-king-room')
            ->assertJsonPath('data.options.0.available_rooms', 2);

        $raw = $res->getContent();
        $this->assertStringNotContainsString('CONFIDENTIAL HK NOTE', $raw);
        $this->assertStringNotContainsString('CONFIDENTIAL OPS NOTE', $raw);
        $this->assertStringNotContainsString('SECRET ROOM 101 INTERNAL NOTE', $raw);
    }

    public function test_checkin_inclusive_checkout_exclusive_and_same_day_turnover(): void
    {
        // Stay A: Nov 10 to Nov 12 (2 nights: Nov 10, Nov 11) for 2 rooms (consumes all 2 rooms)
        $inA = now()->addDays(10)->format('Y-m-d');
        $outA = now()->addDays(12)->format('Y-m-d');
        $outB = now()->addDays(14)->format('Y-m-d');

        $this->postJson('/api/v1/public/reservations', [
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'check_in_date' => $inA,
            'check_out_date' => $outA,
            'adults' => 2,
            'rooms_count' => 2,
            'guest_name' => 'Amina Yusuf',
            'guest_email' => 'amina@example.test',
            'guest_phone' => '+2348000000001',
        ])->assertCreated()
            ->assertJsonPath('data.nights', 2);

        // Overlapping dates [inA..outA] have 0 rooms left
        $this->getJson("/api/v1/public/availability?check_in_date={$inA}&check_out_date={$outA}&adults=2")
            ->assertOk()
            ->assertJsonCount(0, 'data.options');

        // Same-day turnover: Guest B checks in on $outA (when Guest A checks out) -> all 2 rooms are available!
        $this->getJson("/api/v1/public/availability?check_in_date={$outA}&check_out_date={$outB}&adults=2")
            ->assertOk()
            ->assertJsonPath('data.options.0.available_rooms', 2);
    }

    public function test_room_occupancy_and_capacity_restrictions(): void
    {
        $in = now()->addDays(4)->format('Y-m-d');
        $out = now()->addDays(6)->format('Y-m-d');

        // Deluxe King allows max_adults=2, max_children=1, max_occupancy=3 per room
        $this->postJson('/api/v1/public/booking-quotes', [
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'adults' => 3, // exceeds max_adults=2 for 1 room
            'children' => 0,
            'rooms_count' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors(['adults']);

        // 2 adults + 1 child (total 3 <= max_occupancy 3) succeeds and includes extra_child_rate (5,000 NGN/night)
        $this->postJson('/api/v1/public/booking-quotes', [
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'adults' => 2,
            'children' => 1,
            'rooms_count' => 1,
        ])->assertOk()
            ->assertJsonPath('data.nightly_breakdown.0.extra_guest_minor', 500000);
    }

    public function test_out_of_service_room_exclusion_and_protection_against_invalidating_confirmed_stays(): void
    {
        $in = now()->addDays(15)->format('Y-m-d');
        $out = now()->addDays(17)->format('Y-m-d');

        // Put room 102 out of service overlapping [$in, $out]
        $this->room102->update([
            'operational_status' => 'out_of_service',
            'out_of_service_from' => $in,
            'out_of_service_until' => $out,
            'out_of_service_reason' => 'AC compressor replacement',
        ]);

        // Only 1 room (room 101) should now be available
        $this->getJson("/api/v1/public/availability?check_in_date={$in}&check_out_date={$out}&adults=2")
            ->assertOk()
            ->assertJsonPath('data.options.0.available_rooms', 1);

        // Book that last remaining room
        $this->postJson('/api/v1/public/reservations', [
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'adults' => 2,
            'rooms_count' => 1,
            'guest_name' => 'Ibrahim Musa',
            'guest_email' => 'ibrahim@example.test',
            'guest_phone' => '+2348000000002',
        ])->assertCreated();

        // Now try to put Room 101 out of service as Hotel Manager -> must be blocked because 1 confirmed reservation needs it!
        $manager = $this->userWithRole('hotel-manager');
        $this->actingAs($manager)->putJson("/api/v1/admin/rooms/{$this->room101->id}", [
            'room_number' => '101',
            'room_type_id' => $this->deluxeType->id,
            'operational_status' => 'out_of_service',
            'housekeeping_status' => 'maintenance',
            'out_of_service_from' => $in,
            'out_of_service_until' => $out,
        ])->assertUnprocessable()->assertJsonValidationErrors(['operational_status']);
    }

    public function test_deterministic_pricing_precedence_and_client_price_tampering_ignored(): void
    {
        // Choose a Monday check-in for 3 nights (Mon, Tue, Wed) using CarbonImmutable so addDay() does not mutate $monday
        $monday = \Carbon\CarbonImmutable::now()->next('Monday')->addWeeks(2);
        $in = $monday->format('Y-m-d');
        $tuesday = $monday->addDay()->format('Y-m-d');
        $wednesday = $monday->addDays(2)->format('Y-m-d');
        $out = $monday->addDays(3)->format('Y-m-d');

        // Add a seasonal date_range override covering Mon..Wed at 50,000 NGN
        RatePlanOverride::create([
            'rate_plan_id' => $this->standardPlan->id,
            'room_type_id' => $this->deluxeType->id,
            'label' => 'Zaria Convocation Week',
            'override_type' => 'date_range',
            'start_date' => $in,
            'end_date' => $wednesday,
            'nightly_rate_minor' => 5000000, // 50,000 NGN
            'nightly_rate' => 50000.00,
            'priority' => 10,
        ]);

        // Add an exact_date override on Tuesday at 60,000 NGN (must win over seasonal date_range!)
        RatePlanOverride::create([
            'rate_plan_id' => $this->standardPlan->id,
            'room_type_id' => $this->deluxeType->id,
            'label' => 'Peak Tuesday Override',
            'override_type' => 'exact_date',
            'start_date' => $tuesday,
            'end_date' => $tuesday,
            'nightly_rate_minor' => 6000000, // 60,000 NGN
            'nightly_rate' => 60000.00,
            'priority' => 10,
        ]);

        // Expected subtotal: Mon (50,000) + Tue (60,000) + Wed (50,000) = 160,000.00 NGN (16,000,000 kobo)
        // Tax 7.5% = 12,000.00 NGN; Service Charge 5.0% = 8,000.00 NGN; Total = 180,000.00 NGN
        $res = $this->postJson('/api/v1/public/reservations', [
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'adults' => 2,
            'rooms_count' => 1,
            'guest_name' => 'Zainab Bello',
            'guest_email' => 'zainab@example.test',
            'guest_phone' => '+2348000000003',
            // Client attempts to tamper with price fields:
            'total_amount' => 100.00,
            'total_minor' => 10000,
            'subtotal_amount' => 100.00,
        ])->assertCreated();

        $res->assertJsonPath('data.subtotal_amount', 160000)
            ->assertJsonPath('data.taxes_amount', 12000)
            ->assertJsonPath('data.fees_amount', 8000)
            ->assertJsonPath('data.total_amount', 180000)
            ->assertJsonPath('data.price_snapshot.nightly_breakdown.0.pricing_source', 'date_range_override')
            ->assertJsonPath('data.price_snapshot.nightly_breakdown.1.pricing_source', 'exact_date_override');

        // Verify later rate changes do NOT alter the existing reservation's snapshot
        $this->standardPlan->update(['default_base_rate_minor' => 9900000, 'default_base_rate' => 99000.00]);
        $reservation = Reservation::where('reference', $res->json('data.reference'))->firstOrFail();
        $this->assertSame(18000000, $reservation->total_minor);
    }

    public function test_booking_hold_inventory_reservation_expiration_and_idempotency(): void
    {
        $in = now()->addDays(20)->format('Y-m-d');
        $out = now()->addDays(22)->format('Y-m-d');

        // Create Hold #1 for 2 rooms with idempotency_key
        $holdRes1 = $this->postJson('/api/v1/public/booking-holds', [
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'adults' => 2,
            'rooms_count' => 2,
            'idempotency_key' => 'hold-idem-key-001',
        ])->assertCreated();

        $token1 = $holdRes1->json('data.hold_token');

        // Retrying with same idempotency_key returns the exact same hold without consuming extra inventory
        $holdResRetry = $this->postJson('/api/v1/public/booking-holds', [
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'adults' => 2,
            'rooms_count' => 2,
            'idempotency_key' => 'hold-idem-key-001',
        ])->assertCreated();
        $this->assertSame($token1, $holdResRetry->json('data.hold_token'));

        // A second customer trying to hold or book overlapping dates while Hold #1 is active is rejected
        $this->postJson('/api/v1/public/booking-holds', [
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'adults' => 2,
            'rooms_count' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors(['rooms_count']);

        // Expire Hold #1 in DB (simulating time passing even before cron runs)
        BookingHold::where('hold_token', $token1)->update(['expires_at' => now()->subMinute()]);

        // Attempting to convert the expired hold into a reservation fails
        $this->postJson('/api/v1/public/reservations', [
            'hold_token' => $token1,
            'guest_name' => 'Late Customer',
            'guest_email' => 'late@example.test',
            'guest_phone' => '+2348000000099',
        ])->assertUnprocessable()->assertJsonValidationErrors(['hold_token']);

        // And the inventory is immediately available again for another customer even without cron running!
        $this->getJson("/api/v1/public/availability?check_in_date={$in}&check_out_date={$out}&adults=2")
            ->assertOk()
            ->assertJsonPath('data.options.0.available_rooms', 2);
    }

    public function test_concurrent_attempts_for_last_available_room_and_reservation_idempotency(): void
    {
        $in = now()->addDays(25)->format('Y-m-d');
        $out = now()->addDays(27)->format('Y-m-d');

        // Put room 102 inactive so only 1 physical room (101) is sellable
        $this->room102->update(['operational_status' => 'inactive']);

        // Customer 1 books the last room with an idempotency key
        $r1 = $this->postJson('/api/v1/public/reservations', [
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'adults' => 2,
            'rooms_count' => 1,
            'guest_name' => 'Customer One',
            'guest_email' => 'c1@example.test',
            'guest_phone' => '+2348000000011',
            'idempotency_key' => 'res-idem-001',
        ])->assertCreated();

        // Duplicate submission by Customer 1 returns the same reservation reference idempotently
        $r1Dup = $this->postJson('/api/v1/public/reservations', [
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'adults' => 2,
            'rooms_count' => 1,
            'guest_name' => 'Customer One',
            'guest_email' => 'c1@example.test',
            'guest_phone' => '+2348000000011',
            'idempotency_key' => 'res-idem-001',
        ])->assertCreated();
        $this->assertSame($r1->json('data.reference'), $r1Dup->json('data.reference'));

        // Customer 2 attempting to reserve the same last room is rejected with 422
        $this->postJson('/api/v1/public/reservations', [
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'adults' => 2,
            'rooms_count' => 1,
            'guest_name' => 'Customer Two',
            'guest_email' => 'c2@example.test',
            'guest_phone' => '+2348000000012',
        ])->assertUnprocessable()->assertJsonValidationErrors(['rooms_count']);

        $this->assertSame(1, Reservation::count());
    }

    public function test_public_reservation_lookup_requires_valid_access_token(): void
    {
        $in = now()->addDays(6)->format('Y-m-d');
        $out = now()->addDays(8)->format('Y-m-d');

        $created = $this->postJson('/api/v1/public/reservations', [
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'adults' => 2,
            'rooms_count' => 1,
            'guest_name' => 'Sani Usman',
            'guest_email' => 'sani@example.test',
            'guest_phone' => '+2348000000021',
        ])->assertCreated();

        $ref = $created->json('data.reference');
        $token = $created->json('data.access_token');

        // Lookup without token or with wrong token is forbidden (prevents PII enumeration)
        $this->getJson("/api/v1/public/reservations/{$ref}")->assertForbidden();
        $this->getJson("/api/v1/public/reservations/{$ref}?token=wrong-token")->assertForbidden();

        // Lookup with valid token succeeds
        $this->getJson("/api/v1/public/reservations/{$ref}?token={$token}")
            ->assertOk()
            ->assertJsonPath('data.reference', $ref)
            ->assertJsonPath('data.guest_email', 'sani@example.test');
    }

    public function test_staff_workflows_room_assignment_checkin_checkout_amendment_and_cancellation(): void
    {
        $receptionist = $this->userWithRole('receptionist');
        $manager = $this->userWithRole('hotel-manager');

        $in = now()->addDays(2)->format('Y-m-d');
        $out = now()->addDays(4)->format('Y-m-d');

        // 1. Receptionist creates a walk-in reservation and assigns Room 101
        $createRes = $this->actingAs($receptionist)->postJson('/api/v1/admin/reservations', [
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'assigned_room_id' => $this->room101->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'adults' => 2,
            'rooms_count' => 1,
            'source' => 'walk_in',
            'guest_name' => 'Dr. Halima Danjuma',
            'guest_email' => 'halima@example.test',
            'guest_phone' => '+2348000000033',
        ])->assertCreated();

        $resId = $createRes->json('data.id');

        // 2. Trying to assign Room 101 to a second overlapping reservation fails
        $this->actingAs($receptionist)->postJson('/api/v1/admin/reservations', [
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'assigned_room_id' => $this->room101->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'adults' => 1,
            'rooms_count' => 1,
            'source' => 'phone',
            'guest_name' => 'Second Guest',
            'guest_email' => 'second@example.test',
            'guest_phone' => '+2348000000034',
        ])->assertUnprocessable()->assertJsonValidationErrors(['assigned_room_id']);

        // 3. Amend reservation to 3 nights -> recalculates price and records amendment history
        $newOut = now()->addDays(5)->format('Y-m-d');
        $this->actingAs($receptionist)->putJson("/api/v1/admin/reservations/{$resId}", [
            'check_out_date' => $newOut,
            'amendment_reason' => 'Guest requested 1 extra night',
        ])->assertOk()
            ->assertJsonPath('data.nights', 3);

        // 4. Check-in guest
        $this->actingAs($receptionist)->postJson("/api/v1/admin/reservations/{$resId}/check-in", [])
            ->assertOk()
            ->assertJsonPath('data.status', 'checked_in');

        // 5. Checked-in reservation cannot be cancelled via ordinary cancel endpoint
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAs($manager)->postJson("/api/v1/admin/reservations/{$resId}/cancel", [
            'reason' => 'Attempt invalid cancel after check-in',
        ])->assertUnprocessable()->assertJsonValidationErrors(['status']);

        // 6. Check-out guest -> marks Room 101 housekeeping_status = 'dirty'
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAs($receptionist)->postJson("/api/v1/admin/reservations/{$resId}/check-out", [])
            ->assertOk()
            ->assertJsonPath('data.status', 'checked_out');

        $this->assertSame('dirty', $this->room101->fresh()->housekeeping_status);

        // 7. Checked-out reservation cannot be checked in again
        $this->actingAs($receptionist)->postJson("/api/v1/admin/reservations/{$resId}/check-in", [])
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);
    }

    public function test_cancellation_releases_inventory_and_does_not_auto_mark_refunded(): void
    {
        $manager = $this->userWithRole('hotel-manager');
        $in = now()->addDays(12)->format('Y-m-d');
        $out = now()->addDays(14)->format('Y-m-d');

        // Book both rooms
        $r = $this->actingAs($manager)->postJson('/api/v1/admin/reservations', [
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'adults' => 2,
            'rooms_count' => 2,
            'source' => 'phone',
            'guest_name' => 'Group Booking',
            'guest_email' => 'group@example.test',
            'guest_phone' => '+2348000000044',
        ])->assertCreated();

        $resId = $r->json('data.id');

        // Record a deposit payment first
        $this->actingAs($manager)->postJson("/api/v1/admin/reservations/{$resId}/payments", [
            'amount' => 50000,
            'method' => 'bank_transfer',
            'provider_reference' => 'NIP-000123',
        ])->assertCreated();

        $this->assertSame('partially_paid', Reservation::find($resId)->payment_status);

        // Cancel reservation
        $this->actingAs($manager)->postJson("/api/v1/admin/reservations/{$resId}/cancel", [
            'reason' => 'Event postponed',
        ])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.payment_status', 'partially_paid'); // NOT automatically 'refunded'!

        // Inventory is released (all 2 rooms available again)
        $this->getJson("/api/v1/public/availability?check_in_date={$in}&check_out_date={$out}&adults=2")
            ->assertOk()
            ->assertJsonPath('data.options.0.available_rooms', 2);
    }

    public function test_manual_payments_reversals_refund_approvals_and_webhook_idempotency(): void
    {
        $receptionist = $this->userWithRole('receptionist');
        $accountant = $this->userWithRole('accountant');

        $in = now()->addDays(8)->format('Y-m-d');
        $out = now()->addDays(9)->format('Y-m-d');

        $resResp = $this->actingAs($receptionist)->postJson('/api/v1/admin/reservations', [
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'adults' => 2,
            'rooms_count' => 1,
            'source' => 'walk_in',
            'guest_name' => 'Musa Abdullahi',
            'guest_email' => 'musa@example.test',
            'guest_phone' => '+2348000000055',
        ])->assertCreated();

        $resId = $resResp->json('data.id');
        $resRef = $resResp->json('data.reference');
        $totalAmount = $resResp->json('data.total_amount');

        // Invalid currency is rejected
        $this->actingAs($receptionist)->postJson("/api/v1/admin/reservations/{$resId}/payments", [
            'amount' => 10000,
            'currency_code' => 'USD',
            'method' => 'cash',
        ])->assertUnprocessable()->assertJsonValidationErrors(['currency_code']);

        // Record manual payment
        $payResp = $this->actingAs($receptionist)->postJson("/api/v1/admin/reservations/{$resId}/payments", [
            'amount' => $totalAmount,
            'currency_code' => 'NGN',
            'method' => 'pos',
            'provider_reference' => 'POS-SLIP-9988',
        ])->assertCreated();

        $txId = $payResp->json('data.id');
        $this->assertSame('paid', Reservation::find($resId)->payment_status);

        // Receptionist cannot reverse historical payments or approve refunds
        $this->actingAs($receptionist)->postJson("/api/v1/admin/payments/{$txId}/reverse", [
            'reason' => 'Mistake',
        ])->assertForbidden();

        // Receptionist can request a refund, but cannot approve it
        $refundReq = $this->actingAs($receptionist)->postJson("/api/v1/admin/reservations/{$resId}/refunds", [
            'amount' => 10000,
            'payout_method' => 'bank_transfer',
            'reason' => 'Guest overpaid',
        ])->assertCreated();

        $refundId = $refundReq->json('data.id');

        $this->actingAs($receptionist)->postJson("/api/v1/admin/refunds/{$refundId}/review", [
            'decision' => 'approve',
        ])->assertForbidden();

        // Accountant (has finance.refund) approves the refund -> status becomes partially_refunded
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAs($accountant)->postJson("/api/v1/admin/refunds/{$refundId}/review", [
            'decision' => 'approve',
            'payout_method' => 'bank_transfer',
            'payout_reference' => 'RFD-TX-001',
            'review_notes' => 'Verified and transferred',
        ])->assertOk()->assertJsonPath('data.status', 'processed');

        $this->assertSame('partially_refunded', Reservation::find($resId)->payment_status);

        // Test Paystack webhook signature verification (`PAYSTACK_SECRET_KEY` + server-side verification) and idempotency
        config(['services.paystack.secret_key' => 'test_paystack_secret_123']);
        $reservation = Reservation::find($resId);
        \Illuminate\Support\Facades\Http::fake([
            'api.paystack.co/transaction/verify/*' => \Illuminate\Support\Facades\Http::response([
                'status' => true,
                'message' => 'Verification successful',
                'data' => [
                    'id' => 987654321,
                    'status' => 'success',
                    'reference' => 'PSK-REF-0001',
                    'amount' => $reservation->total_minor,
                    'currency' => 'NGN',
                    'metadata' => ['reservation_reference' => $resRef],
                ],
            ], 200),
        ]);
        $webhookPayload = [
            'event' => 'charge.success',
            'data' => [
                'id' => 987654321,
                'status' => 'success',
                'reference' => 'PSK-REF-0001',
                'amount' => $reservation->total_minor,
                'currency' => 'NGN',
                'metadata' => ['reservation_reference' => $resRef],
            ],
        ];
        $rawJson = json_encode($webhookPayload);
        $validSig = hash_hmac('sha512', $rawJson, 'test_paystack_secret_123');

        // Invalid signature -> 401
        $this->call('POST', '/api/v1/public/webhooks/payments/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => 'invalid_signature',
        ], $rawJson)->assertStatus(401);

        // Valid signature -> 200 completed
        $this->call('POST', '/api/v1/public/webhooks/payments/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $validSig,
        ], $rawJson)->assertOk()->assertJsonPath('data.status', 'completed');

        // Duplicate delivery -> 200 duplicate_ignored (idempotent!)
        $this->call('POST', '/api/v1/public/webhooks/payments/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $validSig,
        ], $rawJson)->assertOk()->assertJsonPath('data.status', 'duplicate_ignored');
    }

    public function test_flutterwave_v3_webhook_signature_and_server_verification_plus_out_of_order_and_late_callbacks(): void
    {
        // Configure online booking to require payment so reservations start in `pending_payment`
        Setting::updateOrCreate(['group' => 'booking', 'key' => 'require_payment_for_online_booking'], ['value' => true, 'is_public' => true]);
        Setting::updateOrCreate(['group' => 'booking', 'key' => 'online_payment_provider'], ['value' => 'flutterwave', 'is_public' => true]);
        \Illuminate\Support\Facades\Cache::forget('settings.all');

        config([
            'services.flutterwave.secret_key' => 'FLWSECK_TEST-998877',
            'services.flutterwave.webhook_secret_hash' => 'flw_secret_hash_abc123',
            'services.flutterwave.base_url' => 'https://api.flutterwave.com/v3',
        ]);

        $in = now()->addDays(18)->format('Y-m-d');
        $out = now()->addDays(19)->format('Y-m-d');

        $resResp = $this->postJson('/api/v1/public/reservations', [
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'adults' => 2,
            'rooms_count' => 1,
            'guest_name' => 'Fatima Aliyu',
            'guest_email' => 'fatima@example.test',
            'guest_phone' => '+2348000000081',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.payment_status', 'unpaid');

        $resRef = $resResp->json('data.reference');
        $totalAmount = (float) $resResp->json('data.total_amount');

        // 1. Missing or invalid verif-hash header -> 401
        $validPayload = [
            'event' => 'charge.completed',
            'data' => [
                'id' => 555001,
                'tx_ref' => 'FLW-TX-001',
                'amount' => $totalAmount,
                'currency' => 'NGN',
                'status' => 'successful',
                'meta' => ['reservation_reference' => $resRef],
            ],
        ];
        $rawValid = json_encode($validPayload);

        $this->call('POST', '/api/v1/public/webhooks/payments/flutterwave', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $rawValid)->assertStatus(401);

        $this->call('POST', '/api/v1/public/webhooks/payments/flutterwave', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_VERIF_HASH' => 'wrong_hash',
        ], $rawValid)->assertStatus(401);

        // 2. Flutterwave verification API returns underpaid amount -> rejected with 422 (reservation stays pending_payment)
        \Illuminate\Support\Facades\Http::fakeSequence()
            ->push([
                'status' => 'success',
                'data' => [
                    'id' => 555001,
                    'tx_ref' => 'FLW-TX-001',
                    'amount' => 5000.00, // underpaid!
                    'currency' => 'NGN',
                    'status' => 'successful',
                    'meta' => ['reservation_reference' => $resRef],
                ],
            ], 200)
            ->push([
                'status' => 'success',
                'data' => [
                    'id' => 555001,
                    'tx_ref' => 'FLW-TX-001',
                    'amount' => $totalAmount,
                    'currency' => 'USD', // wrong currency!
                    'status' => 'successful',
                    'meta' => ['reservation_reference' => $resRef],
                ],
            ], 200)
            ->push([
                'status' => 'success',
                'data' => [
                    'id' => 555001,
                    'tx_ref' => 'FLW-TX-001',
                    'amount' => $totalAmount,
                    'currency' => 'NGN',
                    'status' => 'successful',
                    'meta' => ['reservation_reference' => $resRef],
                ],
            ], 200)
            ->push([
                'status' => 'success',
                'data' => [
                    'id' => 555003,
                    'tx_ref' => 'FLW-TX-003-LATE',
                    'amount' => $totalAmount,
                    'currency' => 'NGN',
                    'status' => 'successful',
                    'meta' => ['reservation_reference' => $resRef],
                ],
            ], 200);

        $this->call('POST', '/api/v1/public/webhooks/payments/flutterwave', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_VERIF_HASH' => 'flw_secret_hash_abc123',
        ], $rawValid)->assertStatus(422)->assertJsonValidationErrors(['amount']);

        // 3. Wrong currency from verification API -> rejected with 422
        $this->call('POST', '/api/v1/public/webhooks/payments/flutterwave', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_VERIF_HASH' => 'flw_secret_hash_abc123',
        ], $rawValid)->assertStatus(422)->assertJsonValidationErrors(['currency']);

        $this->assertSame('pending_payment', Reservation::where('reference', $resRef)->value('status'));

        // 4. Valid verification API response -> promotes pending_payment -> confirmed and marks paid
        $this->call('POST', '/api/v1/public/webhooks/payments/flutterwave', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_VERIF_HASH' => 'flw_secret_hash_abc123',
        ], $rawValid)->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.reservation_status', 'confirmed')
            ->assertJsonPath('data.payment_status', 'paid');

        // 5. Out-of-order failed payment callback arriving AFTER a completed payment must NOT downgrade `paid` or `confirmed`
        $failedPayload = [
            'event' => 'charge.completed',
            'data' => [
                'id' => 555002,
                'tx_ref' => 'FLW-TX-002-EARLIER-FAIL',
                'amount' => $totalAmount,
                'currency' => 'NGN',
                'status' => 'failed',
                'meta' => ['reservation_reference' => $resRef],
            ],
        ];
        $this->call('POST', '/api/v1/public/webhooks/payments/flutterwave', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_VERIF_HASH' => 'flw_secret_hash_abc123',
        ], json_encode($failedPayload))->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.reservation_status', 'confirmed')
            ->assertJsonPath('data.payment_status', 'paid');

        // 6. Late payment webhook arriving for a CANCELLED reservation records the money for reconciliation/refund without silently re-confirming released inventory
        $cancelledRes = Reservation::where('reference', $resRef)->firstOrFail();
        $manager = $this->userWithRole('hotel-manager');
        $this->actingAs($manager)->postJson("/api/v1/admin/reservations/{$cancelledRes->id}/cancel", [
            'reason' => 'Cancelled before late webhook test',
        ])->assertOk();

        $latePayload = [
            'event' => 'charge.completed',
            'data' => [
                'id' => 555003,
                'tx_ref' => 'FLW-TX-003-LATE',
                'amount' => $totalAmount,
                'currency' => 'NGN',
                'status' => 'successful',
                'meta' => ['reservation_reference' => $resRef],
            ],
        ];

        $this->call('POST', '/api/v1/public/webhooks/payments/flutterwave', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_VERIF_HASH' => 'flw_secret_hash_abc123',
        ], json_encode($latePayload))->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.reservation_status', 'cancelled')
            ->assertJsonPath('data.payment_status', 'paid');
    }

    public function test_financial_ledger_immutability_and_protection_against_over_refund_and_over_reversal(): void
    {
        $receptionist = $this->userWithRole('receptionist');
        $accountant = $this->userWithRole('accountant');

        $in = now()->addDays(11)->format('Y-m-d');
        $out = now()->addDays(12)->format('Y-m-d');

        $resResp = $this->actingAs($receptionist)->postJson('/api/v1/admin/reservations', [
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'adults' => 2,
            'rooms_count' => 1,
            'source' => 'walk_in',
            'guest_name' => 'Kabiru Lawal',
            'guest_email' => 'kabiru@example.test',
            'guest_phone' => '+2348000000090',
        ])->assertCreated();

        $resId = $resResp->json('data.id');
        $totalAmount = (float) $resResp->json('data.total_amount');

        $payResp = $this->actingAs($receptionist)->postJson("/api/v1/admin/reservations/{$resId}/payments", [
            'amount' => $totalAmount,
            'currency_code' => 'NGN',
            'method' => 'cash',
        ])->assertCreated();

        $txId = $payResp->json('data.id');
        $tx = \App\Models\PaymentTransaction::findOrFail($txId);

        // 1. Direct model update or deletion on PaymentTransaction is blocked by LogicException
        try {
            $tx->update(['amount_minor' => 100]);
            $this->fail('Expected LogicException when mutating a PaymentTransaction.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        try {
            $tx->delete();
            $this->fail('Expected LogicException when deleting a PaymentTransaction.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('cannot be deleted', $e->getMessage());
        }

        // 2. Request a pending refund of 60% of totalAmount
        $part1 = round($totalAmount * 0.6, 2);
        $this->actingAs($receptionist)->postJson("/api/v1/admin/reservations/{$resId}/refunds", [
            'amount' => $part1,
            'payout_method' => 'bank_transfer',
            'reason' => 'Partial refund request 1',
        ])->assertCreated();

        // 3. Attempting to queue a second pending refund of 60% (which would push pending refunds to 120% of paid) is rejected!
        $this->actingAs($receptionist)->postJson("/api/v1/admin/reservations/{$resId}/refunds", [
            'amount' => $part1,
            'payout_method' => 'bank_transfer',
            'reason' => 'Duplicate/excess refund request',
        ])->assertUnprocessable()->assertJsonValidationErrors(['amount']);

        // 4. Attempting to reverse the full original payment while a 60% refund is pending (or processed) is rejected!
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAs($accountant)->postJson("/api/v1/admin/payments/{$txId}/reverse", [
            'reason' => 'Attempt full reversal while refund exists',
        ])->assertUnprocessable()->assertJsonValidationErrors(['transaction']);
    }

    public function test_idempotency_key_parameter_tampering_rejected_and_audit_log_redacts_tokens(): void
    {
        $in1 = now()->addDays(21)->format('Y-m-d');
        $out1 = now()->addDays(22)->format('Y-m-d');
        $out2 = now()->addDays(24)->format('Y-m-d');

        // Create hold with idempotency key for 1 night
        $this->postJson('/api/v1/public/booking-holds', [
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'check_in_date' => $in1,
            'check_out_date' => $out1,
            'adults' => 2,
            'rooms_count' => 1,
            'idempotency_key' => 'tamper-hold-key-1',
        ])->assertCreated();

        // Reusing the same idempotency key with DIFFERENT check_out_date or rooms_count is rejected with 422
        $this->postJson('/api/v1/public/booking-holds', [
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'check_in_date' => $in1,
            'check_out_date' => $out2,
            'adults' => 2,
            'rooms_count' => 1,
            'idempotency_key' => 'tamper-hold-key-1',
        ])->assertUnprocessable()->assertJsonValidationErrors(['idempotency_key']);

        // Create reservation with idempotency key
        $res = $this->postJson('/api/v1/public/reservations', [
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'check_in_date' => $in1,
            'check_out_date' => $out1,
            'adults' => 2,
            'rooms_count' => 1,
            'guest_name' => 'Hassan Garba',
            'guest_email' => 'hassan@example.test',
            'guest_phone' => '+2348000000095',
            'idempotency_key' => 'tamper-res-key-1',
        ])->assertCreated();

        // Reusing reservation idempotency key for a different guest email or date is rejected with 422
        $this->postJson('/api/v1/public/reservations', [
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'check_in_date' => $in1,
            'check_out_date' => $out2,
            'adults' => 2,
            'rooms_count' => 1,
            'guest_name' => 'Attacker Guest',
            'guest_email' => 'attacker@example.test',
            'guest_phone' => '+2348000000096',
            'idempotency_key' => 'tamper-res-key-1',
        ])->assertUnprocessable()->assertJsonValidationErrors(['idempotency_key']);

        // Verify public_access_token is never written to audit_logs
        $accessToken = $res->json('data.access_token');
        $this->assertNotEmpty($accessToken);
        $auditDump = json_encode(\App\Models\AuditLog::all()->toArray());
        $this->assertStringNotContainsString($accessToken, $auditDump);
    }

    public function test_webhook_provider_timeout_malformed_response_tx_ref_mismatch_and_cross_reservation_reuse(): void
    {
        Setting::updateOrCreate(['group' => 'booking', 'key' => 'require_payment_for_online_booking'], ['value' => true, 'is_public' => true]);
        Setting::updateOrCreate(['group' => 'booking', 'key' => 'online_payment_provider'], ['value' => 'paystack', 'is_public' => true]);
        \Illuminate\Support\Facades\Cache::forget('settings.all');

        config([
            'services.paystack.secret_key' => 'sk_test_paystack_gate_999',
            'services.paystack.base_url' => 'https://api.paystack.co',
            'services.flutterwave.secret_key' => 'FLWSECK_TEST-GATE-999',
            'services.flutterwave.webhook_secret_hash' => 'flw_gate_hash_999',
            'services.flutterwave.base_url' => 'https://api.flutterwave.com/v3',
        ]);

        $in = now()->addDays(28)->format('Y-m-d');
        $out = now()->addDays(29)->format('Y-m-d');

        $r1 = $this->postJson('/api/v1/public/reservations', [
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'adults' => 1,
            'rooms_count' => 1,
            'guest_name' => 'Guest Alpha',
            'guest_email' => 'alpha@example.test',
            'guest_phone' => '+2348000000101',
        ])->assertCreated();

        $r2 = $this->postJson('/api/v1/public/reservations', [
            'room_type_id' => $this->deluxeType->id,
            'rate_plan_id' => $this->standardPlan->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'adults' => 1,
            'rooms_count' => 1,
            'guest_name' => 'Guest Beta',
            'guest_email' => 'beta@example.test',
            'guest_phone' => '+2348000000102',
        ])->assertCreated();

        $ref1 = $r1->json('data.reference');
        $ref2 = $r2->json('data.reference');
        $totalMinor = Reservation::where('reference', $ref1)->value('total_minor');

        $payload1 = [
            'event' => 'charge.success',
            'data' => [
                'id' => 777001,
                'status' => 'success',
                'reference' => 'PSK-GATE-001',
                'amount' => $totalMinor,
                'currency' => 'NGN',
                'metadata' => ['reservation_reference' => $ref1],
            ],
        ];
        $raw1 = json_encode($payload1);
        $sig1 = hash_hmac('sha512', $raw1, 'sk_test_paystack_gate_999');

        \Illuminate\Support\Facades\Http::fakeSequence()
            // 1. Provider HTTP 500 outage -> fails closed with 422
            ->push('Internal Server Error', 500)
            // 2. Provider malformed JSON response -> fails closed with 422
            ->push(['status' => false, 'message' => 'Invalid key'], 200)
            // 3. Provider returns pending/failed status despite charge.success webhook -> records failed/pending, does NOT mark paid
            ->push([
                'status' => true,
                'data' => [
                    'id' => 777001,
                    'status' => 'abandoned',
                    'reference' => 'PSK-GATE-001',
                    'amount' => $totalMinor,
                    'currency' => 'NGN',
                    'metadata' => ['reservation_reference' => $ref1],
                ],
            ], 200)
            // 4. Provider returns mismatched reference -> fails closed with 422
            ->push([
                'status' => true,
                'data' => [
                    'id' => 777002,
                    'status' => 'success',
                    'reference' => 'DIFFERENT-PSK-REF',
                    'amount' => $totalMinor,
                    'currency' => 'NGN',
                    'metadata' => ['reservation_reference' => $ref1],
                ],
            ], 200)
            // 5. Valid provider verification for Reservation 1
            ->push([
                'status' => true,
                'data' => [
                    'id' => 777003,
                    'status' => 'success',
                    'reference' => 'PSK-GATE-VALID-1',
                    'amount' => $totalMinor,
                    'currency' => 'NGN',
                    'metadata' => ['reservation_reference' => $ref1],
                ],
            ], 200)
            // 6. Attempt to reuse PSK-GATE-VALID-1 for Reservation 2
            ->push([
                'status' => true,
                'data' => [
                    'id' => 777003,
                    'status' => 'success',
                    'reference' => 'PSK-GATE-VALID-1',
                    'amount' => $totalMinor,
                    'currency' => 'NGN',
                    'metadata' => ['reservation_reference' => $ref2],
                ],
            ], 200);

        // 1. Provider HTTP 500
        $this->call('POST', '/api/v1/public/webhooks/payments/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $sig1,
        ], $raw1)->assertStatus(422)->assertJsonValidationErrors(['provider_verification']);

        // 2. Provider malformed response
        $this->call('POST', '/api/v1/public/webhooks/payments/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $sig1,
        ], $raw1)->assertStatus(422)->assertJsonValidationErrors(['provider_verification']);

        // 3. Provider status is 'abandoned' -> recorded as 'pending', reservation remains 'pending_payment' & 'unpaid'
        $this->call('POST', '/api/v1/public/webhooks/payments/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $sig1,
        ], $raw1)->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.reservation_status', 'pending_payment')
            ->assertJsonPath('data.payment_status', 'unpaid');

        // 4. Mismatched provider reference -> 422
        $payloadMismatch = $payload1;
        $payloadMismatch['data']['id'] = 777002;
        $payloadMismatch['data']['reference'] = 'PSK-GATE-002';
        $rawMismatch = json_encode($payloadMismatch);
        $this->call('POST', '/api/v1/public/webhooks/payments/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $rawMismatch, 'sk_test_paystack_gate_999'),
        ], $rawMismatch)->assertStatus(422)->assertJsonValidationErrors(['reference']);

        // 5. Valid payment for Reservation 1
        $payloadValid1 = $payload1;
        $payloadValid1['data']['id'] = 777003;
        $payloadValid1['data']['reference'] = 'PSK-GATE-VALID-1';
        $rawValid1 = json_encode($payloadValid1);
        $this->call('POST', '/api/v1/public/webhooks/payments/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $rawValid1, 'sk_test_paystack_gate_999'),
        ], $rawValid1)->assertOk()->assertJsonPath('data.status', 'completed');

        // 6. Reusing the same provider_reference (PSK-GATE-VALID-1) for Reservation 2 is rejected with 422!
        $payloadReuse = [
            'event' => 'charge.success',
            'data' => [
                'id' => 777004,
                'status' => 'success',
                'reference' => 'PSK-GATE-VALID-1',
                'amount' => $totalMinor,
                'currency' => 'NGN',
                'metadata' => ['reservation_reference' => $ref2],
            ],
        ];
        $rawReuse = json_encode($payloadReuse);
        $this->call('POST', '/api/v1/public/webhooks/payments/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $rawReuse, 'sk_test_paystack_gate_999'),
        ], $rawReuse)->assertStatus(422)->assertJsonValidationErrors(['reference']);

        $this->assertSame('pending_payment', Reservation::where('reference', $ref2)->value('status'));
    }

    public function test_sensitive_guest_details_permission_enforcement(): void
    {
        $guest = Guest::create([
            'full_name' => 'Chief Bello',
            'email' => 'bello@example.test',
            'phone' => '+2348000000077',
            'id_document_type' => 'Passport',
            'id_document_number' => 'A12345678',
            'internal_notes' => 'CONFIDENTIAL VIP SECURITY NOTE',
        ]);

        $receptionist = $this->userWithRole('receptionist'); // has guests.view, NOT guests.sensitive
        $gm = $this->userWithRole('general-manager'); // has guests.sensitive

        $recResp = $this->actingAs($receptionist)->getJson('/api/v1/admin/guests')->assertOk();
        $this->assertStringNotContainsString('A12345678', $recResp->getContent());
        $this->assertStringNotContainsString('CONFIDENTIAL VIP SECURITY NOTE', $recResp->getContent());

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $gmResp = $this->actingAs($gm)->getJson('/api/v1/admin/guests')->assertOk();
        $this->assertStringContainsString('A12345678', $gmResp->getContent());
        $this->assertStringContainsString('CONFIDENTIAL VIP SECURITY NOTE', $gmResp->getContent());
    }
}
