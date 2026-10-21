<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaintenanceWorkOrder extends Model
{
    public const STATUS_REPORTED = 'reported';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_AWAITING_PARTS = 'awaiting_parts';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';

    public const OPEN_STATUSES = [
        self::STATUS_REPORTED,
        self::STATUS_ACKNOWLEDGED,
        self::STATUS_ASSIGNED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_AWAITING_PARTS,
    ];

    protected $fillable = [
        'work_order_reference', 'room_id', 'area_label', 'category', 'priority', 'status',
        'title', 'description', 'Internal_notes', 'diagnosis_notes', 'resolution_notes', 'parts_Wait_notes',
        'blocks_room', 'blocked_from', 'blocked_until',
        'has_arrival_conflict', 'conflicting_reservation_ids',
        'currency_code',
        'parts_cost_minor', 'labor_cost_minor', 'total_cost_minor',
        'parts_cost', 'labor_cost', 'total_cost',
        'vendor_name', 'vendor_invoice_reference',
        'reported_by', 'reported_at',
        'acknowledged_by', 'acknowledged_at',
        'assigned_to', 'assigned_by', 'assigned_at',
        'started_at',
        'resolved_at', 'resolved_by',
        'closed_at', 'closed_by',
        'reopened_at', 'reopened_by',
        'followup_housekeeping_task_id',
    ];

    protected $hidden = [
        'parts_cost_minor', 'labor_cost_minor', 'total_cost_minor',
        'parts_cost', 'labor_cost', 'total_cost',
        'vendor_name', 'vendor_invoice_reference',
    ];

    protected function casts(): array
    {
        return [
            'room_id' => 'integer',
            'blocks_room' => 'boolean',
            'blocked_from' => 'date:Y-m-d',
            'blocked_until' => 'date:Y-m-d',
            'has_arrival_conflict' => 'boolean',
            'conflicting_reservation_ids' => 'array',
            'parts_cost_minor' => 'integer',
            'labor_cost_minor' => 'integer',
            'total_cost_minor' => 'integer',
            'parts_cost' => 'float',
            'labor_cost' => 'float',
            'total_cost' => 'float',
            'reported_by' => 'integer',
            'reported_at' => 'datetime',
            'acknowledged_by' => 'integer',
            'acknowledged_at' => 'datetime',
            'assigned_to' => 'integer',
            'assigned_by' => 'integer',
            'assigned_at' => 'datetime',
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
            'resolved_by' => 'integer',
            'closed_at' => 'datetime',
            'closed_by' => 'integer',
            'reopened_at' => 'datetime',
            'reopened_by' => 'integer',
            'followup_housekeeping_task_id' => 'integer',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function followupHousekeepingTask(): BelongsTo
    {
        return $this->belongsTo(HousekeepingTask::class, 'followup_housekeeping_task_id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(MaintenanceWorkOrderHistory::class, 'maintenance_work_order_id')->orderByDesc('id');
    }
}
