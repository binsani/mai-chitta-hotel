<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HousekeepingTask extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_INSPECTED = 'inspected';
    public const STATUS_FAILED_INSPECTION = 'failed_inspection';
    public const STATUS_CANCELLED = 'cancelled';

    public const ACTIVE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ASSIGNED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
    ];

    protected $fillable = [
        'task_reference', 'room_id', 'reservation_id', 'parent_task_id',
        'task_type', 'priority', 'status', 'scheduled_date', 'due_time',
        'title', 'instructions', 'completion_notes', 'internal_notes',
        'assigned_to', 'assigned_by', 'assigned_at',
        'started_at', 'started_by',
        'completed_at', 'completed_by',
        'inspected_at', 'inspected_by',
        'cancelled_at', 'cancelled_by', 'cancellation_reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'room_id' => 'integer',
            'reservation_id' => 'integer',
            'parent_task_id' => 'integer',
            'scheduled_date' => 'date:Y-m-d',
            'assigned_to' => 'integer',
            'assigned_by' => 'integer',
            'assigned_at' => 'datetime',
            'started_at' => 'datetime',
            'started_by' => 'integer',
            'completed_at' => 'datetime',
            'completed_by' => 'integer',
            'inspected_at' => 'datetime',
            'inspected_by' => 'integer',
            'cancelled_at' => 'datetime',
            'cancelled_by' => 'integer',
            'created_by' => 'integer',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id');
    }

    public function parentTask(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_task_id');
    }

    public function followupTasks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_task_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(HousekeepingInspection::class, 'housekeeping_task_id')->orderByDesc('id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(HousekeepingTaskHistory::class, 'housekeeping_task_id')->orderByDesc('id');
    }
}
