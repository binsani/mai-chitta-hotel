<?php

namespace App\Services;

use App\Models\MaintenanceWorkOrder;
use App\Models\MaintenanceWorkOrderHistory;
use App\Models\OperationalNotification;
use App\Models\Reservation;
use App\Models\ReservationNight;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Transactional Maintenance Work-Order Service with Room & Inventory Integration.
 *
 * State Machine:
 *   reported -> acknowledged -> assigned -> in_progress -> awaiting_parts -> resolved -> closed
 *   (with direct transitions from reported/acknowledged/assigned/awaiting_parts to in_progress or resolved,
 *   cancellation of open orders, and authorized reopening of resolved/closed orders).
 *
 * Room Blocking & Safe Return-to-Service:
 * - When a work order blocks a room (`blocks_room = true`), it locks the RoomType and Room in deterministic
 *   order (`RoomType -> Room`), sets the room's `housekeeping_status = 'maintenance'` (and optionally
 *   `operational_status = 'out_of_service'` when no confirmed pool deficit occurs), and detects any
 *   overlapping confirmed reservations assigned to that room (`has_arrival_conflict = true`) without
 *   invalidating the confirmed reservation.
 * - When the last blocking work order on a room is resolved or closed, the room's maintenance block is
 *   cleared, `housekeeping_status` is set to `'dirty'` (never directly `'clean'`), and a follow-up
 *   `maintenance_followup` housekeeping task is automatically created so housekeeping cleans and inspects
 *   the room before a guest can check in.
 */
class MaintenanceService
{
    public const ALLOWED_TRANSITIONS = [
        MaintenanceWorkOrder::STATUS_REPORTED => [
            MaintenanceWorkOrder::STATUS_ACKNOWLEDGED,
            MaintenanceWorkOrder::STATUS_ASSIGNED,
            MaintenanceWorkOrder::STATUS_IN_PROGRESS,
            MaintenanceWorkOrder::STATUS_CANCELLED,
        ],
        MaintenanceWorkOrder::STATUS_ACKNOWLEDGED => [
            MaintenanceWorkOrder::STATUS_ASSIGNED,
            MaintenanceWorkOrder::STATUS_IN_PROGRESS,
            MaintenanceWorkOrder::STATUS_AWAITING_PARTS,
            MaintenanceWorkOrder::STATUS_RESOLVED,
            MaintenanceWorkOrder::STATUS_CANCELLED,
        ],
        MaintenanceWorkOrder::STATUS_ASSIGNED => [
            MaintenanceWorkOrder::STATUS_ASSIGNED,
            MaintenanceWorkOrder::STATUS_IN_PROGRESS,
            MaintenanceWorkOrder::STATUS_AWAITING_PARTS,
            MaintenanceWorkOrder::STATUS_RESOLVED,
            MaintenanceWorkOrder::STATUS_CANCELLED,
        ],
        MaintenanceWorkOrder::STATUS_IN_PROGRESS => [
            MaintenanceWorkOrder::STATUS_AWAITING_PARTS,
            MaintenanceWorkOrder::STATUS_RESOLVED,
            MaintenanceWorkOrder::STATUS_CANCELLED,
        ],
        MaintenanceWorkOrder::STATUS_AWAITING_PARTS => [
            MaintenanceWorkOrder::STATUS_IN_PROGRESS,
            MaintenanceWorkOrder::STATUS_RESOLVED,
            MaintenanceWorkOrder::STATUS_CANCELLED,
        ],
        MaintenanceWorkOrder::STATUS_RESOLVED => [
            MaintenanceWorkOrder::STATUS_CLOSED,
            MaintenanceWorkOrder::STATUS_IN_PROGRESS, // Reopened
        ],
        MaintenanceWorkOrder::STATUS_CLOSED => [
            MaintenanceWorkOrder::STATUS_IN_PROGRESS, // Reopened by authorized supervisor/manager
        ],
        MaintenanceWorkOrder::STATUS_CANCELLED => [],
    ];

    public function __construct(
        private HousekeepingService $housekeeping,
        private SettingsService $settings,
        private AuditLogger $audit,
    ) {}

