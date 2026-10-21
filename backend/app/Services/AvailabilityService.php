<?php

namespace App\Services;

use App\Models\BookingHold;
use App\Models\RatePlan;
use App\Models\RatePlanOverride;
use App\Models\RatePlanRoomType;
use App\Models\Reservation;
use App\Models\ReservationNight;
use App\Models\Room;
use App\Models\RoomType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Centralized Availability & Pricing Engine for Mai Chitta Hotel.
 *
 * Date Convention:
 * - Check-in date is INCLUSIVE.
 * - Check-out date is EXCLUSIVE.
 * - A stay from 2026-11-10 to 2026-11-12 consumes 2 nights: 2026-11-10 and 2026-11-11.
 * - Same-day turnover (e.g., Guest A checks out 2026-11-12, Guest B checks in 2026-11-12) is permitted.
 *
 * Pricing Precedence Rule (Deterministic):
 * For each stay night D in [check_in_date .. check_out_date - 1 day]:
 * 1. Exact-date override (`override_type = 'exact_date'` and `start_date <= D <= end_date`), ordered by `priority DESC, id DESC`.
 * 2. Seasonal date-range override (`override_type = 'date_range'` and `start_date <= D <= end_date`), ordered by `priority DESC, id DESC`.
 * 3. Weekday-specific rate configured on `rate_plan_room_types.weekday_rates_minor` for the lowercase 3-letter day (`mon`..`sun`).
 * 4. Room-type rate plan base rate (`rate_plan_room_types.base_rate_minor`), falling back to `rate_plans.default_base_rate_minor`.
 *
 * Monetary Arithmetic:
 * All calculations use integer minor units (1 NGN = 100 kobo) to eliminate floating-point rounding defects.
 */
class AvailabilityService
{
    public function __construct(
        private SettingsService $settings,
    ) {}

    public static function toMinor(float|int|string|null $major): int
    {
        if ($major === null || $major === '') {
            return 0;
        }

        return (int) round(((float) $major) * 100);
    }

    public static function toMajor(int $minor): float
    {
        return round($minor / 100, 2);
    }

    /**
     * Expire stale holds opportunistically so cPanel cron delays never leave inventory blocked.
     */
    public function releaseExpiredHolds(): int
    {
        return BookingHold::where('status', 'active')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired', 'updated_at' => now()]);
    }

