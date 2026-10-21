<?php

namespace App\Services;

use App\Models\BookingHold;
use App\Models\Guest;
use App\Models\MaintenanceWorkOrder;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationNight;
use App\Models\ReservationStatusHistory;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Notifications\ReservationConfirmationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Transactional Reservation & Front-Desk Service with Concurrency Locking.
 */
class ReservationService
{
    /**
     * Explicit state machine transitions: from_status => [allowed_to_statuses].
     */
    public const ALLOWED_TRANSITIONS = [
        Reservation::STATUS_PENDING_PAYMENT => [
            Reservation::STATUS_CONFIRMED,
            Reservation::STATUS_CANCELLED,
            Reservation::STATUS_EXPIRED,
        ],
        Reservation::STATUS_CONFIRMED => [
            Reservation::STATUS_CHECKED_IN,
            Reservation::STATUS_CANCELLED,
            Reservation::STATUS_NO_SHOW,
        ],
        Reservation::STATUS_CHECKED_IN => [
            Reservation::STATUS_CHECKED_OUT,
        ],
        Reservation::STATUS_CHECKED_OUT => [],
        Reservation::STATUS_CANCELLED => [],
        Reservation::STATUS_NO_SHOW => [],
        Reservation::STATUS_EXPIRED => [],
    ];

    public function __construct(
        private AvailabilityService $availability,
        private SettingsService $settings,
        private AuditLogger $audit,
    ) {}

