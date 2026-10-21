<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class HousekeepingTaskHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'housekeeping_task_id', 'from_status', 'to_status',
        'from_assigned_to', 'to_assigned_to', 'notes',
        'changed_by', 'created_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Housekeeping task history records are immutable.');
        });
        static::deleting(function (): void {
            throw new LogicException('Housekeeping task history records cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'housekeeping_task_id' => 'integer',
            'from_assigned_to' => 'integer',
            'to_assigned_to' => 'integer',
            'changed_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(HousekeepingTask::class, 'housekeeping_task_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