    /**
     * Parse and validate a stay range, returning the list of inclusive stay dates (`Y-m-d`).
     *
     * @return list<string>
     */
    public function parseStayDates(string $checkInDate, string $checkOutDate, bool $allowPastForStaff = false): array
    {
        try {
            $checkIn = CarbonImmutable::createFromFormat('Y-m-d', $checkInDate)->startOfDay();
            $checkOut = CarbonImmutable::createFromFormat('Y-m-d', $checkOutDate)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'check_in_date' => ['Invalid check-in or check-out date format. Use YYYY-MM-DD.'],
            ]);
        }

        if (! $allowPastForStaff && $checkIn->lt(CarbonImmutable::today())) {
            throw ValidationException::withMessages([
                'check_in_date' => ['Check-in date cannot be in the past.'],
            ]);
        }

        if ($checkOut->lte($checkIn)) {
            throw ValidationException::withMessages([
                'check_out_date' => ['Check-out date must be after the check-in date (at least 1 night).'],
            ]);
        }

        $nights = (int) $checkIn->diffInDays($checkOut);
        $bookingSettings = $this->settings->all()['booking'] ?? [];
        $maxStay = max(1, (int) ($bookingSettings['max_stay_nights'] ?? 30));

        if ($nights > $maxStay) {
            throw ValidationException::withMessages([
                'check_out_date' => ["Maximum stay duration is {$maxStay} nights."],
            ]);
        }

        $dates = [];
        for ($i = 0; $i < $nights; $i++) {
            $dates[] = $checkIn->addDays($i)->format('Y-m-d');
        }

        return $dates;
    }

    /**
     * Compute per-night inventory breakdown for a RoomType across `$stayDates`.
     *
     * @param  list<string>  $stayDates
     * @return array{
     *   minimum_available: int,
     *   total_physical_rooms: int,
     *   nights: array<string, array{total_sellable: int, reserved_units: int, held_units: int, available_units: int}>
     * }
     */
    public function getRoomTypeInventory(
        RoomType $roomType,
        array $stayDates,
        ?int $excludeReservationId = null,
        ?int $excludeHoldId = null
    ): array {
        $this->releaseExpiredHolds();

        $firstDate = $stayDates[0];
        $lastDate = $stayDates[count($stayDates) - 1];
        $checkOutBoundary = CarbonImmutable::createFromFormat('Y-m-d', $lastDate)->addDay()->format('Y-m-d');

        $rooms = Room::where('room_type_id', $roomType->id)->lockForUpdate()->get();

        // Active reservation night allocations in range (use lockForUpdate so InnoDB reads the latest committed snapshot after RoomType::lockForUpdate())
        $activeReservationIds = Reservation::where('room_type_id', $roomType->id)
            ->whereIn('status', Reservation::INVENTORY_CONSUMING_STATUSES)
            ->where('check_in_date', '<', $checkOutBoundary)
            ->where('check_out_date', '>', $firstDate)
            ->when($excludeReservationId, fn ($q) => $q->where('id', '!=', $excludeReservationId))
            ->lockForUpdate()
            ->get(['id'])
            ->pluck('id')
            ->all();

        $reservedNightRows = empty($activeReservationIds)
            ? collect()
            : ReservationNight::query()
                ->where('room_type_id', $roomType->id)
                ->where('status', 'active')
                ->whereBetween('stay_date', [$firstDate, $lastDate])
                ->whereIn('reservation_id', $activeReservationIds)
                ->lockForUpdate()
                ->get(['stay_date']);

        $reservedPerDate = $reservedNightRows
            ->groupBy(fn (ReservationNight $n) => $n->stay_date instanceof \DateTimeInterface ? $n->stay_date->format('Y-m-d') : (string) $n->stay_date)
            ->map(fn ($group) => $group->count());

        // Active unexpired booking holds overlapping [firstDate, checkOutBoundary)
        $holds = BookingHold::activeUnexpired()
            ->where('room_type_id', $roomType->id)
            ->where('check_in_date', '<', $checkOutBoundary)
            ->where('check_out_date', '>', $firstDate)
            ->when($excludeHoldId, fn ($q) => $q->where('id', '!=', $excludeHoldId))
            ->lockForUpdate()
            ->get();

        $nightMatrix = [];
        $minAvailable = PHP_INT_MAX;

        foreach ($stayDates as $d) {
            $sellableCount = $rooms->filter(fn (Room $r) => $r->isSellableOnDate($d))->count();
            $reservedUnits = (int) ($reservedPerDate[$d] ?? 0);

            $heldUnits = $holds->filter(function (BookingHold $h) use ($d) {
                $in = $h->check_in_date->format('Y-m-d');
                $out = $h->check_out_date->format('Y-m-d');

                return $d >= $in && $d < $out;
            })->sum('rooms_count');

            $available = max(0, $sellableCount - $reservedUnits - $heldUnits);
            $minAvailable = min($minAvailable, $available);

            $nightMatrix[$d] = [
                'total_sellable' => $sellableCount,
                'reserved_units' => $reservedUnits,
                'held_units' => (int) $heldUnits,
                'available_units' => $available,
            ];
        }

        return [
            'minimum_available' => $minAvailable === PHP_INT_MAX ? 0 : $minAvailable,
            'total_physical_rooms' => $rooms->where('operational_status', '!=', 'inactive')->count(),
            'nights' => $nightMatrix,
        ];
    }

    /**
     * Find physical rooms of `$roomType` that are sellable and have no conflicting assignment across `$stayDates`.
     *
     * @param  list<string>  $stayDates
     * @return Collection<int, Room>
     */
    public function getAssignableRooms(
        RoomType $roomType,
        array $stayDates,
        ?int $excludeReservationId = null
    ): Collection {
        $firstDate = $stayDates[0];
        $lastDate = $stayDates[count($stayDates) - 1];
        $checkOutDate = CarbonImmutable::createFromFormat('Y-m-d', $lastDate)->addDay()->format('Y-m-d');

        $rooms = Room::where('room_type_id', $roomType->id)
            ->orderBy('room_number')
            ->get()
            ->filter(function (Room $room) use ($stayDates) {
                if ($room->housekeeping_status === 'maintenance') {
                    return false;
                }
                foreach ($stayDates as $d) {
                    if (! $room->isSellableOnDate($d)) {
                        return false;
                    }
                }

                return true;
            });

        if ($rooms->isEmpty()) {
            return collect();
        }

        // Exclude rooms assigned to overlapping active reservations or reservation_nights
        $busyRoomIdsFromReservations = Reservation::query()
            ->whereNotNull('assigned_room_id')
            ->whereIn('status', Reservation::INVENTORY_CONSUMING_STATUSES)
            ->where('check_in_date', '<', $checkOutDate)
            ->where('check_out_date', '>', $firstDate)
            ->when($excludeReservationId, fn ($q) => $q->where('id', '!=', $excludeReservationId))
            ->pluck('assigned_room_id')
            ->all();

        $busyRoomIdsFromNights = ReservationNight::query()
            ->whereNotNull('room_id')
            ->where('status', 'active')
            ->whereBetween('stay_date', [$firstDate, $lastDate])
            ->when($excludeReservationId, fn ($q) => $q->where('reservation_id', '!=', $excludeReservationId))
            ->whereHas('reservation', fn ($q) => $q->whereIn('status', Reservation::INVENTORY_CONSUMING_STATUSES))
            ->pluck('room_id')
            ->all();

        $busyIds = array_unique([...$busyRoomIdsFromReservations, ...$busyRoomIdsFromNights]);

        return $rooms->reject(fn (Room $r) => in_array($r->id, $busyIds, true))->values();
    }

    /**
     * Calculate a deterministic server-side quote for a specific RoomType, RatePlan, stay dates, and guest count.
     * Throws ValidationException if rate plan is ineligible, restricted (CTA/CTD/Closed/MinStay/MaxStay), or capacity exceeded.
     */
    public function calculateQuote(
        RoomType $roomType,
        RatePlan $ratePlan,
        string $checkInDate,
        string $checkOutDate,
        int $adults = 1,
        int $children = 0,
        int $roomsCount = 1,
        bool $publicOnly = true,
        bool $allowPastForStaff = false,
        ?int $excludeReservationId = null,
        ?int $excludeHoldId = null
    ): array {
        $stayDates = $this->parseStayDates($checkInDate, $checkOutDate, $allowPastForStaff);
        $nightsCount = count($stayDates);
        $firstNight = $stayDates[0];
        $lastNight = $stayDates[$nightsCount - 1];

        if (! $roomType->is_active || ($publicOnly && ! $roomType->is_public)) {
            throw ValidationException::withMessages(['room_type_id' => ['Selected room type is not available.']]);
        }

        if (! $ratePlan->is_active || ($publicOnly && ! $ratePlan->is_public)) {
            throw ValidationException::withMessages(['rate_plan_id' => ['Selected rate plan is not available.']]);
        }

        // Validate occupancy per room
        $adultsPerRoom = (int) ceil($adults / max(1, $roomsCount));
        $childrenPerRoom = (int) ceil($children / max(1, $roomsCount));
        $guestsPerRoom = (int) ceil(($adults + $children) / max(1, $roomsCount));

        if ($adults < 1 || $adultsPerRoom > $roomType->max_adults) {
            throw ValidationException::withMessages([
                'adults' => ["{$roomType->name} allows at most {$roomType->max_adults} adult(s) per room."],
            ]);
        }
        if ($children < 0 || $childrenPerRoom > $roomType->max_children) {
            throw ValidationException::withMessages([
                'children' => ["{$roomType->name} allows at most {$roomType->max_children} child(ren) per room."],
            ]);
        }
        if ($guestsPerRoom > $roomType->max_occupancy) {
            throw ValidationException::withMessages([
                'adults' => ["{$roomType->name} has a maximum total occupancy of {$roomType->max_occupancy} guest(s) per room."],
            ]);
        }

        // Rate plan effective window
        if ($ratePlan->effective_from && $firstNight < $ratePlan->effective_from->format('Y-m-d')) {
            throw ValidationException::withMessages([
                'rate_plan_id' => ["Rate plan {$ratePlan->name} is not effective before {$ratePlan->effective_from->format('Y-m-d')}."],
            ]);
        }
        if ($ratePlan->effective_until && $lastNight > $ratePlan->effective_until->format('Y-m-d')) {
            throw ValidationException::withMessages([
                'rate_plan_id' => ["Rate plan {$ratePlan->name} expires on {$ratePlan->effective_until->format('Y-m-d')}."],
            ]);
        }

        /** @var RatePlanRoomType|null $pivot */
        $pivot = RatePlanRoomType::where('rate_plan_id', $ratePlan->id)
            ->where('room_type_id', $roomType->id)
            ->where('is_active', true)
            ->first();

        if (! $pivot) {
            throw ValidationException::withMessages([
                'rate_plan_id' => ["Rate plan {$ratePlan->name} is not configured for {$roomType->name}."],
            ]);
        }

        // Check RatePlan-level stay restrictions
        if ($nightsCount < max(1, $ratePlan->min_stay_nights)) {
            throw ValidationException::withMessages([
                'check_out_date' => ["{$ratePlan->name} requires a minimum stay of {$ratePlan->min_stay_nights} night(s)."],
            ]);
        }
        if ($ratePlan->max_stay_nights !== null && $nightsCount > $ratePlan->max_stay_nights) {
            throw ValidationException::withMessages([
                'check_out_date' => ["{$ratePlan->name} allows a maximum stay of {$ratePlan->max_stay_nights} night(s)."],
            ]);
        }
        if ($ratePlan->closed_to_arrival) {
            throw ValidationException::withMessages([
                'check_in_date' => ["{$ratePlan->name} is currently closed to arrivals."],
            ]);
        }
        if ($ratePlan->closed_to_departure) {
            throw ValidationException::withMessages([
                'check_out_date' => ["{$ratePlan->name} is currently closed to departures."],
            ]);
        }

        // Inventory availability check
        $inventory = $this->getRoomTypeInventory($roomType, $stayDates, $excludeReservationId, $excludeHoldId);
        if ($inventory['minimum_available'] < $roomsCount) {
            throw ValidationException::withMessages([
                'rooms_count' => ["Only {$inventory['minimum_available']} room(s) of {$roomType->name} are available for the selected dates."],
            ]);
        }

        // Load date overrides overlapping stayDates or checkOutDate (for CTD check)
        $overrides = RatePlanOverride::where('rate_plan_id', $ratePlan->id)
            ->where('room_type_id', $roomType->id)
            ->where('start_date', '<=', $checkOutDate)
            ->where('end_date', '>=', $firstNight)
            ->orderByRaw("CASE WHEN override_type = 'exact_date' THEN 1 ELSE 2 END ASC")
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->get();

        // Check departure-date CTD override
        $departureOverride = $overrides->first(fn (RatePlanOverride $o) => $o->start_date->format('Y-m-d') <= $checkOutDate && $o->end_date->format('Y-m-d') >= $checkOutDate);
        if ($departureOverride && $departureOverride->closed_to_departure) {
            throw ValidationException::withMessages([
                'check_out_date' => ["Check-out on {$checkOutDate} is restricted (closed to departure) for {$ratePlan->name}."],
            ]);
        }

        $extraAdultsPerRoom = max(0, $adultsPerRoom - $roomType->base_occupancy);
        $extraChildrenPerRoom = max(0, $childrenPerRoom);

        $nightlyBreakdown = [];
        $singleRoomSubtotalMinor = 0;

        foreach ($stayDates as $idx => $stayDate) {
            /** @var RatePlanOverride|null $matchingOverride */
            $matchingOverride = $overrides->first(
                fn (RatePlanOverride $o) => $o->start_date->format('Y-m-d') <= $stayDate && $o->end_date->format('Y-m-d') >= $stayDate
            );

            if ($matchingOverride) {
                if ($matchingOverride->closed_to_sell) {
                    throw ValidationException::withMessages([
                        'check_in_date' => ["{$roomType->name} ({$ratePlan->name}) is closed to sell on {$stayDate}."],
                    ]);
                }
                if ($idx === 0 && $matchingOverride->closed_to_arrival) {
                    throw ValidationException::withMessages([
                        'check_in_date' => ["Check-in on {$stayDate} is restricted (closed to arrival) for {$ratePlan->name}."],
                    ]);
                }
                if ($matchingOverride->min_stay_nights !== null && $nightsCount < $matchingOverride->min_stay_nights) {
                    throw ValidationException::withMessages([
                        'check_out_date' => ["Stays including {$stayDate} require a minimum of {$matchingOverride->min_stay_nights} night(s)."],
                    ]);
                }
                if ($matchingOverride->max_stay_nights !== null && $nightsCount > $matchingOverride->max_stay_nights) {
                    throw ValidationException::withMessages([
                        'check_out_date' => ["Stays including {$stayDate} allow a maximum of {$matchingOverride->max_stay_nights} night(s)."],
                    ]);
                }
            }

            // Deterministic pricing precedence
            $dayKey = strtolower(CarbonImmutable::createFromFormat('Y-m-d', $stayDate)->format('D')); // mon..sun
            $weekdayMap = is_array($pivot->weekday_rates_minor) ? $pivot->weekday_rates_minor : [];

            if ($matchingOverride && $matchingOverride->nightly_rate_minor !== null) {
                $baseNightMinor = (int) $matchingOverride->nightly_rate_minor;
                $pricingSource = $matchingOverride->override_type === 'exact_date' ? 'exact_date_override' : 'date_range_override';
            } elseif (isset($weekdayMap[$dayKey]) && is_numeric($weekdayMap[$dayKey]) && (int) $weekdayMap[$dayKey] > 0) {
                $baseNightMinor = (int) $weekdayMap[$dayKey];
                $pricingSource = 'weekday_rate';
            } else {
                $baseNightMinor = (int) ($pivot->base_rate_minor ?: $ratePlan->default_base_rate_minor);
                $pricingSource = 'base_rate';
            }

            $extraAdultMinorRate = $matchingOverride && $matchingOverride->extra_adult_rate_minor !== null
                ? (int) $matchingOverride->extra_adult_rate_minor
                : (int) ($pivot->extra_adult_rate_minor ?: $ratePlan->extra_adult_rate_minor);

            $extraChildMinorRate = $matchingOverride && $matchingOverride->extra_child_rate_minor !== null
                ? (int) $matchingOverride->extra_child_rate_minor
                : (int) ($pivot->extra_child_rate_minor ?: $ratePlan->extra_child_rate_minor);

            $extraGuestMinor = ($extraAdultsPerRoom * $extraAdultMinorRate) + ($extraChildrenPerRoom * $extraChildMinorRate);
            $totalNightSingleRoomMinor = $baseNightMinor + $extraGuestMinor;
            $singleRoomSubtotalMinor += $totalNightSingleRoomMinor;

            $nightlyBreakdown[] = [
                'stay_date' => $stayDate,
                'day_of_week' => $dayKey,
                'pricing_source' => $pricingSource,
                'override_label' => $matchingOverride?->label,
                'base_rate_minor' => $baseNightMinor,
                'base_rate' => self::toMajor($baseNightMinor),
                'extra_guest_minor' => $extraGuestMinor,
                'extra_guest_amount' => self::toMajor($extraGuestMinor),
                'total_night_minor' => $totalNightSingleRoomMinor,
                'total_night_amount' => self::toMajor($totalNightSingleRoomMinor),
            ];
        }

        $allSettings = $this->settings->all();
        $currency = $allSettings['hotel']['currency'] ?? $ratePlan->currency_code ?? 'NGN';
        $taxPercent = (float) ($allSettings['booking']['tax_percentage'] ?? 0);
        $servicePercent = (float) ($allSettings['booking']['service_charge_percentage'] ?? 0);

        $subtotalMinor = $singleRoomSubtotalMinor * $roomsCount;
        $taxesMinor = (int) round($subtotalMinor * ($taxPercent / 100));
        $feesMinor = (int) round($subtotalMinor * ($servicePercent / 100));
        $totalMinor = $subtotalMinor + $taxesMinor + $feesMinor;

        $cancellationPolicy = $this->buildCancellationPolicySnapshot($ratePlan, $allSettings['booking']['default_cancellation_policy'] ?? null);

        return [
            'room_type' => [
                'id' => $roomType->id,
                'name' => $roomType->name,
                'slug' => $roomType->slug,
                'max_adults' => $roomType->max_adults,
                'max_children' => $roomType->max_children,
                'max_occupancy' => $roomType->max_occupancy,
                'base_occupancy' => $roomType->base_occupancy,
                'bed_configuration' => $roomType->bed_configuration,
            ],
            'rate_plan' => [
                'id' => $ratePlan->id,
                'name' => $ratePlan->name,
                'code' => $ratePlan->code,
                'meal_basis' => $ratePlan->meal_Basis,
                'is_refundable' => $ratePlan->is_refundable,
            ],
            'check_in_date' => $checkInDate,
            'check_out_date' => $checkOutDate,
            'nights' => $nightsCount,
            'rooms_count' => $roomsCount,
            'adults' => $adults,
            'children' => $children,
            'currency_code' => $currency,
            'average_nightly_minor' => (int) round($singleRoomSubtotalMinor / $nightsCount),
            'average_nightly_amount' => self::toMajor((int) round($singleRoomSubtotalMinor / $nightsCount)),
            'subtotal_minor' => $subtotalMinor,
            'subtotal_amount' => self::toMajor($subtotalMinor),
            'tax_percentage' => $taxPercent,
            'taxes_minor' => $taxesMinor,
            'taxes_amount' => self::toMajor($taxesMinor),
            'service_charge_percentage' => $servicePercent,
            'fees_minor' => $feesMinor,
            'fees_amount' => self::toMajor($feesMinor),
            'total_minor' => $totalMinor,
            'total_amount' => self::toMajor($totalMinor),
            'available_rooms' => $inventory['minimum_available'],
            'nightly_breakdown' => $nightlyBreakdown,
            'cancellation_policy' => $cancellationPolicy,
            'calculated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Search all available room types and their valid rate plans for a requested stay.
     */
    public function searchAvailableOptions(
        string $checkInDate,
        string $checkOutDate,
        int $adults = 1,
        int $children = 0,
        int $roomsCount = 1,
        bool $publicOnly = true,
        bool $allowPastForStaff = false
    ): array {
        $stayDates = $this->parseStayDates($checkInDate, $checkOutDate, $allowPastForStaff);
        $adultsPerRoom = (int) ceil($adults / max(1, $roomsCount));
        $childrenPerRoom = (int) ceil($children / max(1, $roomsCount));
        $guestsPerRoom = (int) ceil(($adults + $children) / max(1, $roomsCount));

        $query = RoomType::query()
            ->where('is_active', true)
            ->when($publicOnly, fn ($q) => $q->where('is_public', true))
            ->where('max_adults', '>=', $adultsPerRoom)
            ->where('max_children', '>=', $childrenPerRoom)
            ->where('max_occupancy', '>=', $guestsPerRoom)
            ->with(['featuredMedia', 'ratePlans' => fn ($q) => $q->where('rate_plans.is_active', true)->when($publicOnly, fn ($sq) => $sq->where('rate_plans.is_public', true))->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->orderBy('id');

        $roomTypes = $query->get();
        $results = [];

        foreach ($roomTypes as $roomType) {
            $inventory = $this->getRoomTypeInventory($roomType, $stayDates);
            if ($inventory['minimum_available'] < $roomsCount) {
                continue;
            }

            $ratePlanQuotes = [];
            foreach ($roomType->ratePlans as $ratePlan) {
                try {
                    $quote = $this->calculateQuote(
                        roomType: $roomType,
                        ratePlan: $ratePlan,
                        checkInDate: $checkInDate,
                        checkOutDate: $checkOutDate,
                        adults: $adults,
                        children: $children,
                        roomsCount: $roomsCount,
                        publicOnly: $publicOnly,
                        allowPastForStaff: $allowPastForStaff,
                    );
                    $ratePlanQuotes[] = $quote;
                } catch (ValidationException) {
                    // Skip rate plans restricted for the requested dates
                    continue;
                }
            }

            if (empty($ratePlanQuotes)) {
                continue;
            }

            $lowestQuote = collect($ratePlanQuotes)->sortBy('total_minor')->first();

            $results[] = [
                'room_type' => [
                    'id' => $roomType->id,
                    'name' => $roomType->name,
                    'slug' => $roomType->slug,
                    'code' => $roomType->code,
                    'subtitle' => $roomType->subtitle,
                    'description' => $roomType->public_description ?: $roomType->description,
                    'max_adults' => $roomType->max_adults,
                    'max_children' => $roomType->max_children,
                    'max_occupancy' => $roomType->max_occupancy,
                    'base_occupancy' => $roomType->base_occupancy,
                    'bed_configuration' => $roomType->bed_configuration,
                    'size_label' => $roomType->size_label,
                    'amenities' => $roomType->amenities ?? [],
                    'featured_media' => $roomType->featuredMedia,
                    'indicative_starting_rate' => $roomType->starting_rate,
                    'currency_code' => $roomType->currency_code,
                ],
                'available_rooms' => $inventory['minimum_available'],
                'lowest_total_minor' => $lowestQuote['total_minor'],
                'lowest_total_amount' => $lowestQuote['total_amount'],
                'rate_plans' => $ratePlanQuotes,
            ];
        }

        return [
            'search' => [
                'check_in_date' => $checkInDate,
                'check_out_date' => $checkOutDate,
                'nights' => count($stayDates),
                'adults' => $adults,
                'children' => $children,
                'rooms_count' => $roomsCount,
            ],
            'options' => $results,
        ];
    }

    /**
     * Build an explicit cancellation policy snapshot without fabricating hotel rules when unconfigured.
     */
    public function buildCancellationPolicySnapshot(RatePlan $ratePlan, ?string $defaultPolicyNote = null): array
    {
        $type = $ratePlan->cancellation_policy_type ?: 'unconfigured';
        $configured = $type !== 'unconfigured' || filled($ratePlan->cancellation_terms) || filled($defaultPolicyNote);

        return [
            'configured' => $configured,
            'policy_type' => $type,
            'is_refundable' => (bool) $ratePlan->is_refundable,
            'free_cancellation_hours' => $ratePlan->free_cancellation_hours,
            'penalty_type' => $ratePlan->cancellation_penalty_type,
            'penalty_value' => $ratePlan->cancellation_penalty_value,
            'terms_text' => $ratePlan->cancellation_terms
                ?: $defaultPolicyNote
                ?: 'Cancellation terms have not yet been configured by hotel administration. Please contact the hotel reservation desk before cancelling or amending your stay.',
        ];
    }

    /**
     * Compute cancellation fee minor units at the time of cancellation based on the reservation's stored snapshot.
     */
    public function calculateCancellationFeeMinor(Reservation $reservation): int
    {
        $policy = $reservation->cancellation_policy_snapshot ?? [];
        if (empty($policy) || empty($policy['configured'])) {
            return 0;
        }

        $freeHours = $policy['free_cancellation_hours'] ?? null;
        if ($freeHours !== null && is_numeric($freeHours)) {
            $checkInTime = ($this->settings->all()['booking']['check_in_time'] ?? '14:00');
            $deadline = CarbonImmutable::parse($reservation->check_in_date->format('Y-m-d').' '.$checkInTime)
                ->subHours((int) $freeHours);
            if (now()->lte($deadline)) {
                return 0;
            }
        }

        if (! ($policy['is_refundable'] ?? true)) {
            return (int) $reservation->total_minor;
        }

        $penaltyType = $policy['penalty_type'] ?? 'none';
        $penaltyValue = (float) ($policy['penalty_value'] ?? 0);

        return match ($penaltyType) {
            'full_stay' => (int) $reservation->total_minor,
            'first_night' => (int) (($reservation->price_snapshot['nightly_breakdown'][0]['total_night_minor'] ?? 0) * $reservation->rooms_count),
            'percentage' => (int) round($reservation->total_minor * (min(100, max(0, $penaltyValue)) / 100)),
            default => 0,
        };
    }
}