    /**
     * Report / create a new maintenance work order.
     */
    public function createWorkOrder(array $data, User $actor): MaintenanceWorkOrder
    {
        return DB::transaction(function () use ($data, $actor) {
            $roomId = ! empty($data['room_id']) ? (int) $data['room_id'] : null;
            $blocksRoom = (bool) ($data['blocks_room'] ?? false);

            /** @var Room|null $room */
            $room = null;
            /** @var RoomType|null $roomType */
            $roomType = null;

            if ($roomId) {
                $roomLookup = Room::findOrFail($roomId);
                // Lock RoomType then Room in deterministic order to coordinate with ReservationService
                $roomType = RoomType::where('id', $roomLookup->room_type_id)->lockForUpdate()->firstOrFail();
                $room = Room::where('id', $roomId)->lockForUpdate()->firstOrFail();
            }

            $assignedTo = ! empty($data['assigned_to']) ? (int) $data['assigned_to'] : null;
            $initialStatus = $assignedTo
                ? MaintenanceWorkOrder::STATUS_ASSIGNED
                : MaintenanceWorkOrder::STATUS_REPORTED;

            $blockedFrom = $blocksRoom ? ($data['blocked_from'] ?? now()->format('Y-m-d')) : null;
            $blockedUntil = $blocksRoom ? ($data['blocked_until'] ?? null) : null;

            // Detect any upcoming or in-house confirmed reservations assigned to this room
            $conflictingReservationIds = [];
            $hasArrivalConflict = false;
            if ($room && $blocksRoom) {
                $conflicts = $this->findConflictingReservationsForRoom($room, $blockedFrom, $blockedUntil);
                if ($conflicts->isNotEmpty()) {
                    $hasArrivalConflict = true;
                    $conflictingReservationIds = $conflicts->pluck('id')->all();
                }
            }

            $currency = $this->settings->all()['hotel']['currency'] ?? 'NGN';
            $partsCostMinor = AvailabilityService::toMinor($data['parts_cost'] ?? 0);
            $laborCostMinor = AvailabilityService::toMinor($data['labor_cost'] ?? 0);
            $totalCostMinor = $partsCostMinor + $laborCostMinor;

            $workOrder = MaintenanceWorkOrder::create([
                'work_order_reference' => $this->generateUniqueReference(),
                'room_id' => $room?->id,
                'area_label' => isset($data['area_label']) ? strip_tags((string) $data['area_label']) : ($room ? "Room {$room->room_number}" : 'General Facility'),
                'category' => $data['category'] ?? 'other',
                'priority' => $data['priority'] ?? 'normal',
                'status' => $initialStatus,
                'title' => strip_tags((string) $data['title']),
                'description' => strip_tags((string) $data['description']),
                'Internal_notes' => isset($data['internal_notes']) ? strip_tags((string) $data['internal_notes']) : null,
                'blocks_room' => $blocksRoom && $room !== null,
                'blocked_from' => $blockedFrom,
                'blocked_until' => $blockedUntil,
                'has_arrival_conflict' => $hasArrivalConflict,
                'conflicting_reservation_ids' => $conflictingReservationIds,
                'currency_code' => $currency,
                'parts_cost_minor' => $partsCostMinor,
                'labor_cost_minor' => $laborCostMinor,
                'total_cost_minor' => $totalCostMinor,
                'parts_cost' => AvailabilityService::toMajor($partsCostMinor),
                'labor_cost' => AvailabilityService::toMajor($laborCostMinor),
                'total_cost' => AvailabilityService::toMajor($totalCostMinor),
                'vendor_name' => isset($data['vendor_name']) ? strip_tags((string) $data['vendor_name']) : null,
                'vendor_invoice_reference' => isset($data['vendor_invoice_reference']) ? strip_tags((string) $data['vendor_invoice_reference']) : null,
                'reported_by' => $actor->id,
                'reported_at' => now(),
                'assigned_to' => $assignedTo,
                'assigned_by' => $assignedTo ? $actor->id : null,
                'assigned_at' => $assignedTo ? now() : null,
            ]);

            if ($room && $blocksRoom) {
                $this->applyRoomMaintenanceBlock($room, $roomType, $workOrder, $actor, ! empty($data['mark_out_of_service']));
            }

            MaintenanceWorkOrderHistory::create([
                'maintenance_work_order_id' => $workOrder->id,
                'from_status' => null,
                'to_status' => $workOrder->status,
                'notes' => 'Maintenance work order reported',
                'metadata' => [
                    'blocks_room' => $workOrder->blocks_room,
                    'has_arrival_conflict' => $hasArrivalConflict,
                    'conflicting_reservation_ids' => $conflictingReservationIds,
                ],
                'changed_by' => $actor->id,
                'created_at' => now(),
            ]);

            if (in_array($workOrder->priority, ['high', 'emergency'], true)) {
                OperationalNotification::create([
                    'type' => 'maintenance.urgent',
                    'severity' => $workOrder->priority === 'emergency' ? 'critical' : 'warning',
                    'title' => strtoupper($workOrder->priority)." Maintenance: {$workOrder->work_order_reference}",
                    'message' => "{$workOrder->title} ({$workOrder->area_label}).",
                    'target_permission' => 'maintenance.view',
                    'target_user_id' => $assignedTo,
                    'subject_type' => MaintenanceWorkOrder::class,
                    'subject_id' => $workOrder->id,
                ]);
            }

            if ($hasArrivalConflict) {
                OperationalNotification::create([
                    'type' => 'maintenance.arrival_conflict',
                    'severity' => 'critical',
                    'title' => "Room {$room->room_number} Blocked — Reassignment Needed",
                    'message' => "Maintenance work order {$workOrder->work_order_reference} blocks Room {$room->room_number}, which has upcoming/active reservation(s) assigned. Front desk must reassign the room before check-in.",
                    'target_permission' => 'frontdesk.view',
                    'subject_type' => MaintenanceWorkOrder::class,
                    'subject_id' => $workOrder->id,
                    'metadata' => ['conflicting_reservation_ids' => $conflictingReservationIds],
                ]);
            }

            $this->audit->log('maintenance.work_order_created', $workOrder, [
                'work_order_reference' => $workOrder->work_order_reference,
                'room_id' => $room?->id,
                'room_number' => $room?->room_number,
                'category' => $workOrder->category,
                'priority' => $workOrder->priority,
                'blocks_room' => $workOrder->blocks_room,
                'has_arrival_conflict' => $hasArrivalConflict,
            ], 'success', $actor->id);

            return $workOrder->load(['room.roomType', 'reporter', 'assignee', 'histories.actor']);
        }, 3);
    }

