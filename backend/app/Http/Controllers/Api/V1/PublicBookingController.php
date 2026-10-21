<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\AuditLogger;
use App\Services\AvailabilityService;
use App\Services\PaymentService;
use App\Services\ReservationService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Customer-facing Online Booking, Availability, Quotes, Holds & Reservation Lookup Controller.
 * Never exposes internal room notes, physical room numbers, or guest PII without an unguessable access token.
 */
class PublicBookingController extends Controller
{
    public function __construct(
        private AvailabilityService $availability,
        private ReservationService $reservations,
        private PaymentService $payments,
        private SettingsService $settings,
        private AuditLogger $audit,
    ) {}

    public function roomTypes(): JsonResponse
    {
        $roomTypes = RoomType::publiclyVisible()
            ->with(['featuredMedia', 'ratePlans' => fn ($q) => $q->publiclyVisible()->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (RoomType $rt) => [
                'id' => $rt->id,
                'name' => $rt->name,
                'slug' => $rt->slug,
                'code' => $rt->code,
                'subtitle' => $rt->subtitle,
                'description' => $rt->public_description ?: $rt->description,
                'max_adults' => $rt->max_adults,
                'max_children' => $rt->max_children,
                'max_occupancy' => $rt->max_occupancy,
                'base_occupancy' => $rt->base_occupancy,
                'bed_configuration' => $rt->bed_configuration,
                'size_label' => $rt->size_label,
                'amenities' => $rt->amenities ?? [],
                'featured_media' => $rt->featuredMedia,
                'indicative_starting_rate' => $rt->starting_rate,
                'currency_code' => $rt->currency_code,
                'rate_plans' => $rt->ratePlans->map(fn (RatePlan $rp) => [
                    'id' => $rp->id,
                    'name' => $rp->name,
                    'code' => $rp->code,
                    'description' => $rp->description,
                    'meal_basis' => $rp->meal_Basis,
                    'is_refundable' => $rp->is_refundable,
                    'min_stay_nights' => $rp->min_stay_nights,
                    'max_stay_nights' => $rp->max_stay_nights,
                    'base_rate' => (float) ($rp->pivot->base_rate ?? $rp->default_base_rate),
                ])->values(),
            ]);

        return response()->json(['data' => $roomTypes]);
    }

    public function ratePlans(): JsonResponse
    {
        $plans = RatePlan::publiclyVisible()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (RatePlan $rp) => [
                'id' => $rp->id,
                'name' => $rp->name,
                'code' => $rp->code,
                'description' => $rp->description,
                'meal_basis' => $rp->meal_Basis,
                'is_refundable' => $rp->is_refundable,
                'min_stay_nights' => $rp->min_stay_nights,
                'max_stay_nights' => $rp->max_stay_nights,
                'cancellation_policy' => $this->availability->buildCancellationPolicySnapshot($rp),
            ]);

        return response()->json(['data' => $plans]);
    }

