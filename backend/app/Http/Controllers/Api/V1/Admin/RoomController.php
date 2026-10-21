<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\ReservationNight;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\AuditLogger;
use App\Services\AvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RoomController extends Controller
{
    public function __construct(
        private AvailabilityService $availability,
        private AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['rooms.view', 'housekeeping.view', 'frontdesk.view'])) {
            abort(403, 'You do not have permission to view rooms.');
        }

        $rooms = Room::query()
            ->with('roomType')
            ->when($request->filled('room_type_id'), fn ($q) => $q->where('room_type_id', (int) $request->query('room_type_id')))
            ->when($request->filled('operational_status'), fn ($q) => $q->where('operational_status', (string) $request->query('operational_status')))
            ->when($request->filled('housekeeping_status'), fn ($q) => $q->where('housekeeping_status', (string) $request->query('housekeeping_status')))
            ->orderBy('sort_order')
            ->orderBy('room_number')
            ->get();

        return response()->json(['data' => $rooms]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('rooms.create');

        $data = $this->validatePayload($request);

        $room = Room::create([
            ...$data,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        $this->audit->log('room.created', $room, [
            'room_number' => $room->room_number,
            'room_type_id' => $room->room_type_id,
            'operational_status' => $room->operational_status,
        ]);

        return response()->json(['message' => 'Physical room created.', 'data' => $room->load('roomType')], 201);
    }

    public function update(Request $request, Room $room): JsonResponse
    {
        // Allow housekeeping staff to update housekeeping_status only
        if (! $request->user()->hasPermission('rooms.edit') && $request->user()->hasAnyPermission(['housekeeping.update', 'housekeeping.manage'])) {
            $hk = $request->validate([
                'housekeeping_status' => ['required', 'string', Rule::in(['clean', 'dirty', 'inspecting', 'inspected', 'maintenance'])],
            ]);
            $room->update([
                'housekeeping_status' => $hk['housekeeping_status'],
                'updated_by' => $request->user()->id,
            ]);
            $this->audit->log('room.housekeeping_updated', $room, ['room_number' => $room->room_number, 'housekeeping_status' => $room->housekeeping_status]);

            return response()->json(['message' => 'Housekeeping status updated.', 'data' => $room->load('roomType')]);
        }

        Gate::authorize('rooms.edit');
        $data = $this->validatePayload($request, $room->id);

        DB::transaction(function () use ($room, $data, $request) {
            $newRoomTypeId = (int) $data['room_type_id'];
            $newOpStatus = (string) $data['operational_status'];
            $today = now()->format('Y-m-d');

            // 1. Lock both old and new room-type pools in ascending ID order FIRST so taking a room offline or changing its type cannot deadlock or race with concurrent bookings
            $lockedTypes = RoomType::whereIn('id', array_unique([$room->room_type_id, $newRoomTypeId]))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $oldRoomType = $lockedTypes->get($room->room_type_id) ?? RoomType::findOrFail($room->room_type_id);
            $lockedRoom = Room::where('id', $room->id)->lockForUpdate()->firstOrFail();

            // 2. Check direct room assignment conflicts if changing room_type or marking inactive / out_of_service
            if ($newRoomTypeId !== $lockedRoom->room_type_id || $newOpStatus !== 'active') {
                $assignedConflicts = Reservation::where('assigned_room_id', $lockedRoom->id)
                    ->whereIn('status', Reservation::INVENTORY_CONSUMING_STATUSES)
                    ->where('check_out_date', '>', $today)
                    ->lockForUpdate()
                    ->get();

                foreach ($assignedConflicts as $conflict) {
                    if ($newRoomTypeId !== $lockedRoom->room_type_id || $newOpStatus === 'inactive') {
                        throw ValidationException::withMessages([
                            'operational_status' => ["Room {$lockedRoom->room_number} is assigned to active reservation {$conflict->reference} ({$conflict->check_in_date->format('Y-m-d')} to {$conflict->check_out_date->format('Y-m-d')}). Reassign the reservation first."],
                        ]);
                    }
                    // If out_of_service, check date overlap
                    $oosFrom = $data['out_of_service_from'] ?? null;
                    $oosUntil = $data['out_of_service_until'] ?? null;
                    $in = $conflict->check_in_date->format('Y-m-d');
                    $out = $conflict->check_out_date->format('Y-m-d');
                    $overlaps = (! $oosFrom && ! $oosUntil)
                        || ($oosFrom && ! $oosUntil && $out > $oosFrom)
                        || (! $oosFrom && $oosUntil && $in <= $oosUntil)
                        || ($oosFrom && $oosUntil && $in <= $oosUntil && $out > $oosFrom);

                    if ($overlaps) {
                        throw ValidationException::withMessages([
                            'operational_status' => ["Cannot mark Room {$lockedRoom->room_number} out of service for dates overlapping assigned reservation {$conflict->reference}."],
                        ]);
                    }
                }
            }

            $room->update([
                ...$data,
                'updated_by' => $request->user()->id,
            ]);

            $activeReservationIds = Reservation::where('room_type_id', $oldRoomType->id)
                ->whereIn('status', Reservation::INVENTORY_CONSUMING_STATUSES)
                ->where('check_out_date', '>', $today)
                ->lockForUpdate()
                ->get(['id'])
                ->pluck('id')
                ->all();

            $futureStayDates = empty($activeReservationIds)
                ? []
                : ReservationNight::where('room_type_id', $oldRoomType->id)
                    ->where('status', 'active')
                    ->where('stay_date', '>=', $today)
                    ->whereIn('reservation_id', $activeReservationIds)
                    ->lockForUpdate()
                    ->get(['stay_date'])
                    ->map(fn (ReservationNight $n) => $n->stay_date instanceof \DateTimeInterface ? $n->stay_date->format('Y-m-d') : (string) $n->stay_date)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

            if (! empty($futureStayDates)) {
                $roomsOfOldType = Room::where('room_type_id', $oldRoomType->id)->lockForUpdate()->get();
                foreach ($futureStayDates as $stayDate) {
                    $sellable = $roomsOfOldType->filter(fn (Room $r) => $r->isSellableOnDate($stayDate))->count();
                    $reserved = ReservationNight::where('room_type_id', $oldRoomType->id)
                        ->where('status', 'active')
                        ->where('stay_date', $stayDate)
                        ->whereIn('reservation_id', $activeReservationIds)
                        ->lockForUpdate()
                        ->get(['id'])
                        ->count();

                    if ($reserved > $sellable) {
                        throw ValidationException::withMessages([
                            'operational_status' => ["Taking Room {$room->room_number} out of {$oldRoomType->name} inventory would leave {$reserved} confirmed reservation(s) with only {$sellable} sellable room(s) on {$stayDate}."],
                        ]);
                    }
                }
            }
        });

        $this->audit->log('room.updated', $room, [
            'room_number' => $room->room_number,
            'operational_status' => $room->operational_status,
            'housekeeping_status' => $room->housekeeping_status,
        ]);

        return response()->json(['message' => 'Room updated.', 'data' => $room->fresh('roomType')]);
    }

    public function destroy(Room $room): JsonResponse
    {
        Gate::authorize('rooms.edit');

        if ($room->reservations()->exists() || $room->reservationNights()->exists()) {
            throw ValidationException::withMessages([
                'room' => ["Room {$room->room_number} has reservation history and cannot be deleted. Set its status to inactive instead."],
            ]);
        }

        $this->audit->log('room.deleted', $room, ['room_number' => $room->room_number]);
        $room->delete();

        return response()->json(['message' => 'Room deleted.']);
    }

    private function validatePayload(Request $request, ?int $ignoreId = null): array
    {
        $v = $request->validate([
            'room_number' => ['required', 'string', 'max:32', Rule::unique('rooms', 'room_number')->ignore($ignoreId)],
            'room_type_id' => ['required', 'integer', 'exists:room_types,id'],
            'floor' => ['nullable', 'string', 'max:32'],
            'building' => ['nullable', 'string', 'max:64'],
            'operational_status' => ['required', 'string', Rule::in(['active', 'inactive', 'out_of_service'])],
            'housekeeping_status' => ['required', 'string', Rule::in(['clean', 'dirty', 'inspecting', 'inspected', 'maintenance'])],
            'out_of_service_from' => ['nullable', 'date_format:Y-m-d'],
            'out_of_service_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:out_of_service_from'],
            'out_of_service_reason' => ['nullable', 'string', 'max:255'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        return [
            'room_number' => trim(strip_tags($v['room_number'])),
            'room_type_id' => (int) $v['room_type_id'],
            'floor' => isset($v['floor']) ? strip_tags($v['floor']) : null,
            'building' => isset($v['building']) ? strip_tags($v['building']) : null,
            'operational_status' => $v['operational_status'],
            'housekeeping_status' => $v['housekeeping_status'],
            'out_of_service_from' => $v['operational_status'] === 'out_of_service' ? ($v['out_of_service_from'] ?? null) : null,
            'out_of_service_until' => $v['operational_status'] === 'out_of_service' ? ($v['out_of_service_until'] ?? null) : null,
            'out_of_service_reason' => $v['operational_status'] === 'out_of_service' && isset($v['out_of_service_reason'])
                ? strip_tags($v['out_of_service_reason'])
                : null,
            'internal_notes' => isset($v['internal_notes']) ? strip_tags($v['internal_notes']) : null,
            'sort_order' => (int) ($v['sort_order'] ?? 0),
        ];
    }
}