    /**
     * Create an idempotent, time-limited booking hold with row-level locking on the RoomType.
     */
    public function createHold(array $data, ?string $ipAddress = null, bool $publicOnly = true): BookingHold
    {
        $idempotencyKey = isset($data['idempotency_key']) && filled($data['idempotency_key'])
            ? trim((string) $data['idempotency_key'])
            : null;

        if ($idempotencyKey) {
            $existing = BookingHold::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if (
                    $existing->room_type_id !== (int) $data['room_type_id']
                    || $existing->rate_plan_id !== (int) $data['rate_plan_id']
                    || $existing->check_in_date->format('Y-m-d') !== (string) $data['check_in_date']
                    || $existing->check_out_date->format('Y-m-d') !== (string) $data['check_out_date']
                    || $existing->rooms_count !== (int) ($data['rooms_count'] ?? 1)
                ) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['Idempotency key has already been used with different booking parameters.'],
                    ]);
                }
                if (! $existing->isExpired()) {
                    return $existing->load(['roomType', 'ratePlan']);
                }
                // Release the expired hold's idempotency_key so a fresh retry can proceed cleanly
                $existing->update(['status' => 'expired', 'idempotency_key' => null]);
            }
        }

        return DB::transaction(function () use ($data, $idempotencyKey, $ipAddress, $publicOnly) {
            $this->availability->releaseExpiredHolds();

            /** @var RoomType $roomType */
            $roomType = RoomType::where('id', (int) $data['room_type_id'])->lockForUpdate()->firstOrFail();

            if ($idempotencyKey) {
                $existingLocked = BookingHold::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if ($existingLocked) {
                    if (
                        $existingLocked->room_type_id !== (int) $data['room_type_id']
                        || $existingLocked->rate_plan_id !== (int) $data['rate_plan_id']
                        || $existingLocked->check_in_date->format('Y-m-d') !== (string) $data['check_in_date']
                        || $existingLocked->check_out_date->format('Y-m-d') !== (string) $data['check_out_date']
                        || $existingLocked->rooms_count !== (int) ($data['rooms_count'] ?? 1)
                    ) {
                        throw ValidationException::withMessages([
                            'idempotency_key' => ['Idempotency key has already been used with different booking parameters.'],
                        ]);
                    }
                    if (! $existingLocked->isExpired()) {
                        return $existingLocked->load(['roomType', 'ratePlan']);
                    }
                    $existingLocked->update(['status' => 'expired', 'idempotency_key' => null]);
                }
            }

            /** @var RatePlan $ratePlan */
            $ratePlan = RatePlan::findOrFail((int) $data['rate_plan_id']);

            $quote = $this->availability->calculateQuote(
                roomType: $roomType,
                ratePlan: $ratePlan,
                checkInDate: (string) $data['check_in_date'],
                checkOutDate: (string) $data['check_out_date'],
                adults: (int) ($data['adults'] ?? 1),
                children: (int) ($data['children'] ?? 0),
                roomsCount: (int) ($data['rooms_count'] ?? 1),
                publicOnly: $publicOnly,
                allowPastForStaff: ! $publicOnly,
            );

            $ttlMinutes = max(5, min(60, (int) ($this->settings->all()['booking']['hold_ttl_minutes'] ?? 15)));

            $hold = BookingHold::create([
                'hold_token' => Str::random(48),
                'idempotency_key' => $idempotencyKey,
                'room_type_id' => $roomType->id,
                'rate_plan_id' => $ratePlan->id,
                'check_in_date' => $quote['check_in_date'],
                'check_out_date' => $quote['check_out_date'],
                'nights' => $quote['nights'],
                'rooms_count' => $quote['rooms_count'],
                'adults' => $quote['adults'],
                'children' => $quote['children'],
                'currency_code' => $quote['currency_code'],
                'subtotal_minor' => $quote['subtotal_minor'],
                'taxes_minor' => $quote['taxes_minor'],
                'fees_minor' => $quote['fees_minor'],
                'total_minor' => $quote['total_minor'],
                'total_amount' => $quote['total_amount'],
                'quote_snapshot' => $quote,
                'guest_name' => isset($data['guest_name']) ? strip_tags((string) $data['guest_name']) : null,
                'guest_email' => $data['guest_email'] ?? null,
                'guest_phone' => isset($data['guest_phone']) ? strip_tags((string) $data['guest_phone']) : null,
                'status' => 'active',
                'expires_at' => now()->addMinutes($ttlMinutes),
                'ip_address' => $ipAddress,
            ]);

            return $hold->load(['roomType', 'ratePlan']);
        }, 3);
    }

    /**
     * Create a reservation (online or staff-managed) with atomic inventory locking and nightly allocation rows.
     */
    public function createReservation(
        array $data,
        ?User $actor = null,
        bool $isPublic = true
    ): Reservation {
        $idempotencyKey = isset($data['idempotency_key']) && filled($data['idempotency_key'])
            ? trim((string) $data['idempotency_key'])
            : null;

        if ($idempotencyKey) {
            $existing = Reservation::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                $reqEmail = isset($data['guest_email']) ? strtolower(trim((string) $data['guest_email'])) : '';
                $sameRoomType = empty($data['room_type_id']) || $existing->room_type_id === (int) $data['room_type_id'];
                $sameRatePlan = empty($data['rate_plan_id']) || $existing->rate_plan_id === (int) $data['rate_plan_id'];
                $sameCheckIn = empty($data['check_in_date']) || $existing->check_in_date->format('Y-m-d') === (string) $data['check_in_date'];
                $sameCheckOut = empty($data['check_out_date']) || $existing->check_out_date->format('Y-m-d') === (string) $data['check_out_date'];
                $sameRoomsCount = empty($data['rooms_count']) || $existing->rooms_count === (int) $data['rooms_count'];
                $sameEmail = $reqEmail === '' || strtolower($existing->guest_email) === $reqEmail;

                if (! ($sameRoomType && $sameRatePlan && $sameCheckIn && $sameCheckOut && $sameRoomsCount && $sameEmail)) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['Idempotency key has already been used for a reservation with different booking parameters.'],
                    ]);
                }

                return $existing->load(['roomType', 'ratePlan', 'assignedRoom', 'nightsAllocations']);
            }
        }

        $reservation = DB::transaction(function () use ($data, $actor, $isPublic, $idempotencyKey) {
            $this->availability->releaseExpiredHolds();

            /** @var BookingHold|null $hold */
            $hold = null;
            if (! empty($data['hold_token'])) {
                $hold = BookingHold::where('hold_token', (string) $data['hold_token'])->lockForUpdate()->first();
                if (! $hold) {
                    throw ValidationException::withMessages(['hold_token' => ['Booking hold was not found.']]);
                }
                if ($hold->status === 'converted') {
                    $existingByHold = Reservation::where('booking_hold_id', $hold->id)->first();
                    if ($existingByHold) {
                        return $existingByHold;
                    }
                }
                if ($hold->isExpired()) {
                    $hold->update(['status' => 'expired']);
                    throw ValidationException::withMessages([
                        'hold_token' => ['Your booking hold has expired. Please re-check availability to reserve your room.'],
                    ]);
                }
            }

            $roomTypeId = $hold ? $hold->room_type_id : (int) $data['room_type_id'];
            $ratePlanId = $hold ? $hold->rate_plan_id : (int) $data['rate_plan_id'];
            $checkInDate = $hold ? $hold->check_in_date->format('Y-m-d') : (string) $data['check_in_date'];
            $checkOutDate = $hold ? $hold->check_out_date->format('Y-m-d') : (string) $data['check_out_date'];
            $adults = $hold ? $hold->adults : (int) ($data['adults'] ?? 1);
            $children = $hold ? $hold->children : (int) ($data['children'] ?? 0);
            $roomsCount = $hold ? $hold->rooms_count : (int) ($data['rooms_count'] ?? 1);

            /** @var RoomType $roomType */
            $roomType = RoomType::where('id', $roomTypeId)->lockForUpdate()->firstOrFail();

            if ($idempotencyKey) {
                $existingLocked = Reservation::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if ($existingLocked) {
                    $reqEmail = isset($data['guest_email']) ? strtolower(trim((string) $data['guest_email'])) : '';
                    $sameRoomType = empty($data['room_type_id']) || $existingLocked->room_type_id === (int) $data['room_type_id'];
                    $sameRatePlan = empty($data['rate_plan_id']) || $existingLocked->rate_plan_id === (int) $data['rate_plan_id'];
                    $sameCheckIn = empty($data['check_in_date']) || $existingLocked->check_in_date->format('Y-m-d') === (string) $data['check_in_date'];
                    $sameCheckOut = empty($data['check_out_date']) || $existingLocked->check_out_date->format('Y-m-d') === (string) $data['check_out_date'];
                    $sameRoomsCount = empty($data['rooms_count']) || $existingLocked->rooms_count === (int) $data['rooms_count'];
                    $sameEmail = $reqEmail === '' || strtolower($existingLocked->guest_email) === $reqEmail;

                    if (! ($sameRoomType && $sameRatePlan && $sameCheckIn && $sameCheckOut && $sameRoomsCount && $sameEmail)) {
                        throw ValidationException::withMessages([
                            'idempotency_key' => ['Idempotency key has already been used for a reservation with different booking parameters.'],
                        ]);
                    }

                    return $existingLocked;
                }
            }

            /** @var RatePlan $ratePlan */
            $ratePlan = RatePlan::findOrFail($ratePlanId);

            // Always calculate/verify quote server-side; ignore any client-supplied price fields
            $quote = $this->availability->calculateQuote(
                roomType: $roomType,
                ratePlan: $ratePlan,
                checkInDate: $checkInDate,
                checkOutDate: $checkOutDate,
                adults: $adults,
                children: $children,
                roomsCount: $roomsCount,
                publicOnly: $isPublic,
                allowPastForStaff: ! $isPublic,
                excludeHoldId: $hold?->id,
            );

            // Optional physical room assignment (for staff bookings with rooms_count === 1)
            $assignedRoomId = null;
            if (! $isPublic && ! empty($data['assigned_room_id'])) {
                if ($roomsCount > 1) {
                    throw ValidationException::withMessages([
                        'assigned_room_id' => ['Physical room assignment at booking time is supported for single-room reservations.'],
                    ]);
                }
                $assignedRoom = $this->validateRoomAssignment(
                    roomType: $roomType,
                    roomId: (int) $data['assigned_room_id'],
                    checkInDate: $checkInDate,
                    checkOutDate: $checkOutDate,
                );
                $assignedRoomId = $assignedRoom->id;
            }

            $guestName = trim(strip_tags((string) $data['guest_name']));
            $guestEmail = strtolower(trim((string) $data['guest_email']));
            $guestPhone = trim(strip_tags((string) $data['guest_phone']));

            $guest = Guest::firstOrCreate(
                ['email' => $guestEmail],
                [
                    'full_name' => $guestName,
                    'phone' => $guestPhone,
                    'nationality' => isset($data['guest_nationality']) ? strip_tags((string) $data['guest_nationality']) : null,
                    'created_by' => $actor?->id,
                ]
            );
            if ($guest->full_name !== $guestName || $guest->phone !== $guestPhone) {
                $guest->update(['full_name' => $guestName, 'phone' => $guestPhone]);
            }

            $bookingSettings = $this->settings->all()['booking'] ?? [];
            $requireOnlinePayment = $isPublic && ! empty($bookingSettings['require_payment_for_online_booking']) && ($bookingSettings['online_payment_provider'] ?? 'none') !== 'none';

            $initialStatus = $requireOnlinePayment
                ? Reservation::STATUS_PENDING_PAYMENT
                : Reservation::STATUS_CONFIRMED;

            $reference = $this->generateUniqueReference();

            $reservation = Reservation::create([
                'reference' => $reference,
                'public_access_token' => Str::random(48),
                'idempotency_key' => $idempotencyKey,
                'booking_hold_id' => $hold?->id,
                'guest_id' => $guest->id,
                'room_type_id' => $roomType->id,
                'rate_plan_id' => $ratePlan->id,
                'assigned_room_id' => $assignedRoomId,
                'guest_name' => $guestName,
                'guest_email' => $guestEmail,
                'guest_phone' => $guestPhone,
                'check_in_date' => $quote['check_in_date'],
                'check_out_date' => $quote['check_out_date'],
                'nights' => $quote['nights'],
                'rooms_count' => $quote['rooms_count'],
                'adults' => $quote['adults'],
                'children' => $quote['children'],
                'source' => $isPublic ? 'online' : ($data['source'] ?? 'front_desk'),
                'status' => $initialStatus,
                'payment_status' => Reservation::PAYMENT_UNPAID,
                'currency_code' => $quote['currency_code'],
                'subtotal_minor' => $quote['subtotal_minor'],
                'taxes_minor' => $quote['taxes_minor'],
                'fees_minor' => $quote['fees_minor'],
                'discount_minor' => 0,
                'total_minor' => $quote['total_minor'],
                'amount_paid_minor' => 0,
                'amount_refunded_minor' => 0,
                'cancellation_fee_minor' => 0,
                'subtotal_amount' => $quote['subtotal_amount'],
                'taxes_amount' => $quote['taxes_amount'],
                'fees_amount' => $quote['fees_amount'],
                'total_amount' => $quote['total_amount'],
                'amount_paid' => 0,
                'amount_refunded' => 0,
                'price_snapshot' => $quote,
                'cancellation_policy_snapshot' => $quote['cancellation_policy'],
                'special_requests' => isset($data['special_requests']) ? strip_tags((string) $data['special_requests']) : null,
                'internal_notes' => (! $isPublic && isset($data['internal_notes'])) ? strip_tags((string) $data['internal_notes']) : null,
                'confirmed_at' => $initialStatus === Reservation::STATUS_CONFIRMED ? now() : null,
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ]);

            $this->syncNightlyAllocations($reservation, $quote, $assignedRoomId);

            if ($hold) {
                $hold->update(['status' => 'converted']);
            }

            ReservationStatusHistory::create([
                'reservation_id' => $reservation->id,
                'from_status' => null,
                'to_status' => $reservation->status,
                'from_payment_status' => null,
                'to_payment_status' => $reservation->payment_status,
                'reason' => $isPublic ? 'Online reservation created' : 'Staff reservation created',
                'changed_by' => $actor?->id,
                'created_at' => now(),
            ]);

            $this->audit->log('reservation.created', $reservation, [
                'reference' => $reservation->reference,
                'source' => $reservation->source,
                'status' => $reservation->status,
                'room_type_id' => $reservation->room_type_id,
                'rate_plan_id' => $reservation->rate_plan_id,
                'check_in_date' => $reservation->check_in_date->format('Y-m-d'),
                'check_out_date' => $reservation->check_out_date->format('Y-m-d'),
                'rooms_count' => $reservation->rooms_count,
                'total_minor' => $reservation->total_minor,
            ], 'success', $actor?->id);

            if (Schema::hasTable('guest_folios')) {
                app(FolioService::class)->ensureFolioForReservation($reservation, $actor);
            }

            return $reservation;
        }, 3);

        // Send confirmation notification gracefully; never fail the reservation if mail delivery fails
        $this->dispatchConfirmationEmailSafely($reservation);

        return $reservation->load(['roomType', 'ratePlan', 'assignedRoom', 'nightsAllocations']);
    }

    /**
     * Assign or change physical room for a reservation, enforcing availability and sellability.
     */
    public function assignRoom(Reservation $reservation, ?int $roomId, User $actor): Reservation
    {
        return DB::transaction(function () use ($reservation, $roomId, $actor) {
            /** @var Reservation $res */
            $res = Reservation::where('id', $reservation->id)->lockForUpdate()->firstOrFail();

            if (! in_array($res->status, [Reservation::STATUS_CONFIRMED, Reservation::STATUS_PENDING_PAYMENT, Reservation::STATUS_CHECKED_IN], true)) {
                throw ValidationException::withMessages([
                    'assigned_room_id' => ["Cannot assign a room to a reservation in status [{$res->status}]."],
                ]);
            }

            $oldRoomId = $res->assigned_room_id;

            if ($roomId === null) {
                if ($res->status === Reservation::STATUS_CHECKED_IN) {
                    throw ValidationException::withMessages([
                        'assigned_room_id' => ['A checked-in reservation must remain assigned to a physical room.'],
                    ]);
                }
                $res->update(['assigned_room_id' => null, 'updated_by' => $actor->id]);
                ReservationNight::where('reservation_id', $res->id)
                    ->where('status', 'active')
                    ->update(['room_id' => null, 'active_room_id' => null]);
            } else {
                if ($res->rooms_count > 1) {
                    throw ValidationException::withMessages([
                        'assigned_room_id' => ['Single physical room assignment is only supported for single-room reservations (rooms_count = 1).'],
                    ]);
                }
                $roomType = RoomType::where('id', $res->room_type_id)->lockForUpdate()->firstOrFail();
                $room = $this->validateRoomAssignment(
                    roomType: $roomType,
                    roomId: $roomId,
                    checkInDate: $res->check_in_date->format('Y-m-d'),
                    checkOutDate: $res->check_out_date->format('Y-m-d'),
                    excludeReservationId: $res->id,
                );

                $res->update(['assigned_room_id' => $room->id, 'updated_by' => $actor->id]);
                ReservationNight::where('reservation_id', $res->id)
                    ->where('unit_index', 1)
                    ->where('status', 'active')
                    ->update(['room_id' => $room->id, 'active_room_id' => $room->id]);
            }

            $this->audit->log('reservation.room_assigned', $res, [
                'reference' => $res->reference,
                'from_room_id' => $oldRoomId,
                'to_room_id' => $roomId,
            ], 'success', $actor->id);

            return $res->fresh(['roomType', 'ratePlan', 'assignedRoom', 'nightsAllocations']);
        }, 3);
    }

    /**
     * Amend reservation dates, room type, rate plan, or guest counts with fresh availability & price validation.
     */
    public function amendReservation(Reservation $reservation, array $data, User $actor): Reservation
    {
        return DB::transaction(function () use ($reservation, $data, $actor) {
            /** @var Reservation $res */
            $res = Reservation::where('id', $reservation->id)->lockForUpdate()->firstOrFail();

            if (! in_array($res->status, [Reservation::STATUS_PENDING_PAYMENT, Reservation::STATUS_CONFIRMED], true)) {
                throw ValidationException::withMessages([
                    'status' => ["Only pending or confirmed reservations can be amended (current status: {$res->status})."],
                ]);
            }

            $newRoomTypeId = (int) ($data['room_type_id'] ?? $res->room_type_id);
            $newRatePlanId = (int) ($data['rate_plan_id'] ?? $res->rate_plan_id);
            $newCheckIn = (string) ($data['check_in_date'] ?? $res->check_in_date->format('Y-m-d'));
            $newCheckOut = (string) ($data['check_out_date'] ?? $res->check_out_date->format('Y-m-d'));
            $newAdults = (int) ($data['adults'] ?? $res->adults);
            $newChildren = (int) ($data['children'] ?? $res->children);
            $newRoomsCount = (int) ($data['rooms_count'] ?? $res->rooms_count);

            // Lock both old and new RoomTypes in deterministic ascending ID order to prevent AB-BA deadlocks
            $lockedRoomTypes = RoomType::whereIn('id', array_unique([$res->room_type_id, $newRoomTypeId]))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var RoomType $roomType */
            $roomType = $lockedRoomTypes->get($newRoomTypeId) ?? RoomType::findOrFail($newRoomTypeId);
            /** @var RatePlan $ratePlan */
            $ratePlan = RatePlan::findOrFail($newRatePlanId);

            $quote = $this->availability->calculateQuote(
                roomType: $roomType,
                ratePlan: $ratePlan,
                checkInDate: $newCheckIn,
                checkOutDate: $newCheckOut,
                adults: $newAdults,
                children: $newChildren,
                roomsCount: $newRoomsCount,
                publicOnly: false,
                allowPastForStaff: true,
                excludeReservationId: $res->id,
            );

            // Re-validate assigned room if still present
            $assignedRoomId = $res->assigned_room_id;
            if (array_key_exists('assigned_room_id', $data)) {
                $assignedRoomId = $data['assigned_room_id'] ? (int) $data['assigned_room_id'] : null;
            }
            if ($assignedRoomId !== null) {
                if ($newRoomTypeId !== $res->room_type_id || $newRoomsCount > 1) {
                    $assignedRoomId = null;
                } else {
                    try {
                        $this->validateRoomAssignment($roomType, $assignedRoomId, $newCheckIn, $newCheckOut, $res->id);
                    } catch (ValidationException) {
                        $assignedRoomId = null;
                    }
                }
            }

            $oldTotalMinor = $res->total_minor;
            $priceDifferenceMinor = $quote['total_minor'] - $oldTotalMinor;

            $quote['amendment_history'] = [
                ...($res->price_snapshot['amendment_history'] ?? []),
                [
                    'amended_at' => now()->toIso8601String(),
                    'amended_by' => $actor->id,
                    'previous_total_minor' => $oldTotalMinor,
                    'new_total_minor' => $quote['total_minor'],
                    'difference_minor' => $priceDifferenceMinor,
                    'previous_check_in' => $res->check_in_date->format('Y-m-d'),
                    'previous_check_out' => $res->check_out_date->format('Y-m-d'),
                ],
            ];

            $res->update([
                'room_type_id' => $roomType->id,
                'rate_plan_id' => $ratePlan->id,
                'assigned_room_id' => $assignedRoomId,
                'guest_name' => isset($data['guest_name']) ? strip_tags((string) $data['guest_name']) : $res->guest_name,
                'guest_email' => isset($data['guest_email']) ? strtolower(trim((string) $data['guest_email'])) : $res->guest_email,
                'guest_phone' => isset($data['guest_phone']) ? strip_tags((string) $data['guest_phone']) : $res->guest_phone,
                'check_in_date' => $quote['check_in_date'],
                'check_out_date' => $quote['check_out_date'],
                'nights' => $quote['nights'],
                'rooms_count' => $quote['rooms_count'],
                'adults' => $quote['adults'],
                'children' => $quote['children'],
                'subtotal_minor' => $quote['subtotal_minor'],
                'taxes_minor' => $quote['taxes_minor'],
                'fees_minor' => $quote['fees_minor'],
                'total_minor' => $quote['total_minor'],
                'subtotal_amount' => $quote['subtotal_amount'],
                'taxes_amount' => $quote['taxes_amount'],
                'fees_amount' => $quote['fees_amount'],
                'total_amount' => $quote['total_amount'],
                'price_snapshot' => $quote,
                'cancellation_policy_snapshot' => $quote['cancellation_policy'],
                'special_requests' => array_key_exists('special_requests', $data)
                    ? ($data['special_requests'] !== null ? strip_tags((string) $data['special_requests']) : null)
                    : $res->special_requests,
                'internal_notes' => array_key_exists('internal_notes', $data)
                    ? ($data['internal_notes'] !== null ? strip_tags((string) $data['internal_notes']) : null)
                    : $res->internal_notes,
                'updated_by' => $actor->id,
            ]);

            $this->syncNightlyAllocations($res, $quote, $assignedRoomId);
            $this->recalculatePaymentStatus($res);

            ReservationStatusHistory::create([
                'reservation_id' => $res->id,
                'from_status' => $res->status,
                'to_status' => $res->status,
                'from_payment_status' => $res->payment_status,
                'to_payment_status' => $res->fresh()->payment_status,
                'reason' => $data['amendment_reason'] ?? 'Reservation amended',
                'metadata' => [
                    'old_total_minor' => $oldTotalMinor,
                    'new_total_minor' => $quote['total_minor'],
                    'difference_minor' => $priceDifferenceMinor,
                ],
                'changed_by' => $actor->id,
                'created_at' => now(),
            ]);

            $this->audit->log('reservation.amended', $res, [
                'reference' => $res->reference,
                'old_total_minor' => $oldTotalMinor,
                'new_total_minor' => $quote['total_minor'],
                'difference_minor' => $priceDifferenceMinor,
                'check_in_date' => $quote['check_in_date'],
                'check_out_date' => $quote['check_out_date'],
            ], 'success', $actor->id);

            if (Schema::hasTable('guest_folios')) {
                app(FolioService::class)->ensureFolioForReservation($res, $actor);
            }

            return $res->fresh(['roomType', 'ratePlan', 'assignedRoom', 'nightsAllocations', 'statusHistories', 'payments', 'refunds']);
        }, 3);
    }

    /**
     * Check a guest in. Requires physical room assignment for single-room stays and a `confirmed` reservation.
     */
    public function checkIn(Reservation $reservation, ?int $roomId, User $actor): Reservation
    {
        return DB::transaction(function () use ($reservation, $roomId, $actor) {
            /** @var Reservation $res */
            $res = Reservation::where('id', $reservation->id)->lockForUpdate()->firstOrFail();
            $this->assertTransitionAllowed($res->status, Reservation::STATUS_CHECKED_IN);

            $targetRoomId = $roomId ?? $res->assigned_room_id;
            if (! $targetRoomId) {
                throw ValidationException::withMessages([
                    'assigned_room_id' => ['A physical room must be assigned before checking the guest in.'],
                ]);
            }

            $roomType = RoomType::where('id', $res->room_type_id)->lockForUpdate()->firstOrFail();
            $room = $this->validateRoomAssignment(
                roomType: $roomType,
                roomId: $targetRoomId,
                checkInDate: $res->check_in_date->format('Y-m-d'),
                checkOutDate: $res->check_out_date->format('Y-m-d'),
                excludeReservationId: $res->id,
            );

            // Enforce Phase 4 Room Readiness & Housekeeping / Maintenance Check-In Policy
            $bookingSettings = $this->settings->all()['booking'] ?? [];
            $blockDirty = (bool) ($bookingSettings['block_dirty_room_checkin'] ?? true);
            $requireInspected = (bool) ($bookingSettings['require_inspected_room_for_checkin'] ?? false);

            if (! $room->isReadyForCheckIn($blockDirty, $requireInspected)) {
                $reasonMsg = match ($room->housekeeping_status) {
                    'maintenance' => "Room {$room->room_number} is currently blocked by an active maintenance work order.",
                    'dirty' => "Room {$room->room_number} is dirty and must be cleaned before check-in.",
                    'inspecting' => "Room {$room->room_number} is awaiting housekeeping supervisor inspection before check-in.",
                    default => "Room {$room->room_number} requires housekeeping supervisor inspection (status: {$room->housekeeping_status}) before check-in.",
                };
                throw ValidationException::withMessages([
                    'assigned_room_id' => [$reasonMsg],
                ]);
            }

            $oldStatus = $res->status;
            $res->update([
                'status' => Reservation::STATUS_CHECKED_IN,
                'assigned_room_id' => $room->id,
                'checked_in_at' => now(),
                'checked_in_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            ReservationNight::where('reservation_id', $res->id)
                ->where('unit_index', 1)
                ->where('status', 'active')
                ->update(['room_id' => $room->id, 'active_room_id' => $room->id]);

            ReservationStatusHistory::create([
                'reservation_id' => $res->id,
                'from_status' => $oldStatus,
                'to_status' => Reservation::STATUS_CHECKED_IN,
                'from_payment_status' => $res->payment_status,
                'to_payment_status' => $res->payment_status,
                'reason' => "Checked in to Room {$room->room_number}",
                'changed_by' => $actor->id,
                'created_at' => now(),
            ]);

            $this->audit->log('reservation.checked_in', $res, [
                'reference' => $res->reference,
                'room_number' => $room->room_number,
            ], 'success', $actor->id);

            if (Schema::hasTable('guest_folios')) {
                app(FolioService::class)->ensureFolioForReservation($res, $actor);
            }

            return $res->fresh(['roomType', 'ratePlan', 'assignedRoom', 'nightsAllocations', 'statusHistories', 'payments', 'refunds']);
        }, 3);
    }

    /**
     * Check a guest out. Marks assigned room housekeeping_status = 'dirty' for housekeeping workflow.
     */
    public function checkOut(Reservation $reservation, User $actor): Reservation
    {
        return DB::transaction(function () use ($reservation, $actor) {
            /** @var Reservation $res */
            $res = Reservation::where('id', $reservation->id)->lockForUpdate()->firstOrFail();
            $this->assertTransitionAllowed($res->status, Reservation::STATUS_CHECKED_OUT);

            $opsSettings = $this->settings->all()['operations'] ?? [];
            if (! empty($opsSettings['require_settled_folio_for_checkout']) && Schema::hasTable('guest_folios')) {
                $folio = app(FolioService::class)->ensureFolioForReservation($res, $actor);
                if ($folio->balance_due_minor > 0 && $folio->status !== \App\Models\GuestFolio::STATUS_CLOSED) {
                    throw ValidationException::withMessages([
                        'folio' => ["Guest folio {$folio->folio_reference} has an unsettled balance of {$folio->currency_code} ".number_format($folio->balance_due_amount, 2).". Settle the folio before checking out."],
                    ]);
                }
            }

            $oldStatus = $res->status;
            $res->update([
                'status' => Reservation::STATUS_CHECKED_OUT,
                'checked_out_at' => now(),
                'checked_out_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            // Release remaining future night allocations (if early checkout) and mark room dirty
            $today = now()->format('Y-m-d');
            ReservationNight::where('reservation_id', $res->id)
                ->where('stay_date', '>=', $today)
                ->update(['status' => 'released', 'active_room_id' => null]);

            if ($res->assigned_room_id) {
                Room::where('id', $res->assigned_room_id)->update(['housekeeping_status' => 'dirty']);
                if (($opsSettings['auto_create_checkout_cleaning_task'] ?? true) && Schema::hasTable('housekeeping_tasks')) {
                    app(HousekeepingService::class)->createCheckoutCleaningTaskForReservation($res->load('assignedRoom'), $actor);
                }
            }

            if (Schema::hasTable('guest_folios')) {
                app(FolioService::class)->ensureFolioForReservation($res, $actor);
            }

            ReservationStatusHistory::create([
                'reservation_id' => $res->id,
                'from_status' => $oldStatus,
                'to_status' => Reservation::STATUS_CHECKED_OUT,
                'from_payment_status' => $res->payment_status,
                'to_payment_status' => $res->payment_status,
                'reason' => 'Guest checked out',
                'changed_by' => $actor->id,
                'created_at' => now(),
            ]);

            $this->audit->log('reservation.checked_out', $res, [
                'reference' => $res->reference,
                'assigned_room_id' => $res->assigned_room_id,
            ], 'success', $actor->id);

            return $res->fresh(['roomType', 'ratePlan', 'assignedRoom', 'nightsAllocations', 'statusHistories', 'payments', 'refunds']);
        }, 3);
    }

    /**
     * Cancel a reservation, calculate applicable cancellation fee, and atomically release night allocations.
     * Note: Cancellation never automatically marks a reservation as refunded.
     */
    public function cancelReservation(Reservation $reservation, string $reason, ?User $actor = null): Reservation
    {
        return DB::transaction(function () use ($reservation, $reason, $actor) {
            /** @var Reservation $res */
            $res = Reservation::where('id', $reservation->id)->lockForUpdate()->firstOrFail();
            $this->assertTransitionAllowed($res->status, Reservation::STATUS_CANCELLED);

            $oldStatus = $res->status;
            $feeMinor = $this->availability->calculateCancellationFeeMinor($res);

            // Release all nightly inventory allocations first
            ReservationNight::where('reservation_id', $res->id)
                ->where('status', 'active')
                ->update(['status' => 'released', 'room_id' => null, 'active_room_id' => null]);

            $res->update([
                'status' => Reservation::STATUS_CANCELLED,
                'assigned_room_id' => null,
                'cancellation_reason' => strip_tags($reason),
                'cancellation_fee_minor' => $feeMinor,
                'cancelled_at' => now(),
                'cancelled_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ]);

            ReservationStatusHistory::create([
                'reservation_id' => $res->id,
                'from_status' => $oldStatus,
                'to_status' => Reservation::STATUS_CANCELLED,
                'from_payment_status' => $res->payment_status,
                'to_payment_status' => $res->payment_status,
                'reason' => strip_tags($reason),
                'metadata' => ['cancellation_fee_minor' => $feeMinor],
                'changed_by' => $actor?->id,
                'created_at' => now(),
            ]);

            $this->audit->log('reservation.cancelled', $res, [
                'reference' => $res->reference,
                'from_status' => $oldStatus,
                'cancellation_fee_minor' => $feeMinor,
                'reason' => strip_tags($reason),
            ], 'success', $actor?->id);

            if (Schema::hasTable('guest_folios')) {
                app(FolioService::class)->ensureFolioForReservation($res, $actor);
            }

            return $res->fresh(['roomType', 'ratePlan', 'assignedRoom', 'nightsAllocations', 'statusHistories', 'payments', 'refunds']);
        }, 3);
    }

    /**
     * Mark a confirmed reservation as a no-show and release inventory.
     */
    public function markNoShow(Reservation $reservation, string $reason, User $actor): Reservation
    {
        return DB::transaction(function () use ($reservation, $reason, $actor) {
            /** @var Reservation $res */
            $res = Reservation::where('id', $reservation->id)->lockForUpdate()->firstOrFail();
            $this->assertTransitionAllowed($res->status, Reservation::STATUS_NO_SHOW);

            $oldStatus = $res->status;
            $feeMinor = $this->availability->calculateCancellationFeeMinor($res);

            ReservationNight::where('reservation_id', $res->id)
                ->where('status', 'active')
                ->update(['status' => 'released', 'room_id' => null, 'active_room_id' => null]);

            $res->update([
                'status' => Reservation::STATUS_NO_SHOW,
                'assigned_room_id' => null,
                'no_show_reason' => strip_tags($reason),
                'cancellation_fee_minor' => $feeMinor,
                'no_show_at' => now(),
                'updated_by' => $actor->id,
            ]);

            ReservationStatusHistory::create([
                'reservation_id' => $res->id,
                'from_status' => $oldStatus,
                'to_status' => Reservation::STATUS_NO_SHOW,
                'from_payment_status' => $res->payment_status,
                'to_payment_status' => $res->payment_status,
                'reason' => strip_tags($reason),
                'metadata' => ['no_show_fee_minor' => $feeMinor],
                'changed_by' => $actor->id,
                'created_at' => now(),
            ]);

            $this->audit->log('reservation.no_show', $res, [
                'reference' => $res->reference,
                'no_show_fee_minor' => $feeMinor,
                'reason' => strip_tags($reason),
            ], 'success', $actor->id);

            if (Schema::hasTable('guest_folios')) {
                app(FolioService::class)->ensureFolioForReservation($res, $actor);
            }

            return $res->fresh(['roomType', 'ratePlan', 'assignedRoom', 'nightsAllocations', 'statusHistories', 'payments', 'refunds']);
        }, 3);
    }

    /**
     * Recalculate and persist `amount_paid_minor`, `amount_refunded_minor`, and `payment_status` on a Reservation.
     */
    public function recalculatePaymentStatus(Reservation $reservation): Reservation
    {
        $completedPayments = $reservation->payments()
            ->where('status', 'completed')
            ->get();

        $paidMinor = (int) $completedPayments
            ->where('type', 'payment')
            ->sum('amount_minor');

        $reversedMinor = (int) abs($completedPayments
            ->where('type', 'reversal')
            ->sum('amount_minor'));

        $refundedMinor = (int) abs($completedPayments
            ->where('type', 'refund_payout')
            ->sum('amount_minor'));

        $grossPaidMinor = max(0, $paidMinor - $reversedMinor);
        $netPaidMinor = max(0, $grossPaidMinor - $refundedMinor);

        if ($refundedMinor > 0 && $netPaidMinor === 0) {
            $paymentStatus = Reservation::PAYMENT_REFUNDED;
        } elseif ($refundedMinor > 0 && $netPaidMinor > 0) {
            $paymentStatus = Reservation::PAYMENT_PARTIALLY_REFUNDED;
        } elseif ($netPaidMinor >= $reservation->total_minor && $reservation->total_minor > 0) {
            $paymentStatus = Reservation::PAYMENT_PAID;
        } elseif ($netPaidMinor > 0) {
            $paymentStatus = Reservation::PAYMENT_PARTIALLY_PAID;
        } else {
            $hasFailed = $reservation->payments()->where('status', 'failed')->exists();
            $paymentStatus = $hasFailed ? Reservation::PAYMENT_FAILED : Reservation::PAYMENT_UNPAID;
        }

        $updates = [
            'amount_paid_minor' => $grossPaidMinor,
            'amount_paid' => AvailabilityService::toMajor($grossPaidMinor),
            'amount_refunded_minor' => $refundedMinor,
            'amount_refunded' => AvailabilityService::toMajor($refundedMinor),
            'payment_status' => $paymentStatus,
        ];

        // If a pending_payment reservation becomes paid or partially_paid, promote it to confirmed
        if ($reservation->status === Reservation::STATUS_PENDING_PAYMENT && in_array($paymentStatus, [Reservation::PAYMENT_PAID, Reservation::PAYMENT_PARTIALLY_PAID], true)) {
            $updates['status'] = Reservation::STATUS_CONFIRMED;
            $updates['confirmed_at'] = now();
        }

        $reservation->update($updates);
        $fresh = $reservation->fresh();

        if (Schema::hasTable('guest_folios')) {
            app(FolioService::class)->ensureFolioForReservation($fresh);
        }

        return $fresh;
    }

    public function assertTransitionAllowed(string $fromStatus, string $toStatus): void
    {
        $allowed = self::ALLOWED_TRANSITIONS[$fromStatus] ?? [];
        if (! in_array($toStatus, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ["Invalid reservation status transition from [{$fromStatus}] to [{$toStatus}]."],
            ]);
        }
    }

    private function validateRoomAssignment(
        RoomType $roomType,
        int $roomId,
        string $checkInDate,
        string $checkOutDate,
        ?int $excludeReservationId = null
    ): Room {
        /** @var Room|null $room */
        $room = Room::where('id', $roomId)->lockForUpdate()->first();
        if (! $room) {
            throw ValidationException::withMessages(['assigned_room_id' => ['Selected physical room does not exist.']]);
        }
        if ($room->room_type_id !== $roomType->id) {
            throw ValidationException::withMessages([
                'assigned_room_id' => ["Room {$room->room_number} does not belong to {$roomType->name}."],
            ]);
        }

        if ($room->housekeeping_status === 'maintenance') {
            throw ValidationException::withMessages([
                'assigned_room_id' => ["Room {$room->room_number} is currently blocked by an active maintenance work order."],
            ]);
        }

        $stayDates = $this->availability->parseStayDates($checkInDate, $checkOutDate, allowPastForStaff: true);
        foreach ($stayDates as $stayDate) {
            if (! $room->isSellableOnDate($stayDate)) {
                throw ValidationException::withMessages([
                    'assigned_room_id' => ["Room {$room->room_number} is out of service or inactive on {$stayDate}."],
                ]);
            }
        }

        $assignable = $this->availability->getAssignableRooms($roomType, $stayDates, $excludeReservationId);
        if (! $assignable->contains('id', $room->id)) {
            throw ValidationException::withMessages([
                'assigned_room_id' => ["Room {$room->room_number} is already assigned to an overlapping reservation."],
            ]);
        }

        return $room;
    }

    private function syncNightlyAllocations(Reservation $reservation, array $quote, ?int $assignedRoomId = null): void
    {
        ReservationNight::where('reservation_id', $reservation->id)->delete();

        $rows = [];
        $now = now();
        for ($unit = 1; $unit <= $reservation->rooms_count; $unit++) {
            foreach ($quote['nightly_breakdown'] as $night) {
                $rows[] = [
                    'reservation_id' => $reservation->id,
                    'room_type_id' => $reservation->room_type_id,
                    'rate_plan_id' => $reservation->rate_plan_id,
                    'room_id' => $unit === 1 ? $assignedRoomId : null,
                    'active_room_id' => $unit === 1 ? $assignedRoomId : null,
                    'unit_index' => $unit,
                    'stay_date' => $night['stay_date'],
                    'currency_code' => $quote['currency_code'],
                    'base_rate_minor' => $night['base_rate_minor'],
                    'extra_guest_minor' => $night['extra_guest_minor'],
                    'total_night_minor' => $night['total_night_minor'],
                    'total_night_amount' => $night['total_night_amount'],
                    'pricing_source' => $night['pricing_source'],
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        ReservationNight::insert($rows);
    }

    private function generateUniqueReference(): string
    {
        $year = now()->format('Y');
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $candidate = 'MCH-'.$year.'-'.strtoupper(Str::random(6));
            if (! Reservation::where('reference', $candidate)->exists()) {
                return $candidate;
            }
        }

        return 'MCH-'.$year.'-'.strtoupper(Str::random(8));
    }

    private function dispatchConfirmationEmailSafely(Reservation $reservation): void
    {
        try {
            $hotelName = $this->settings->all()['hotel']['name'] ?? 'Mai Chitta Hotel';
            Notification::route('mail', $reservation->guest_email)
                ->notify(new ReservationConfirmationNotification($reservation, (string) $hotelName));
        } catch (\Throwable $e) {
            Log::warning('Reservation confirmation email could not be sent; reservation was preserved.', [
                'reference' => $reservation->reference,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