    public function availability(Request $request): JsonResponse
    {
        $data = $request->validate([
            'check_in_date' => ['required', 'date_format:Y-m-d'],
            'check_out_date' => ['required', 'date_format:Y-m-d'],
            'adults' => ['nullable', 'integer', 'min:1', 'max:20'],
            'children' => ['nullable', 'integer', 'min:0', 'max:20'],
            'rooms_count' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $bookingSettings = $this->settings->all()['booking'] ?? [];
        $maxRooms = max(1, (int) ($bookingSettings['max_rooms_per_booking'] ?? 5));
        $roomsCount = (int) ($data['rooms_count'] ?? 1);

        if ($roomsCount > $maxRooms) {
            throw ValidationException::withMessages([
                'rooms_count' => ["You may request at most {$maxRooms} room(s) in a single online booking."],
            ]);
        }

        $result = $this->availability->searchAvailableOptions(
            checkInDate: $data['check_in_date'],
            checkOutDate: $data['check_out_date'],
            adults: (int) ($data['adults'] ?? 1),
            children: (int) ($data['children'] ?? 0),
            roomsCount: $roomsCount,
            publicOnly: true,
            allowPastForStaff: false,
        );

        return response()->json(['data' => $result]);
    }

    public function createQuote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_type_id' => ['required', 'integer', 'exists:room_types,id'],
            'rate_plan_id' => ['required', 'integer', 'exists:rate_plans,id'],
            'check_in_date' => ['required', 'date_format:Y-m-d'],
            'check_out_date' => ['required', 'date_format:Y-m-d'],
            'adults' => ['required', 'integer', 'min:1', 'max:20'],
            'children' => ['nullable', 'integer', 'min:0', 'max:20'],
            'rooms_count' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        /** @var RoomType $roomType */
        $roomType = RoomType::findOrFail((int) $data['room_type_id']);
        /** @var RatePlan $ratePlan */
        $ratePlan = RatePlan::findOrFail((int) $data['rate_plan_id']);

        $quote = $this->availability->calculateQuote(
            roomType: $roomType,
            ratePlan: $ratePlan,
            checkInDate: $data['check_in_date'],
            checkOutDate: $data['check_out_date'],
            adults: (int) $data['adults'],
            children: (int) ($data['children'] ?? 0),
            roomsCount: (int) ($data['rooms_count'] ?? 1),
            publicOnly: true,
            allowPastForStaff: false,
        );

        return response()->json(['data' => $quote]);
    }

    public function createHold(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_type_id' => ['required', 'integer', 'exists:room_types,id'],
            'rate_plan_id' => ['required', 'integer', 'exists:rate_plans,id'],
            'check_in_date' => ['required', 'date_format:Y-m-d'],
            'check_out_date' => ['required', 'date_format:Y-m-d'],
            'adults' => ['required', 'integer', 'min:1', 'max:20'],
            'children' => ['nullable', 'integer', 'min:0', 'max:20'],
            'rooms_count' => ['nullable', 'integer', 'min:1', 'max:10'],
            'guest_name' => ['nullable', 'string', 'max:160'],
            'guest_email' => ['nullable', 'email', 'max:255'],
            'guest_phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\s-]*$/'],
            'idempotency_key' => ['nullable', 'string', 'max:96'],
        ]);

        $hold = $this->reservations->createHold($data, $request->ip(), publicOnly: true);

        return response()->json([
            'data' => [
                'hold_token' => $hold->hold_token,
                'status' => $hold->status,
                'expires_at' => $hold->expires_at->toIso8601String(),
                'quote' => $hold->quote_snapshot,
            ],
        ], 201);
    }

    public function createReservation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hold_token' => ['nullable', 'string', 'max:64'],
            'idempotency_key' => ['nullable', 'string', 'max:96'],
            'room_type_id' => ['required_without:hold_token', 'integer', 'exists:room_types,id'],
            'rate_plan_id' => ['required_without:hold_token', 'integer', 'exists:rate_plans,id'],
            'check_in_date' => ['required_without:hold_token', 'date_format:Y-m-d'],
            'check_out_date' => ['required_without:hold_token', 'date_format:Y-m-d'],
            'adults' => ['required_without:hold_token', 'integer', 'min:1', 'max:20'],
            'children' => ['nullable', 'integer', 'min:0', 'max:20'],
            'rooms_count' => ['nullable', 'integer', 'min:1', 'max:10'],
            'guest_name' => ['required', 'string', 'min:2', 'max:160'],
            'guest_email' => ['required', 'email', 'max:255'],
            'guest_phone' => ['required', 'string', 'min:5', 'max:40', 'regex:/^[0-9+()\s-]*$/'],
            'special_requests' => ['nullable', 'string', 'max:1500'],
        ]);

        $res = $this->reservations->createReservation($data, actor: null, isPublic: true);

        return response()->json([
            'message' => 'Your reservation has been created.',
            'data' => $this->formatPublicReservation($res, includeToken: true),
        ], 201);
    }

    public function showReservation(Request $request, string $reference): JsonResponse
    {
        $res = $this->resolvePublicReservationAuthorized($request, $reference);

        return response()->json(['data' => $this->formatPublicReservation($res, includeToken: false)]);
    }

    public function requestCancellation(Request $request, string $reference): JsonResponse
    {
        $res = $this->resolvePublicReservationAuthorized($request, $reference);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $cancelled = $this->reservations->cancelReservation($res, 'Guest online cancellation: '.$data['reason'], actor: null);

        return response()->json([
            'message' => 'Your reservation has been cancelled.',
            'data' => $this->formatPublicReservation($cancelled, includeToken: false),
        ]);
    }

    public function webhook(Request $request, string $provider): JsonResponse
    {
        $raw = (string) $request->getContent();
        $sig = $provider === 'paystack'
            ? $request->header('x-paystack-signature')
            : $request->header('verif-hash');

        $result = $this->payments->handleWebhook($provider, $raw, $sig, $request->all());

        return response()->json(['data' => $result]);
    }

    private function resolvePublicReservationAuthorized(Request $request, string $reference): Reservation
    {
        $token = (string) ($request->query('token') ?: $request->input('token') ?: $request->header('X-Reservation-Token'));
        $email = strtolower(trim((string) ($request->query('email') ?: $request->input('email'))));

        /** @var Reservation|null $res */
        $res = Reservation::where('reference', strtoupper(trim($reference)))
            ->with(['roomType', 'ratePlan'])
            ->first();

        if (! $res) {
            abort(404, 'Reservation not found.');
        }

        $tokenValid = $token !== '' && hash_equals($res->public_access_token, $token);
        $emailValid = $email !== '' && hash_equals(strtolower($res->guest_email), $email) && strlen($token) >= 16 && hash_equals($res->public_access_token, $token);

        if (! $tokenValid && ! $emailValid) {
            abort(403, 'A valid reservation security token is required to view or manage this booking.');
        }

        return $res;
    }

    private function formatPublicReservation(Reservation $res, bool $includeToken = false): array
    {
        return [
            'reference' => $res->reference,
            'access_token' => $includeToken ? $res->public_access_token : null,
            'status' => $res->status,
            'payment_status' => $res->payment_status,
            'guest_name' => $res->guest_name,
            'guest_email' => $res->guest_email,
            'guest_phone' => $res->guest_phone,
            'check_in_date' => $res->check_in_date->format('Y-m-d'),
            'check_out_date' => $res->check_out_date->format('Y-m-d'),
            'nights' => $res->nights,
            'rooms_count' => $res->rooms_count,
            'adults' => $res->adults,
            'children' => $res->children,
            'room_type' => $res->roomType ? [
                'id' => $res->roomType->id,
                'name' => $res->roomType->name,
                'slug' => $res->roomType->slug,
                'bed_configuration' => $res->roomType->bed_configuration,
            ] : null,
            'rate_plan' => $res->ratePlan ? [
                'id' => $res->ratePlan->id,
                'name' => $res->ratePlan->name,
                'code' => $res->ratePlan->code,
                'meal_basis' => $res->ratePlan->meal_Basis,
            ] : null,
            'currency_code' => $res->currency_code,
            'subtotal_amount' => $res->subtotal_amount,
            'taxes_amount' => $res->taxes_amount,
            'fees_amount' => $res->fees_amount,
            'total_amount' => $res->total_amount,
            'amount_paid' => $res->amount_paid,
            'amount_refunded' => $res->amount_refunded,
            'balance_due_amount' => AvailabilityService::toMajor($res->balanceDueMinor()),
            'price_snapshot' => $res->price_snapshot,
            'cancellation_policy' => $res->cancellation_policy_snapshot,
            'special_requests' => $res->special_requests,
            'created_at' => $res->created_at?->toIso8601String(),
        ];
    }
}
