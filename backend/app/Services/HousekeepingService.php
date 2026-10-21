<?php

namespace App\Services;

use App\Models\HousekeepingInspection;
use App\Models\HousekeepingTask;
use App\Models\HousekeepingTaskHistory;
use App\Models\OperationalNotification;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Transactional Housekeeping Task, Room Readiness, and Inspection Service.
 *
 * State Machine:
 *   pending -> assigned -> in_progress -> completed -> inspected | failed_inspection
 *   pending -> in_progress -> completed -> inspected | failed_inspection
 *   any active state -> cancelled
 *
 * Room Readiness Coupling:
 * - Completing a task transitions the room's `housekeeping_status` to `'inspecting'` (awaiting supervisor inspection)
 *   and records `last_cleaned_at`.
 * - Passing an inspection transitions the task to `'inspected'` and the room's `housekeeping_status` to `'inspected'`
 *   (or `'clean'`, with `last_inspected_at` recorded). We set `housekeeping_status = 'inspected'` (which satisfies
 *   both `block_dirty_room_checkin` and `require_inspected_room_for_checkin`).
 * - Failing an inspection transitions the task to `'failed_inspection'`, immediately returns the room's
 *   `housekeeping_status` to `'dirty'`, and automatically creates a high-priority follow-up corrective task.
 */
class HousekeepingService
{
    public const ALLOWED_TRANSITIONS = [
        HousekeepingTask::STATUS_PENDING => [
            HousekeepingTask::STATUS_ASSIGNED,
            HousekeepingTask::STATUS_IN_PROGRESS,
            HousekeepingTask::STATUS_CANCELLED,
        ],
        HousekeepingTask::STATUS_ASSIGNED => [
            HousekeepingTask::STATUS_ASSIGNED,
            HousekeepingTask::STATUS_IN_PROGRESS,
            HousekeepingTask::STATUS_COMPLETED,
            HousekeepingTask::STATUS_CANCELLED,
        ],
        HousekeepingTask::STATUS_IN_PROGRESS => [
            HousekeepingTask::STATUS_COMPLETED,
            HousekeepingTask::STATUS_CANCELLED,
        ],
        HousekeepingTask::STATUS_COMPLETED => [
            HousekeepingTask::STATUS_INSPECTED,
            HousekeepingTask::STATUS_FAILED_INSPECTION,
        ],
        HousekeepingTask::STATUS_INSPECTED => [],
        HousekeepingTask::STATUS_FAILED_INSPECTION => [],
        HousekeepingTask::STATUS_CANCELLED => [],
    ];

    public function __construct(
        private AuditLogger $audit,
    ) {}

