<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\AuditLogger;
use App\Services\AvailabilityService;
use App\Services\ReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ReservationController extends Controller
{
    public function __construct(
        private AvailabilityService $availability,
        private ReservationService $reservations,
        private AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['reservations.view', 'frontdesk.view'])) {
            abort(403, 'You do not have permission to view reservations.');
        }

        $q = Reservation::query()
            ->with(['roomType', 'ratePlan', 'assignedRoom']);

        if ($search = trim((string) $request->query('search', ''))) {
            $q->where(function ($sub) use ($search) {
                $sub->where('reference', 'like', "%{$search}%")
                    ->orWhere('guest_name', 'like', "%{$search}%")
                    ->orWhere('guest_email', 'like', "%{$search}%")
                    ->orWhere('guest_phone', 'like', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $q->where('status', (string) $status);
        }
        if ($paymentStatus = $request->query('payment_status')) {
            $q->where('payment_status', (string) $paymentStatus);
        }
        if ($source = $request->query('source')) {
            $q->where('source', (string) $source);
        }
        if ($roomTypeId = $request->query('room_type_id')) {
            $q->where('room_type_id', (int) $roomTypeId);
        }
        if ($arrivalFrom = $request->query('arrival_from')) {
            $q->whereDate('check_in_date', '>=', (string) $arrivalFrom);
        }
        if ($arrivalTo = $request->query('arrival_to')) {
            $q->whereDate('check_in_date', '<=', (string) $arrivalTo);
        }
        if ($departureFrom = $request->query('departure_from')) {
            $q->whereDate('check_out_date', '>=', (string) $departureFrom);
        }
        if ($departureTo = $request->query('departure_to')) {
            $q->whereDate('check_out_date', '<=', (string) $departureTo);
        }

        $paginated = $q->orderByDesc('check_in_date')->orderByDesc('id')->paginate(25);
        $paginated->getCollection()->each(function (Reservation $r) {
            $r->makeVisible(['internal_notes']);
            $r->setAttribute('balance_due_amount', AvailabilityService::toMajor($r->balanceDueMinor()));
        });

        return response()->json($paginated);
    }

    public function show(Request $request, Reservation $reservation): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['reservations.view', 'frontdesk.view'])) {
            abort(403, 'You do not have permission to view reservations.');
        }

        $reservation->load([
            'guest.reservations' => fn ($q) => $q->where('id', '!=', $reservation->id)->latest()->limit(10),
            'roomType',
            'ratePlan',
            'assignedRoom',
            'nightsAllocations.room',
            'statusHistories.actor:id,name',
            'payments.recorder:id,name',
            'refunds.requester:id,name',
            'refunds.reviewer:id,name',
            'folio',
            'housekeepingTasks.assignee:id,name',
        ])->makeVisible(['internal_notes']);

        if ($reservation->guest && $request->user()->hasPermission('guests.sensitive')) {
            $reservation->guest->makeVisible(['id_document_type', 'id_document_number', 'internal_notes']);
        }

        // Also provide assignable physical rooms for this reservation's stay window
        $stayDates = $this->availability->parseStayDates(
            $reservation->check_in_date->format('Y-m-d'),
            $reservation->check_out_date->format('Y-m-d'),
            allowPastForStaff: true,
        );
        $assignableRooms = $reservation->roomType
            ? $this->availability->getAssignableRooms($reservation->roomType, $stayDates, $reservation->id)
            : collect();

        $reservation->setAttribute('balance_due_amount', AvailabilityService::toMajor($reservation->balanceDueMinor()));
        $reservation->setAttribute('assignable_rooms', $assignableRooms);

        return response()->json(['data' => $reservation]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('reservations.create');

        $data = $request->validate([
            'room_type_id' => ['required', 'integer', 'exists:room_types,id'],
            'rate_plan_id' => ['required', 'integer', 'exists:rate_plans,id'],
            'assigned_room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'check_in_date' => ['required', 'date_format:Y-m-d'],
            'check_out_date' => ['required', 'date_format:Y-m-d'],
            'adults' => ['required', 'integer', 'min:1', 'max:20'],
            'children' => ['nullable', 'integer', 'min:0', 'max:20'],
            'rooms_count' => ['nullable', 'integer', 'min:1', 'max:10'],
            'source' => ['nullable', 'string', Rule::in(['walk_in', 'phone', 'staff', 'corporate', 'online'])],
            'guest_name' => ['required', 'string', 'min:2', 'max:160'],
            'guest_email' => ['required', 'email', 'max:255'],
            'guest_phone' => ['required', 'string', 'min:5', 'max:40', 'regex:/^[0-9+()\s-]*$/'],
            'guest_nationality' => ['nullable', 'string', 'max:80'],
            'special_requests' => ['nullable', 'string', 'max:1500'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['nullable', 'string', 'max:96'],
        ]);

        if (! empty($data['assigned_room_id']) && ! $request->user()->hasPermission('reservations.assign')) {
            abort(403, 'You do not have permission to assign physical rooms.');
        }

        $res = $this->reservations->createReservation($data, $request->user(), isPublic: false);
        $res->makeVisible(['internal_notes']);

        return response()->json(['message' => "Reservation {$res->reference} created.", 'data' => $res], 201);
    }

    public function update(Request $request, Reservation $reservation): JsonResponse
    {
        Gate::authorize('reservations.edit');

        $data = $request->validate([
            'room_type_id' => ['sometimes', 'integer', 'exists:room_types,id'],
            'rate_plan_id' => ['sometimes', 'integer', 'exists:rate_plans,id'],
            'assigned_room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'check_in_date' => ['sometimes', 'date_format:Y-m-d'],
            'check_out_date' => ['sometimes', 'date_format:Y-m-d'],
            'adults' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'children' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'rooms_count' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'guest_name' => ['sometimes', 'string', 'min:2', 'max:160'],
            'guest_email' => ['sometimes', 'email', 'max:255'],
            'guest_phone' => ['sometimes', 'string', 'min:5', 'max:40', 'regex:/^[0-9+()\s-]*$/'],
            'special_requests' => ['nullable', 'string', 'max:1500'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
            'amendment_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $amended = $this->reservations->amendReservation($reservation, $data, $request->user());
        $amended->makeVisible(['internal_notes']);

        return response()->json(['message' => 'Reservation amended.', 'data' => $amended]);
    }

    public function assignRoom(Request $request, Reservation $reservation): JsonResponse
    {
        Gate::authorize('reservations.assign');

        $data = $request->validate([
            'assigned_room_id' => ['nullable', 'integer', 'exists:rooms,id'],
        ]);

        $updated = $this->reservations->assignRoom($reservation, $data['assigned_room_id'] ?? null, $request->user());
        $updated->makeVisible(['internal_notes']);

        return response()->json(['message' => 'Room assignment updated.', 'data' => $updated]);
    }

    public function checkIn(Request $request, Reservation $reservation): JsonResponse
    {
        Gate::authorize('frontdesk.checkin');

        $data = $request->validate([
            'assigned_room_id' => ['nullable', 'integer', 'exists:rooms,id'],
        ]);

        $updated = $this->reservations->checkIn($reservation, $data['assigned_room_id'] ?? null, $request->user());
        $updated->makeVisible(['internal_notes']);

        return response()->json(['message' => 'Guest checked in.', 'data' => $updated]);
    }

    public function checkOut(Request $request, Reservation $reservation): JsonResponse
    {
        Gate::authorize('frontdesk.checkout');

        $updated = $this->reservations->checkOut($reservation, $request->user());
        $updated->makeVisible(['internal_notes']);

        return response()->json(['message' => 'Guest checked out.', 'data' => $updated]);
    }

    public function cancel(Request $request, Reservation $reservation): JsonResponse
    {
        Gate::authorize('reservations.cancel');

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $updated = $this->reservations->cancelReservation($reservation, $data['reason'], $request->user());
        $updated->makeVisible(['internal_notes']);

        return response()->json(['message' => 'Reservation cancelled.', 'data' => $updated]);
    }

    public function noShow(Request $request, Reservation $reservation): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['reservations.cancel', 'frontdesk.checkin'])) {
            abort(403, 'You do not have permission to record no-shows.');
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $updated = $this->reservations->markNoShow($reservation, $data['reason'], $request->user());
        $updated->makeVisible(['internal_notes']);

        return response()->json(['message' => 'Reservation marked as no-show.', 'data' => $updated]);
    }

    public function frontDeskSummary(Request $request): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['frontdesk.view', 'reservations.view'])) {
            abort(403, 'You do not have permission to view front desk operations.');
        }

        $today = $request->query('date', now()->format('Y-m-d'));

        $arrivals = Reservation::with(['roomType', 'ratePlan', 'assignedRoom'])
            ->whereDate('check_in_date', $today)
            ->whereIn('status', [Reservation::STATUS_CONFIRMED, Reservation::STATUS_PENDING_PAYMENT, Reservation::STATUS_CHECKED_IN])
            ->orderBy('guest_name')
            ->get()
            ->each(fn (Reservation $r) => $r->setAttribute('balance_due_amount', AvailabilityService::toMajor($r->balanceDueMinor())));

        $departures = Reservation::with(['roomType', 'ratePlan', 'assignedRoom'])
            ->whereDate('check_out_date', $today)
            ->whereIn('status', [Reservation::STATUS_CHECKED_IN, Reservation::STATUS_CHECKED_OUT])
            ->orderBy('guest_name')
            ->get()
            ->each(fn (Reservation $r) => $r->setAttribute('balance_due_amount', AvailabilityService::toMajor($r->balanceDueMinor())));

        $inHouse = Reservation::with(['roomType', 'ratePlan', 'assignedRoom'])
            ->where('status', Reservation::STATUS_CHECKED_IN)
            ->orderBy('check_out_date')
            ->get()
            ->each(fn (Reservation $r) => $r->setAttribute('balance_due_amount', AvailabilityService::toMajor($r->balanceDueMinor())));

        return response()->json([
            'data' => [
                'date' => $today,
                'arrivals' => $arrivals,
                'departures' => $departures,
                'in_house' => $inHouse,
            ],
        ]);
    }

    public function availabilityMatrix(Request $request): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['reservations.view', 'rooms.view', 'frontdesk.view'])) {
            abort(403, 'You do not have permission to view availability.');
        }

        $startDate = (string) $request->query('start_date', now()->format('Y-m-d'));
        $endDate = (string) $request->query('end_date', now()->addDays(14)->format('Y-m-d'));
        $stayDates = $this->availability->parseStayDates($startDate, $endDate, allowPastForStaff: true);

        $roomTypes = RoomType::orderBy('sort_order')->orderBy('id')->get();
        $matrix = [];

        foreach ($roomTypes as $rt) {
            $inv = $this->availability->getRoomTypeInventory($rt, $stayDates);
            $matrix[] = [
                'room_type' => [
                    'id' => $rt->id,
                    'name' => $rt->name,
                    'slug' => $rt->slug,
                    'code' => $rt->code,
                    'is_active' => $rt->is_active,
                ],
                'total_physical_rooms' => $inv['total_physical_rooms'],
                'minimum_available' => $inv['minimum_available'],
                'nights' => $inv['nights'],
            ];
        }

        return response()->json([
            'data' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'dates' => $stayDates,
                'matrix' => $matrix,
            ],
        ]);
    }

    public function guests(Request $request): JsonResponse
    {
        Gate::authorize('guests.view');

        $canSensitive = $request->user()->hasPermission('guests.sensitive');
        $search = trim((string) $request->query('search', ''));

        $guests = Guest::query()
            ->withCount('reservations')
            ->when($search !== '', fn ($q) => $q->where('full_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%"))
            ->orderByDesc('id')
            ->paginate(25);

        if ($canSensitive) {
            $guests->getCollection()->each(fn (Guest $g) => $g->makeVisible(['id_document_type', 'id_document_number', 'internal_notes']));
        }

        return response()->json($guests);
    }

    public function updateGuest(Request $request, Guest $guest): JsonResponse
    {
        Gate::authorize('guests.edit');

        $canSensitive = $request->user()->hasPermission('guests.sensitive');

        $rules = [
            'full_name' => ['required', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'nationality' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:80'],
            'state' => ['nullable', 'string', 'max:80'],
            'country' => ['nullable', 'string', 'max:80'],
            'preferences' => ['nullable', 'string', 'max:1500'],
            'is_vip' => ['boolean'],
        ];

        if ($canSensitive) {
            $rules['id_document_type'] = ['nullable', 'string', 'max:48'];
            $rules['id_document_number'] = ['nullable', 'string', 'max:80'];
            $rules['internal_notes'] = ['nullable', 'string', 'max:2000'];
        }

        $data = $request->validate($rules);
        $guest->update($data);

        if ($canSensitive) {
            $guest->makeVisible(['id_document_type', 'id_document_number', 'internal_notes']);
        }

        $this->audit->log('guest.updated', $guest, ['guest_id' => $guest->id]);

        return response()->json(['message' => 'Guest profile updated.', 'data' => $guest]);
    }
}
