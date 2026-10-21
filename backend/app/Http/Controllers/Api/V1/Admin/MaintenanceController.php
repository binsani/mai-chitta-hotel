<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\MaintenanceWorkOrder;
use App\Models\Room;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\MaintenanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class MaintenanceController extends Controller
{
    public function __construct(
        private MaintenanceService $maintenance,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('maintenance.view');

        $user = $request->user();
        $canViewCosts = $user->hasPermission('maintenance.costs.view');

        $query = MaintenanceWorkOrder::query()
            ->with(['room.roomType', 'reporter:id,name', 'assignee:id,name,email', 'resolver:id,name', 'followupHousekeepingTask:id,task_reference,status']);

        if ($status = $request->query('status')) {
            $query->where('status', (string) $status);
        }
        if ($priority = $request->query('priority')) {
            $query->where('priority', (string) $priority);
        }
        if ($category = $request->query('category')) {
            $query->where('category', (string) $category);
        }
        if ($roomId = $request->query('room_id')) {
            $query->where('room_id', (int) $roomId);
        }
        if ($request->filled('blocks_room')) {
            $query->where('blocks_room', $request->boolean('blocks_room'));
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('work_order_reference', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('area_label', 'like', "%{$search}%")
                    ->orWhereHas('room', fn ($rq) => $rq->where('room_number', 'like', "%{$search}%"));
            });
        }

        $orders = $query
            ->orderByRaw("CASE priority WHEN 'emergency' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END ASC")
            ->orderByDesc('id')
            ->paginate(25);

        if ($canViewCosts) {
            $orders->getCollection()->each(fn (MaintenanceWorkOrder $wo) => $wo->makeVisible([
                'parts_cost_minor', 'labor_cost_minor', 'total_cost_minor',
                'parts_cost', 'labor_cost', 'total_cost',
                'vendor_name', 'vendor_invoice_reference',
            ]));
        }

        $rooms = Room::with('roomType:id,name,code')->orderBy('sort_order')->orderBy('room_number')->get();
        $technicians = User::where('is_active', true)
            ->whereHas('roles.permissions', fn ($q) => $q->whereIn('slug', ['maintenance.manage', 'maintenance.update']))
            ->select(['id', 'name', 'email'])
            ->orderBy('name')
            ->get();

        $summary = [
            'open_orders' => MaintenanceWorkOrder::whereIn('status', MaintenanceWorkOrder::OPEN_STATUSES)->count(),
            'emergency_open' => MaintenanceWorkOrder::whereIn('status', MaintenanceWorkOrder::OPEN_STATUSES)->where('priority', 'emergency')->count(),
            'awaiting_parts' => MaintenanceWorkOrder::where('status', MaintenanceWorkOrder::STATUS_AWAITING_PARTS)->count(),
            'blocking_rooms' => MaintenanceWorkOrder::whereIn('status', MaintenanceWorkOrder::OPEN_STATUSES)->where('blocks_room', true)->count(),
            'arrival_conflicts' => MaintenanceWorkOrder::whereIn('status', MaintenanceWorkOrder::OPEN_STATUSES)->where('has_arrival_conflict', true)->count(),
            'resolved' => MaintenanceWorkOrder::where('status', MaintenanceWorkOrder::STATUS_RESOLVED)->count(),
            'closed' => MaintenanceWorkOrder::where('status', MaintenanceWorkOrder::STATUS_CLOSED)->count(),
        ];

        if ($canViewCosts) {
            $totalCostMinor = (int) MaintenanceWorkOrder::where('status', '!=', MaintenanceWorkOrder::STATUS_CANCELLED)->sum('total_cost_minor');
            $summary['total_repair_cost_minor'] = $totalCostMinor;
            $summary['total_repair_cost_amount'] = AvailabilityService::toMajor($totalCostMinor);
        }

        return response()->json([
            'data' => $orders->items(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'total' => $orders->total(),
            ],
            'summary' => $summary,
            'rooms' => $rooms,
            'technicians' => $technicians,
        ]);
    }

    public function show(Request $request, MaintenanceWorkOrder $maintenanceWorkOrder): JsonResponse
    {
        Gate::authorize('maintenance.view');

        $maintenanceWorkOrder->load([
            'room.roomType',
            'reporter:id,name',
            'assignee:id,name,email',
            'resolver:id,name',
            'closer:id,name',
            'followupHousekeepingTask:id,task_reference,title,status',
            'histories.actor:id,name',
        ]);

        if ($request->user()->hasPermission('maintenance.costs.view')) {
            $maintenanceWorkOrder->makeVisible([
                'parts_cost_minor', 'labor_cost_minor', 'total_cost_minor',
                'parts_cost', 'labor_cost', 'total_cost',
                'vendor_name', 'vendor_invoice_reference',
            ]);
        }

        return response()->json(['data' => $maintenanceWorkOrder]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('maintenance.create');

        $data = $request->validate([
            'room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'area_label' => ['nullable', 'string', 'max:120'],
            'category' => ['required', 'string', Rule::in([
                'plumbing', 'electrical', 'hvac', 'furniture', 'appliance',
                'bathroom', 'door_lock', 'painting_civil', 'wifi_tv', 'other',
            ])],
            'priority' => ['nullable', 'string', Rule::in(['low', 'normal', 'high', 'emergency'])],
            'title' => ['required', 'string', 'min:3', 'max:180'],
            'description' => ['required', 'string', 'min:3', 'max:3000'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
            'blocks_room' => ['sometimes', 'boolean'],
            'mark_out_of_service' => ['sometimes', 'boolean'],
            'blocked_from' => ['nullable', 'date_format:Y-m-d'],
            'blocked_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:blocked_from'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'parts_cost' => ['nullable', 'numeric', 'min:0'],
            'labor_cost' => ['nullable', 'numeric', 'min:0'],
            'vendor_name' => ['nullable', 'string', 'max:160'],
            'vendor_invoice_reference' => ['nullable', 'string', 'max:80'],
        ]);

        if (! empty($data['assigned_to']) && ! $request->user()->hasPermission('maintenance.assign')) {
            abort(403, 'You do not have permission to assign maintenance work orders.');
        }

        $wo = $this->maintenance->createWorkOrder($data, $request->user());
        if ($request->user()->hasPermission('maintenance.costs.view')) {
            $wo->makeVisible([
                'parts_cost_minor', 'labor_cost_minor', 'total_cost_minor',
                'parts_cost', 'labor_cost', 'total_cost',
                'vendor_name', 'vendor_invoice_reference',
            ]);
        }

        return response()->json(['message' => "Work order {$wo->work_order_reference} reported.", 'data' => $wo], 201);
    }

    public function assign(Request $request, MaintenanceWorkOrder $maintenanceWorkOrder): JsonResponse
    {
        Gate::authorize('maintenance.assign');

        $data = $request->validate([
            'assigned_to' => ['required', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $updated = $this->maintenance->assignWorkOrder($maintenanceWorkOrder, (int) $data['assigned_to'], $request->user(), $data['notes'] ?? null);
        if ($request->user()->hasPermission('maintenance.costs.view')) {
            $updated->makeVisible([
                'parts_cost_minor', 'labor_cost_minor', 'total_cost_minor',
                'parts_cost', 'labor_cost', 'total_cost',
                'vendor_name', 'vendor_invoice_reference',
            ]);
        }

        return response()->json(['message' => 'Work order assigned.', 'data' => $updated]);
    }

    public function update(Request $request, MaintenanceWorkOrder $maintenanceWorkOrder): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['maintenance.manage', 'maintenance.update', 'maintenance.close'])) {
            abort(403, 'You do not have permission to update maintenance work orders.');
        }

        $data = $request->validate([
            'status' => ['sometimes', 'string', Rule::in([
                'reported', 'acknowledged', 'assigned', 'in_progress',
                'awaiting_parts', 'resolved', 'closed', 'cancelled',
            ])],
            'priority' => ['sometimes', 'string', Rule::in(['low', 'normal', 'high', 'emergency'])],
            'diagnosis_notes' => ['nullable', 'string', 'max:2000'],
            'resolution_notes' => ['nullable', 'string', 'max:2000'],
            'parts_wait_notes' => ['nullable', 'string', 'max:2000'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'blocks_room' => ['sometimes', 'boolean'],
            'mark_out_of_service' => ['sometimes', 'boolean'],
            'blocked_from' => ['nullable', 'date_format:Y-m-d'],
            'blocked_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:blocked_from'],
            'parts_cost' => ['nullable', 'numeric', 'min:0'],
            'labor_cost' => ['nullable', 'numeric', 'min:0'],
            'vendor_name' => ['nullable', 'string', 'max:160'],
            'vendor_invoice_reference' => ['nullable', 'string', 'max:80'],
        ]);

        $updated = $this->maintenance->updateStatusOrDetails($maintenanceWorkOrder, $data, $request->user());
        if ($request->user()->hasPermission('maintenance.costs.view')) {
            $updated->makeVisible([
                'parts_cost_minor', 'labor_cost_minor', 'total_cost_minor',
                'parts_cost', 'labor_cost', 'total_cost',
                'vendor_name', 'vendor_invoice_reference',
            ]);
        }

        return response()->json(['message' => 'Maintenance work order updated.', 'data' => $updated]);
    }
}
