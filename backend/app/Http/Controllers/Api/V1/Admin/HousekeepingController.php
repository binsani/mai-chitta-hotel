<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\HousekeepingTask;
use App\Models\Room;
use App\Models\User;
use App\Services\HousekeepingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class HousekeepingController extends Controller
{
    public function __construct(
        private HousekeepingService $housekeeping,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['housekeeping.view', 'housekeeping.manage', 'frontdesk.view'])) {
            abort(403, 'You do not have permission to view housekeeping operations.');
        }

        $user = $request->user();
        $isSupervisor = $user->hasAnyPermission(['housekeeping.manage', 'housekeeping.assign', 'housekeeping.inspect', 'frontdesk.view']);

        $query = HousekeepingTask::query()
            ->with(['room.roomType', 'assignee:id,name,email', 'completer:id,name', 'inspector:id,name', 'reservation:id,reference,guest_name,status']);

        // Ordinary housekeeping staff default to viewing tasks assigned to them or unassigned pending tasks unless filtering
        if (! $isSupervisor && $request->boolean('mine_only', false)) {
            $query->where('assigned_to', $user->id);
        }

        if ($status = $request->query('status')) {
            $query->where('status', (string) $status);
        }
        if ($priority = $request->query('priority')) {
            $query->where('priority', (string) $priority);
        }
        if ($taskType = $request->query('task_type')) {
            $query->where('task_type', (string) $taskType);
        }
        if ($roomId = $request->query('room_id')) {
            $query->where('room_id', (int) $roomId);
        }
        if ($assignedTo = $request->query('assigned_to')) {
            if ($assignedTo === 'unassigned') {
                $query->whereNull('assigned_to');
            } else {
                $query->where('assigned_to', (int) $assignedTo);
            }
        }
        if ($scheduledDate = $request->query('scheduled_date')) {
            $query->whereDate('scheduled_date', (string) $scheduledDate);
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('task_reference', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhereHas('room', fn ($rq) => $rq->where('room_number', 'like', "%{$search}%"));
            });
        }

        $tasks = $query
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END ASC")
            ->orderByDesc('scheduled_date')
            ->orderByDesc('id')
            ->paginate(25);

        $rooms = Room::with(['roomType:id,name,code', 'lastInspector:id,name'])
            ->orderBy('sort_order')
            ->orderBy('room_number')
            ->get();

        $staff = User::where('is_active', true)
            ->whereHas('roles.permissions', fn ($q) => $q->whereIn('slug', ['housekeeping.view', 'housekeeping.update', 'housekeeping.complete']))
            ->select(['id', 'name', 'email'])
            ->orderBy('name')
            ->get();

        $summary = [
            'pending' => HousekeepingTask::where('status', 'pending')->count(),
            'assigned' => HousekeepingTask::where('status', 'assigned')->count(),
            'in_progress' => HousekeepingTask::where('status', 'in_progress')->count(),
            'completed_awaiting_inspection' => HousekeepingTask::where('status', 'completed')->count(),
            'inspected' => HousekeepingTask::where('status', 'inspected')->count(),
            'failed_inspection' => HousekeepingTask::where('status', 'failed_inspection')->count(),
            'rooms_dirty' => $rooms->where('housekeeping_status', 'dirty')->count(),
            'rooms_inspecting' => $rooms->where('housekeeping_status', 'inspecting')->count(),
            'rooms_ready' => $rooms->whereIn('housekeeping_status', ['clean', 'inspected'])->where('operational_status', 'active')->count(),
            'rooms_maintenance' => $rooms->where('housekeeping_status', 'maintenance')->count(),
        ];

        return response()->json([
            'data' => $tasks->items(),
            'meta' => [
                'current_page' => $tasks->currentPage(),
                'last_page' => $tasks->lastPage(),
                'total' => $tasks->total(),
            ],
            'summary' => $summary,
            'rooms' => $rooms,
            'assignable_staff' => $staff,
        ]);
    }

    public function show(Request $request, HousekeepingTask $housekeepingTask): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['housekeeping.view', 'housekeeping.manage'])) {
            abort(403, 'You do not have permission to view housekeeping tasks.');
        }

        $housekeepingTask->load([
            'room.roomType',
            'assignee:id,name,email',
            'assigner:id,name',
            'completer:id,name',
            'inspector:id,name',
            'reservation:id,reference,guest_name,check_in_date,check_out_date,status',
            'parentTask:id,task_reference,title,status',
            'followupTasks:id,parent_task_id,task_reference,title,status',
            'inspections.inspector:id,name',
            'histories.actor:id,name',
        ]);

        return response()->json(['data' => $housekeepingTask]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['housekeeping.manage', 'housekeeping.assign'])) {
            abort(403, 'You do not have permission to create housekeeping tasks.');
        }

        $data = $request->validate([
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'reservation_id' => ['nullable', 'integer', 'exists:reservations,id'],
            'task_type' => ['required', 'string', Rule::in([
                'checkout_cleaning',
                'stayover_cleaning',
                'deep_cleaning',
                'inspection_followup',
                'maintenance_followup',
                'manual_request',
            ])],
            'priority' => ['nullable', 'string', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'scheduled_date' => ['nullable', 'date_format:Y-m-d'],
            'due_time' => ['nullable', 'date_format:H:i'],
            'title' => ['nullable', 'string', 'max:180'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $task = $this->housekeeping->createTask($data, $request->user());

        return response()->json(['message' => "Housekeeping task {$task->task_reference} created.", 'data' => $task], 201);
    }

    public function assign(Request $request, HousekeepingTask $housekeepingTask): JsonResponse
    {
        Gate::authorize('housekeeping.assign');

        $data = $request->validate([
            'assigned_to' => ['required', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $updated = $this->housekeeping->assignTask($housekeepingTask, (int) $data['assigned_to'], $request->user(), $data['notes'] ?? null);

        return response()->json(['message' => 'Housekeeping task assigned.', 'data' => $updated]);
    }

    public function start(Request $request, HousekeepingTask $housekeepingTask): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['housekeeping.complete', 'housekeeping.update', 'housekeeping.manage'])) {
            abort(403, 'You do not have permission to start housekeeping tasks.');
        }

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $updated = $this->housekeeping->startTask($housekeepingTask, $request->user(), $data['notes'] ?? null);

        return response()->json(['message' => 'Housekeeping task started.', 'data' => $updated]);
    }

    public function complete(Request $request, HousekeepingTask $housekeepingTask): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['housekeeping.complete', 'housekeeping.update', 'housekeeping.manage'])) {
            abort(403, 'You do not have permission to complete housekeeping tasks.');
        }

        $data = $request->validate([
            'completion_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $updated = $this->housekeeping->completeTask($housekeepingTask, $request->user(), $data['completion_notes'] ?? null);

        return response()->json(['message' => 'Housekeeping task marked completed and queued for inspection.', 'data' => $updated]);
    }

    public function inspect(Request $request, HousekeepingTask $housekeepingTask): JsonResponse
    {
        Gate::authorize('housekeeping.inspect');

        $data = $request->validate([
            'outcome' => ['required', 'string', Rule::in(['passed', 'failed'])],
            'checklist' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $updated = $this->housekeeping->inspectTask($housekeepingTask, $data, $request->user());

        return response()->json([
            'message' => $data['outcome'] === 'passed'
                ? 'Room inspection passed. Room is now ready.'
                : 'Room inspection failed. Room returned to dirty and corrective task created.',
            'data' => $updated,
        ]);
    }

    public function cancel(Request $request, HousekeepingTask $housekeepingTask): JsonResponse
    {
        Gate::authorize('housekeeping.manage');

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $updated = $this->housekeeping->cancelTask($housekeepingTask, $data['reason'], $request->user());

        return response()->json(['message' => 'Housekeeping task cancelled.', 'data' => $updated]);
    }
}