    /**
     * Create a new housekeeping task with row-level locking on the Room.
     */
    public function createTask(array $data, ?User $actor = null): HousekeepingTask
    {
        return DB::transaction(function () use ($data, $actor) {
            /** @var Room $room */
            $room = Room::where('id', (int) $data['room_id'])->lockForUpdate()->firstOrFail();

            $taskType = (string) ($data['task_type'] ?? 'manual_request');
            $scheduledDate = (string) ($data['scheduled_date'] ?? now()->format('Y-m-d'));
            $reservationId = isset($data['reservation_id']) ? (int) $data['reservation_id'] : null;

            // Idempotency guard for automatic checkout_cleaning or maintenance_followup tasks
            if ($reservationId && $taskType === 'checkout_cleaning') {
                $existing = HousekeepingTask::where('room_id', $room->id)
                    ->where('reservation_id', $reservationId)
                    ->where('task_type', 'checkout_cleaning')
                    ->whereIn('status', HousekeepingTask::ACTIVE_STATUSES)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    return $existing->load(['room.roomType', 'assignee', 'reservation']);
                }
            }

            $assignedTo = ! empty($data['assigned_to']) ? (int) $data['assigned_to'] : null;
            $initialStatus = $assignedTo ? HousekeepingTask::STATUS_ASSIGNED : HousekeepingTask::STATUS_PENDING;

            $task = HousekeepingTask::create([
                'task_reference' => $this->generateUniqueReference(),
                'room_id' => $room->id,
                'reservation_id' => $reservationId,
                'parent_task_id' => $data['parent_task_id'] ?? null,
                'task_type' => $taskType,
                'priority' => $data['priority'] ?? 'normal',
                'status' => $initialStatus,
                'scheduled_date' => $scheduledDate,
                'due_time' => $data['due_time'] ?? null,
                'title' => strip_tags((string) ($data['title'] ?? ucfirst(str_replace('_', ' ', $taskType))." — Room {$room->room_number}")),
                'instructions' => isset($data['instructions']) ? strip_tags((string) $data['instructions']) : null,
                'internal_notes' => isset($data['internal_notes']) ? strip_tags((string) $data['internal_notes']) : null,
                'assigned_to' => $assignedTo,
                'assigned_by' => $assignedTo ? $actor?->id : null,
                'assigned_at' => $assignedTo ? now() : null,
                'created_by' => $actor?->id,
            ]);

            // If creating a checkout or deep cleaning task on a room not currently in maintenance, ensure room is marked dirty
            if (in_array($taskType, ['checkout_cleaning', 'deep_cleaning', 'inspection_followup', 'maintenance_followup'], true)
                && $room->housekeeping_status !== 'maintenance') {
                $room->update(['housekeeping_status' => 'dirty', 'updated_by' => $actor?->id]);
            }

            HousekeepingTaskHistory::create([
                'housekeeping_task_id' => $task->id,
                'from_status' => null,
                'to_status' => $task->status,
                'from_assigned_to' => null,
                'to_assigned_to' => $assignedTo,
                'notes' => 'Housekeeping task created',
                'changed_by' => $actor?->id,
                'created_at' => now(),
            ]);

            if ($assignedTo) {
                OperationalNotification::create([
                    'type' => 'housekeeping.assigned',
                    'severity' => $task->priority === 'urgent' ? 'critical' : 'info',
                    'title' => "Housekeeping Task Assigned: Room {$room->room_number}",
                    'message' => "{$task->title} scheduled for {$scheduledDate}.",
                    'target_permission' => 'housekeeping.view',
                    'target_user_id' => $assignedTo,
                    'subject_type' => HousekeepingTask::class,
                    'subject_id' => $task->id,
                ]);
            }

            $this->audit->log('housekeeping.task_created', $task, [
                'task_reference' => $task->task_reference,
                'room_number' => $room->room_number,
                'task_type' => $task->task_type,
                'priority' => $task->priority,
                'status' => $task->status,
                'assigned_to' => $assignedTo,
            ], 'success', $actor?->id);

            return $task->load(['room.roomType', 'assignee', 'reservation']);
        }, 3);
    }

    /**
     * Automatically schedule an idempotent checkout cleaning task when a guest checks out.
     */
    public function createCheckoutCleaningTaskForReservation(Reservation $reservation, ?User $actor = null): ?HousekeepingTask
    {
        if (! $reservation->assigned_room_id) {
            return null;
        }

        return $this->createTask([
            'room_id' => $reservation->assigned_room_id,
            'reservation_id' => $reservation->id,
            'task_type' => 'checkout_cleaning',
            'priority' => 'high',
            'scheduled_date' => now()->format('Y-m-d'),
            'title' => "Checkout Cleaning — Room {$reservation->assignedRoom?->room_number} ({$reservation->reference})",
            'instructions' => "Full departure turnover cleaning after guest checkout ({$reservation->guest_name}).",
        ], $actor);
    }

    /**
     * Assign or reassign a housekeeping task to a staff member.
     */
    public function assignTask(HousekeepingTask $task, int $assigneeId, User $actor, ?string $notes = null): HousekeepingTask
    {
        return DB::transaction(function () use ($task, $assigneeId, $actor, $notes) {
            /** @var HousekeepingTask $locked */
            $locked = HousekeepingTask::where('id', $task->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [HousekeepingTask::STATUS_PENDING, HousekeepingTask::STATUS_ASSIGNED, HousekeepingTask::STATUS_IN_PROGRESS], true)) {
                throw ValidationException::withMessages([
                    'status' => ["Cannot assign a housekeeping task in status [{$locked->status}]."],
                ]);
            }

            $assignee = User::where('id', $assigneeId)->where('is_active', true)->first();
            if (! $assignee) {
                throw ValidationException::withMessages([
                    'assigned_to' => ['Selected staff member does not exist or is inactive.'],
                ]);
            }

            $oldStatus = $locked->status;
            $oldAssignee = $locked->assigned_to;
            $newStatus = $oldStatus === HousekeepingTask::STATUS_PENDING
                ? HousekeepingTask::STATUS_ASSIGNED
                : $oldStatus;

            $locked->update([
                'status' => $newStatus,
                'assigned_to' => $assignee->id,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
            ]);

            HousekeepingTaskHistory::create([
                'housekeeping_task_id' => $locked->id,
                'from_status' => $oldStatus,
                'to_status' => $newStatus,
                'from_assigned_to' => $oldAssignee,
                'to_assigned_to' => $assignee->id,
                'notes' => $notes ? strip_tags($notes) : "Assigned to {$assignee->name}",
                'changed_by' => $actor->id,
                'created_at' => now(),
            ]);

            OperationalNotification::create([
                'type' => 'housekeeping.assigned',
                'severity' => $locked->priority === 'urgent' ? 'critical' : 'info',
                'title' => "Task Assigned: {$locked->task_reference}",
                'message' => "{$locked->title} has been assigned to {$assignee->name}.",
                'target_permission' => 'housekeeping.view',
                'target_user_id' => $assignee->id,
                'subject_type' => HousekeepingTask::class,
                'subject_id' => $locked->id,
            ]);

            $this->audit->log('housekeeping.task_assigned', $locked, [
                'task_reference' => $locked->task_reference,
                'from_assigned_to' => $oldAssignee,
                'to_assigned_to' => $assignee->id,
            ], 'success', $actor->id);

            return $locked->fresh(['room.roomType', 'assignee', 'reservation', 'histories.actor', 'inspections.inspector']);
        }, 3);
    }

    /**
     * Start working on a housekeeping task (`pending` or `assigned` -> `in_progress`).
     * Enforces row-level locking so two housekeepers cannot claim/start the same unassigned task concurrently.
     */
    public function startTask(HousekeepingTask $task, User $actor, ?string $notes = null): HousekeepingTask
    {
        return DB::transaction(function () use ($task, $actor, $notes) {
            /** @var HousekeepingTask $locked */
            $locked = HousekeepingTask::where('id', $task->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [HousekeepingTask::STATUS_PENDING, HousekeepingTask::STATUS_ASSIGNED], true)) {
                throw ValidationException::withMessages([
                    'status' => ["Task {$locked->task_reference} cannot be started because its status is [{$locked->status}]."],
                ]);
            }

            // If already assigned to someone else, ordinary housekeeping staff cannot hijack it without assign/manage permission
            if ($locked->assigned_to !== null
                && $locked->assigned_to !== $actor->id
                && ! $actor->hasAnyPermission(['housekeeping.assign', 'housekeeping.manage'])) {
                throw ValidationException::withMessages([
                    'assigned_to' => ['This task is already assigned to another staff member.'],
                ]);
            }

            $oldStatus = $locked->status;
            $oldAssignee = $locked->assigned_to;
            $newAssignee = $locked->assigned_to ?? $actor->id;

            $locked->update([
                'status' => HousekeepingTask::STATUS_IN_PROGRESS,
                'assigned_to' => $newAssignee,
                'started_at' => now(),
                'started_by' => $actor->id,
            ]);

            HousekeepingTaskHistory::create([
                'housekeeping_task_id' => $locked->id,
                'from_status' => $oldStatus,
                'to_status' => HousekeepingTask::STATUS_IN_PROGRESS,
                'from_assigned_to' => $oldAssignee,
                'to_assigned_to' => $newAssignee,
                'notes' => $notes ? strip_tags($notes) : 'Cleaning started',
                'changed_by' => $actor->id,
                'created_at' => now(),
            ]);

            $this->audit->log('housekeeping.task_started', $locked, [
                'task_reference' => $locked->task_reference,
                'started_by' => $actor->id,
            ], 'success', $actor->id);

            return $locked->fresh(['room.roomType', 'assignee', 'reservation', 'histories.actor', 'inspections.inspector']);
        }, 3);
    }

    /**
     * Mark a housekeeping task as completed (`assigned` or `in_progress` -> `completed`).
     * Transitions the room's `housekeeping_status` to `'inspecting'` (awaiting supervisor inspection).
     */
    public function completeTask(HousekeepingTask $task, User $actor, ?string $completionNotes = null): HousekeepingTask
    {
        return DB::transaction(function () use ($task, $actor, $completionNotes) {
            /** @var HousekeepingTask $locked */
            $locked = HousekeepingTask::where('id', $task->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [HousekeepingTask::STATUS_ASSIGNED, HousekeepingTask::STATUS_IN_PROGRESS], true)) {
                throw ValidationException::withMessages([
                    'status' => ["Only assigned or in-progress tasks can be marked completed (current status: {$locked->status})."],
                ]);
            }

            // IDOR / ownership check: if actor does not have housekeeping.manage/assign, they can only complete tasks assigned to them
            if ($locked->assigned_to !== null
                && $locked->assigned_to !== $actor->id
                && ! $actor->hasAnyPermission(['housekeeping.manage', 'housekeeping.assign', 'housekeeping.inspect'])) {
                throw ValidationException::withMessages([
                    'assigned_to' => ['You can only complete housekeeping tasks assigned to you.'],
                ]);
            }

            /** @var Room $room */
            $room = Room::where('id', $locked->room_id)->lockForUpdate()->firstOrFail();

            $oldStatus = $locked->status;
            $locked->update([
                'status' => HousekeepingTask::STATUS_COMPLETED,
                'started_at' => $locked->started_at ?? now(),
                'started_by' => $locked->started_by ?? $actor->id,
                'completed_at' => now(),
                'completed_by' => $actor->id,
                'completion_notes' => $completionNotes ? strip_tags($completionNotes) : $locked->completion_notes,
            ]);

            if ($room->housekeeping_status !== 'maintenance') {
                $room->update([
                    'housekeeping_status' => 'inspecting',
                    'last_cleaned_at' => now(),
                    'updated_by' => $actor->id,
                ]);
            }

            HousekeepingTaskHistory::create([
                'housekeeping_task_id' => $locked->id,
                'from_status' => $oldStatus,
                'to_status' => HousekeepingTask::STATUS_COMPLETED,
                'from_assigned_to' => $locked->assigned_to,
                'to_assigned_to' => $locked->assigned_to,
                'notes' => $completionNotes ? strip_tags($completionNotes) : 'Task completed; awaiting supervisor inspection',
                'changed_by' => $actor->id,
                'created_at' => now(),
            ]);

            $this->audit->log('housekeeping.task_completed', $locked, [
                'task_reference' => $locked->task_reference,
                'room_number' => $room->room_number,
                'room_housekeeping_status' => $room->housekeeping_status,
            ], 'success', $actor->id);

            return $locked->fresh(['room.roomType', 'assignee', 'completer', 'reservation', 'histories.actor', 'inspections.inspector']);
        }, 3);
    }

    /**
     * Supervisor inspection of a completed housekeeping task (`completed` -> `inspected` or `failed_inspection`).
     *
     * - If `outcome === 'passed'`: marks task `inspected`, updates Room `housekeeping_status = 'inspected'` (or `'clean'`
     *   if specified, both are ready), and sets `last_inspected_at` & `last_inspected_by`.
     * - If `outcome === 'failed'`: marks task `failed_inspection`, returns Room `housekeeping_status = 'dirty'`,
     *   and automatically creates a high-priority follow-up corrective task (`inspection_followup`).
     */
    public function inspectTask(HousekeepingTask $task, array $data, User $actor): HousekeepingTask
    {
        return DB::transaction(function () use ($task, $data, $actor) {
            /** @var HousekeepingTask $locked */
            $locked = HousekeepingTask::where('id', $task->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== HousekeepingTask::STATUS_COMPLETED) {
                throw ValidationException::withMessages([
                    'status' => ["Only completed housekeeping tasks can be inspected (current status: {$locked->status})."],
                ]);
            }

            /** @var Room $room */
            $room = Room::where('id', $locked->room_id)->lockForUpdate()->firstOrFail();

            $outcome = (string) $data['outcome']; // passed | failed
            if (! in_array($outcome, ['passed', 'failed'], true)) {
                throw ValidationException::withMessages(['outcome' => ['Inspection outcome must be passed or failed.']]);
            }

            $notes = isset($data['notes']) ? strip_tags((string) $data['notes']) : null;
            $checklist = isset($data['checklist']) && is_array($data['checklist']) ? $data['checklist'] : null;

            $newStatus = $outcome === 'passed'
                ? HousekeepingTask::STATUS_INSPECTED
                : HousekeepingTask::STATUS_FAILED_INSPECTION;

            $locked->update([
                'status' => $newStatus,
                'inspected_at' => now(),
                'inspected_by' => $actor->id,
            ]);

            $followupTask = null;

            if ($outcome === 'passed') {
                if ($room->housekeeping_status !== 'maintenance') {
                    $room->update([
                        'housekeeping_status' => 'inspected',
                        'last_inspected_at' => now(),
                        'last_inspected_by' => $actor->id,
                        'updated_by' => $actor->id,
                    ]);
                }
            } else {
                // Failed inspection: immediately return room to dirty and create a corrective follow-up task
                if ($room->housekeeping_status !== 'maintenance') {
                    $room->update([
                        'housekeeping_status' => 'dirty',
                        'updated_by' => $actor->id,
                    ]);
                }

                $followupTask = HousekeepingTask::create([
                    'task_reference' => $this->generateUniqueReference(),
                    'room_id' => $room->id,
                    'reservation_id' => $locked->reservation_id,
                    'parent_task_id' => $locked->id,
                    'task_type' => 'inspection_followup',
                    'priority' => 'urgent',
                    'status' => $locked->assigned_to ? HousekeepingTask::STATUS_ASSIGNED : HousekeepingTask::STATUS_PENDING,
                    'scheduled_date' => now()->format('Y-m-d'),
                    'title' => "Corrective Cleaning (Failed Inspection) — Room {$room->room_number}",
                    'instructions' => $notes ?: "Re-clean Room {$room->room_number} following failed inspection on {$locked->task_reference}.",
                    'assigned_to' => $locked->assigned_to,
                    'assigned_by' => $actor->id,
                    'assigned_at' => $locked->assigned_to ? now() : null,
                    'created_by' => $actor->id,
                ]);

                HousekeepingTaskHistory::create([
                    'housekeeping_task_id' => $followupTask->id,
                    'from_status' => null,
                    'to_status' => $followupTask->status,
                    'from_assigned_to' => null,
                    'to_assigned_to' => $followupTask->assigned_to,
                    'notes' => "Auto-created corrective task from failed inspection of {$locked->task_reference}",
                    'changed_by' => $actor->id,
                    'created_at' => now(),
                ]);

                OperationalNotification::create([
                    'type' => 'housekeeping.failed_inspection',
                    'severity' => 'warning',
                    'title' => "Inspection Failed: Room {$room->room_number}",
                    'message' => "Room {$room->room_number} failed inspection ({$locked->task_reference}). Follow-up task {$followupTask->task_reference} created.",
                    'target_permission' => 'housekeeping.view',
                    'target_user_id' => $locked->assigned_to,
                    'subject_type' => HousekeepingTask::class,
                    'subject_id' => $followupTask->id,
                ]);
            }

            $inspection = HousekeepingInspection::create([
                'housekeeping_task_id' => $locked->id,
                'room_id' => $room->id,
                'outcome' => $outcome,
                'checklist' => $checklist,
                'notes' => $notes,
                'followup_task_id' => $followupTask?->id,
                'inspected_by' => $actor->id,
                'inspected_at' => now(),
            ]);

            HousekeepingTaskHistory::create([
                'housekeeping_task_id' => $locked->id,
                'from_status' => HousekeepingTask::STATUS_COMPLETED,
                'to_status' => $newStatus,
                'from_assigned_to' => $locked->assigned_to,
                'to_assigned_to' => $locked->assigned_to,
                'notes' => $notes ?: "Inspection {$outcome}",
                'changed_by' => $actor->id,
                'created_at' => now(),
            ]);

            $this->audit->log("housekeeping.task_inspection_{$outcome}", $locked, [
                'task_reference' => $locked->task_reference,
                'room_number' => $room->room_number,
                'outcome' => $outcome,
                'inspection_id' => $inspection->id,
                'followup_task_reference' => $followupTask?->task_reference,
            ], 'success', $actor->id);

            return $locked->fresh([
                'room.roomType',
                'assignee',
                'completer',
                'inspector',
                'reservation',
                'followupTasks',
                'histories.actor',
                'inspections.inspector',
            ]);
        }, 3);
    }

    /**
     * Cancel an open housekeeping task.
     */
    public function cancelTask(HousekeepingTask $task, string $reason, User $actor): HousekeepingTask
    {
        return DB::transaction(function () use ($task, $reason, $actor) {
            /** @var HousekeepingTask $locked */
            $locked = HousekeepingTask::where('id', $task->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [
                HousekeepingTask::STATUS_PENDING,
                HousekeepingTask::STATUS_ASSIGNED,
                HousekeepingTask::STATUS_IN_PROGRESS,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => ["Cannot cancel a housekeeping task in status [{$locked->status}]."],
                ]);
            }

            $oldStatus = $locked->status;
            $locked->update([
                'status' => HousekeepingTask::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancellation_reason' => strip_tags($reason),
            ]);

            HousekeepingTaskHistory::create([
                'housekeeping_task_id' => $locked->id,
                'from_status' => $oldStatus,
                'to_status' => HousekeepingTask::STATUS_CANCELLED,
                'from_assigned_to' => $locked->assigned_to,
                'to_assigned_to' => $locked->assigned_to,
                'notes' => strip_tags($reason),
                'changed_by' => $actor->id,
                'created_at' => now(),
            ]);

            $this->audit->log('housekeeping.task_cancelled', $locked, [
                'task_reference' => $locked->task_reference,
                'reason' => strip_tags($reason),
            ], 'success', $actor->id);

            return $locked->fresh(['room.roomType', 'assignee', 'reservation', 'histories.actor']);
        }, 3);
    }

    private function generateUniqueReference(): string
    {
        $date = now()->format('Ymd');
        for ($i = 0; $i < 10; $i++) {
            $ref = 'HK-'.$date.'-'.strtoupper(Str::random(6));
            if (! HousekeepingTask::where('task_reference', $ref)->exists()) {
                return $ref;
            }
        }

        return 'HK-'.$date.'-'.strtoupper(Str::random(8));
    }
}