    /**
     * Assign a maintenance work order to a technician/staff member.
     */
    public function assignWorkOrder(MaintenanceWorkOrder $workOrder, int $assigneeId, User $actor, ?string $notes = null): MaintenanceWorkOrder
    {
        return DB::transaction(function () use ($workOrder, $assigneeId, $actor, $notes) {
            /** @var MaintenanceWorkOrder $locked */
            $locked = MaintenanceWorkOrder::where('id', $workOrder->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, MaintenanceWorkOrder::OPEN_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => ["Cannot assign work order {$locked->work_order_reference} in status [{$locked->status}]."],
                ]);
            }

            $assignee = User::where('id', $assigneeId)->where('is_active', true)->first();
            if (! $assignee) {
                throw ValidationException::withMessages([
                    'assigned_to' => ['Selected technician does not exist or is inactive.'],
                ]);
            }

            $oldStatus = $locked->status;
            $newStatus = in_array($oldStatus, [MaintenanceWorkOrder::STATUS_REPORTED, MaintenanceWorkOrder::STATUS_ACKNOWLEDGED], true)
                ? MaintenanceWorkOrder::STATUS_ASSIGNED
                : $oldStatus;

            $locked->update([
                'status' => $newStatus,
                'assigned_to' => $assignee->id,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
                'acknowledged_by' => $locked->acknowledged_by ?? $actor->id,
                'acknowledged_at' => $locked->acknowledged_at ?? now(),
            ]);

            MaintenanceWorkOrderHistory::create([
                'maintenance_work_order_id' => $locked->id,
                'from_status' => $oldStatus,
                'to_status' => $newStatus,
                'notes' => $notes ? strip_tags($notes) : "Assigned to {$assignee->name}",
                'metadata' => ['assigned_to' => $assignee->id],
                'changed_by' => $actor->id,
                'created_at' => now(),
            ]);

            $this->audit->log('maintenance.work_order_assigned', $locked, [
                'work_order_reference' => $locked->work_order_reference,
                'assigned_to' => $assignee->id,
            ], 'success', $actor->id);

            return $locked->fresh(['room.roomType', 'reporter', 'assignee', 'histories.actor']);
        }, 3);
    }

    /**
     * Transition status and/or update diagnosis, resolution, room-blocking, or repair cost details.
     */
    public function updateStatusOrDetails(MaintenanceWorkOrder $workOrder, array $data, User $actor): MaintenanceWorkOrder
    {
        return DB::transaction(function () use ($workOrder, $data, $actor) {
            /** @var MaintenanceWorkOrder $locked */
            $locked = MaintenanceWorkOrder::where('id', $workOrder->id)->lockForUpdate()->firstOrFail();

            // IDOR / technician ownership check: if actor does not have maintenance.assign or maintenance.close,
            // they can only update work orders assigned to them (or unassigned orders they are starting).
            if ($locked->assigned_to !== null
                && $locked->assigned_to !== $actor->id
                && ! $actor->hasAnyPermission(['maintenance.assign', 'maintenance.close'])) {
                throw ValidationException::withMessages([
                    'assigned_to' => ['You can only update maintenance work orders assigned to you.'],
                ]);
            }

            $oldStatus = $locked->status;
            $targetStatus = isset($data['status']) ? (string) $data['status'] : $oldStatus;

            if ($targetStatus !== $oldStatus) {
                $allowed = self::ALLOWED_TRANSITIONS[$oldStatus] ?? [];
                if (! in_array($targetStatus, $allowed, true)) {
                    throw ValidationException::withMessages([
                        'status' => ["Invalid maintenance status transition from [{$oldStatus}] to [{$targetStatus}]."],
                    ]);
                }

                // Closing or reopening a resolved/closed work order requires `maintenance.close`
                if (($targetStatus === MaintenanceWorkOrder::STATUS_CLOSED
                    || in_array($oldStatus, [MaintenanceWorkOrder::STATUS_RESOLVED, MaintenanceWorkOrder::STATUS_CLOSED], true))
                    && ! $actor->hasPermission('maintenance.close')) {
                    abort(403, 'You do not have permission to close or reopen maintenance work orders.');
                }
            }

            /** @var Room|null $room */
            $room = null;
            /** @var RoomType|null $roomType */
            $roomType = null;
            if ($locked->room_id) {
                $roomLookup = Room::findOrFail($locked->room_id);
                $roomType = RoomType::where('id', $roomLookup->room_type_id)->lockForUpdate()->firstOrFail();
                $room = Room::where('id', $locked->room_id)->lockForUpdate()->firstOrFail();
            }

            $updates = [];
            if (array_key_exists('priority', $data)) {
                $updates['priority'] = $data['priority'];
            }
            if (array_key_exists('diagnosis_notes', $data)) {
                $updates['diagnosis_notes'] = $data['diagnosis_notes'] !== null ? strip_tags((string) $data['diagnosis_notes']) : null;
            }
            if (array_key_exists('resolution_notes', $data)) {
                $updates['resolution_notes'] = $data['resolution_notes'] !== null ? strip_tags((string) $data['resolution_notes']) : null;
            }
            if (array_key_exists('parts_wait_notes', $data)) {
                $updates['parts_Wait_notes'] = $data['parts_wait_notes'] !== null ? strip_tags((string) $data['parts_wait_notes']) : null;
            }
            if (array_key_exists('internal_notes', $data)) {
                $updates['Internal_notes'] = $data['internal_notes'] !== null ? strip_tags((string) $data['internal_notes']) : null;
            }

            // Cost updates (require maintenance.manage or maintenance.close)
            if (array_key_exists('parts_cost', $data) || array_key_exists('labor_cost', $data)) {
                $partsMinor = array_key_exists('parts_cost', $data)
                    ? AvailabilityService::toMinor($data['parts_cost'])
                    : $locked->parts_cost_minor;
                $laborMinor = array_key_exists('labor_cost', $data)
                    ? AvailabilityService::toMinor($data['labor_cost'])
                    : $locked->labor_cost_minor;
                if ($partsMinor < 0 || $laborMinor < 0) {
                    throw ValidationException::withMessages(['parts_cost' => ['Maintenance costs cannot be negative.']]);
                }
                $totalMinor = $partsMinor + $laborMinor;
                $updates['parts_cost_minor'] = $partsMinor;
                $updates['labor_cost_minor'] = $laborMinor;
                $updates['total_cost_minor'] = $totalMinor;
                $updates['parts_cost'] = AvailabilityService::toMajor($partsMinor);
                $updates['labor_cost'] = AvailabilityService::toMajor($laborMinor);
                $updates['total_cost'] = AvailabilityService::toMajor($totalMinor);
            }
            if (array_key_exists('vendor_name', $data)) {
                $updates['vendor_name'] = $data['vendor_name'] !== null ? strip_tags((string) $data['vendor_name']) : null;
            }
            if (array_key_exists('vendor_invoice_reference', $data)) {
                $updates['vendor_invoice_reference'] = $data['vendor_invoice_reference'] !== null ? strip_tags((string) $data['vendor_invoice_reference']) : null;
            }

            if (array_key_exists('blocks_room', $data) && $room) {
                $updates['blocks_room'] = (bool) $data['blocks_room'];
                if ($updates['blocks_room']) {
                    $updates['blocked_from'] = $data['blocked_from'] ?? ($locked->blocked_from?->format('Y-m-d') ?? now()->format('Y-m-d'));
                    $updates['blocked_until'] = $data['blocked_until'] ?? $locked->blocked_until?->format('Y-m-d');
                }
            }

            // Lifecycle timestamps
            $updates['status'] = $targetStatus;
            if ($targetStatus === MaintenanceWorkOrder::STATUS_ACKNOWLEDGED && ! $locked->acknowledged_at) {
                $updates['acknowledged_by'] = $actor->id;
                $updates['acknowledged_at'] = now();
            }
            if ($targetStatus === MaintenanceWorkOrder::STATUS_IN_PROGRESS) {
                if (! $locked->started_at) {
                    $updates['started_at'] = now();
                }
                if (in_array($oldStatus, [MaintenanceWorkOrder::STATUS_RESOLVED, MaintenanceWorkOrder::STATUS_CLOSED], true)) {
                    $updates['reopened_at'] = now();
                    $updates['reopened_by'] = $actor->id;
                }
            }
            if ($targetStatus === MaintenanceWorkOrder::STATUS_RESOLVED) {
                if (empty($updates['resolution_notes']) && empty($locked->resolution_notes) && empty($data['notes'])) {
                    throw ValidationException::withMessages([
                        'resolution_notes' => ['Resolution notes are required when marking a work order as resolved.'],
                    ]);
                }
                $updates['resolved_at'] = now();
                $updates['resolved_by'] = $actor->id;
            }
            if ($targetStatus === MaintenanceWorkOrder::STATUS_CLOSED) {
                $updates['closed_at'] = now();
                $updates['closed_by'] = $actor->id;
            }

            $locked->update($updates);

            // Handle Room Blocking / Unblocking & Safe Return-to-Service
            if ($room && $roomType) {
                $isNowOpenAndBlocking = in_array($locked->status, MaintenanceWorkOrder::OPEN_STATUSES, true) && $locked->blocks_room;
                if ($isNowOpenAndBlocking) {
                    $this->applyRoomMaintenanceBlock($room, $roomType, $locked, $actor, ! empty($data['mark_out_of_service']));
                } elseif (in_array($targetStatus, [
                    MaintenanceWorkOrder::STATUS_RESOLVED,
                    MaintenanceWorkOrder::STATUS_CLOSED,
                    MaintenanceWorkOrder::STATUS_CANCELLED,
                ], true)) {
                    $this->releaseRoomMaintenanceBlockIfClear($room, $locked, $actor);
                }
            }

            MaintenanceWorkOrderHistory::create([
                'maintenance_work_order_id' => $locked->id,
                'from_status' => $oldStatus,
                'to_status' => $targetStatus,
                'notes' => isset($data['notes']) ? strip_tags((string) $data['notes']) : ($updates['resolution_notes'] ?? "Status updated to {$targetStatus}"),
                'metadata' => [
                    'parts_cost_minor' => $locked->parts_cost_minor,
                    'labor_cost_minor' => $locked->labor_cost_minor,
                    'total_cost_minor' => $locked->total_cost_minor,
                ],
                'changed_by' => $actor->id,
                'created_at' => now(),
            ]);

            $this->audit->log('maintenance.work_order_updated', $locked, [
                'work_order_reference' => $locked->work_order_reference,
                'from_status' => $oldStatus,
                'to_status' => $targetStatus,
                'total_cost_minor' => $locked->total_cost_minor,
            ], 'success', $actor->id);

            return $locked->fresh(['room.roomType', 'reporter', 'assignee', 'resolver', 'closer', 'followupHousekeepingTask', 'histories.actor']);
        }, 3);
    }

    /**
     * Find any active/upcoming reservations directly assigned to `$room` overlapping the maintenance block window.
     */
    private function findConflictingReservationsForRoom(Room $room, ?string $fromDate, ?string $untilDate)
    {
        $today = now()->format('Y-m-d');
        $effectiveFrom = $fromDate ?: $today;

        return Reservation::where('assigned_room_id', $room->id)
            ->whereIn('status', Reservation::INVENTORY_CONSUMING_STATUSES)
            ->where('check_out_date', '>', $effectiveFrom)
            ->when($untilDate, fn ($q) => $q->where('check_in_date', '<=', $untilDate))
            ->get();
    }

    /**
     * Place the room under maintenance block (`housekeeping_status = 'maintenance'`) and optionally `out_of_service`
     * if taking the room out of service does not leave confirmed room-type reservations overbooked.
     */
    private function applyRoomMaintenanceBlock(
        Room $room,
        RoomType $roomType,
        MaintenanceWorkOrder $workOrder,
        User $actor,
        bool $requestOutOfService = false
    ): void {
        // Cannot place a room with a currently checked-in guest into out_of_service without room move first
        $inHouse = Reservation::where('assigned_room_id', $room->id)
            ->where('status', Reservation::STATUS_CHECKED_IN)
            ->first();

        $roomUpdates = [
            'housekeeping_status' => 'maintenance',
            'updated_by' => $actor->id,
        ];

        if ($requestOutOfService && ! $inHouse) {
            // Check if marking the room out_of_service would violate confirmed room-type pool capacity OR active unexpired booking holds on any future date
            $today = now()->format('Y-m-d');
            $from = $workOrder->blocked_from?->format('Y-m-d') ?? $today;
            $until = $workOrder->blocked_until?->format('Y-m-d');

            $activeHolds = \App\Models\BookingHold::activeUnexpired()
                ->where('room_type_id', $roomType->id)
                ->when($until, fn ($q) => $q->where('check_in_date', '<=', $until))
                ->where('check_out_date', '>', $from)
                ->lockForUpdate()
                ->get();

            $activeReservaionIds = Reservation::where('room_type_id', $roomType->id)
                ->whereIn('status', Reservation::INVENTORY_CONSUMING_STATUSES)
                ->where('check_out_date', '>', $from)
                ->when($until, fn ($q) => $q->where('check_in_date', '<=', $until))
                ->lockForUpdate()
                ->get(['id'])
                ->pluck('id')
                ->all();

            $futureStayDates = empty($activeReservaionIds)
                ? []
                : ReservationNight::where('room_type_id', $roomType->id)
                    ->where('status', 'active')
                    ->where('stay_date', '>=', $from)
                    ->when($until, fn ($q) => $q->where('stay_date', '<=', $until))
                    ->whereIn('reservation_id', $activeReservaionIds)
                    ->lockForUpdate()
                    ->get(['stay_date'])
                    ->map(fn (ReservationNight $n) => $n->stay_date instanceof \DateTimeInterface ? $n->stay_date->format('Y-m-d') : (string) $n->stay_date)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

            foreach ($activeHolds as $hold) {
                $hIn = max($from, $hold->check_in_date->format('Y-m-d'));
                $hOut = $hold->check_out_date->format('Y-m-d');
                $cur = \Carbon\CarbonImmutable::createFromFormat('Y-m-d', $hIn);
                $end = \Carbon\CarbonImmutable::createFromFormat('Y-m-d', $hOut);
                while ($cur->lt($end)) {
                    $dStr = $cur->format('Y-m-d');
                    if (! $until || $dStr <= $until) {
                        $futureStayDates[] = $dStr;
                    }
                    $cur = $cur->addDay();
                }
            }
            $futureStayDates = array_values(array_unique($futureStayDates));
            sort($futureStayDates);

            $otherRooms = Room::where('room_type_id', $roomType->id)->where('id', '!=', $room->id)->lockForUpdate()->get();
            foreach ($futureStayDates as $stayDate) {
                $sellableWithoutThisRoom = $otherRooms->filter(fn (Room $r) => $r->isSellableOnDate($stayDate))->count();
                $reserved = empty($activeReservaionIds)
                    ? 0
                    : ReservationNight::where('room_type_id', $roomType->id)
                        ->where('status', 'active')
                        ->where('stay_date', $stayDate)
                        ->whereIn('reservation_id', $activeReservaionIds)
                        ->lockForUpdate()
                        ->get(['id'])
                        ->count();
                $held = (int) $activeHolds->filter(
                    fn (\App\Models\BookingHold $h) => $stayDate >= $h->check_in_date->format('Y-m-d') && $stayDate < $h->check_out_date->format('Y-m-d')
                )->sum('rooms_count');

                if (($reserved + $held) > $sellableWithoutThisRoom) {
                    throw ValidationException::withMessages([
                        'mark_out_of_service' => ["Cannot place Room {$room->room_number} out of service on {$stayDate} because ".($reserved + $held)." confirmed/held reservation(s) require it and only {$sellableWithoutThisRoom} other sellable room(s) exist in {$roomType->name}."],
                    ]);
                }
            }

            $roomUpdates['operational_status'] = 'out_of_service';
            $roomUpdates['out_of_service_from'] = $from;
            $roomUpdates['out_of_service_until'] = $until;
            $roomUpdates['out_of_service_reason'] = "Maintenance {$workOrder->work_order_reference}: {$workOrder->title}";
        }

        $room->update($roomUpdates);
    }

    /**
     * When a blocking work order is resolved, closed, or cancelled, check if any other open blocking work order
     * remains on the room. If none remain:
     * 1. Restore `operational_status` to `'active'` if it was set to `'out_of_service'` by maintenance.
     * 2. Transition `housekeeping_status` from `'maintenance'` to `'dirty'` (NEVER directly to `'clean'`).
     * 3. Automatically create a `maintenance_followup` housekeeping task so the room is cleaned and inspected
     *    before becoming ready for check-in.
     */
    private function releaseRoomMaintenanceBlockIfClear(Room $room, MaintenanceWorkOrder $workOrder, User $actor): void
    {
        $otherOpenBlocking = MaintenanceWorkOrder::where('room_id', $room->id)
            ->where('id', '!=', $workOrder->id)
            ->where('blocks_room', true)
            ->whereIn('status', MaintenanceWorkOrder::OPEN_STATUSES)
            ->exists();

        if ($otherOpenBlocking) {
            return;
        }

        $roomUpdates = [
            'housekeeping_status' => 'dirty',
            'updated_by' => $actor->id,
        ];

        if ($room->operational_status === 'out_of_service'
            && str_starts_with((string) $room->out_of_service_reason, 'Maintenance ')) {
            $roomUpdates['operational_status'] = 'active';
            $roomUpdates['out_of_service_from'] = null;
            $roomUpdates['out_of_service_until'] = null;
            $roomUpdates['out_of_service_reason'] = null;
        }

        $room->update($roomUpdates);

        if (! $workOrder->followup_housekeeping_task_id && $workOrder->status !== MaintenanceWorkOrder::STATUS_CANCELLED) {
            $hkTask = $this->housekeeping->createTask([
                'room_id' => $room->id,
                'task_type' => 'maintenance_followup',
                'priority' => 'high',
                'scheduled_date' => now()->format('Y-m-d'),
                'title' => "Post-Maintenance Cleaning — Room {$room->room_number} ({$workOrder->work_order_reference})",
                'instructions' => "Clean and inspect Room {$room->room_number} after maintenance resolution ({$workOrder->title}).",
            ], $actor);

            $workOrder->update(['followup_housekeeping_task_id' => $hkTask->id]);
        }
    }

    private function generateUniqueReference(): string
    {
        $date = now()->format('Ymd');
        for ($i = 0; $i < 10; $i++) {
            $ref = 'MWO-'.$date.'-'.strtoupper(Str::random(6));
            if (! MaintenanceWorkOrder::where('work_order_reference', $ref)->exists()) {
                return $ref;
            }
        }

        return 'MWO-'.$date.'-'.strtoupper(Str::random(8));
    }
}
