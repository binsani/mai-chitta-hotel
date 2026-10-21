<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HousekeepingInspection extends Model
{
    protected $fillable = [
        'housekeeping_task_id', 'room_id', 'outcome',
        'checklist', 'notes', 'followup_task_id',
        'inspected_by', 'inspected_at',
    ];

    protected function casts(): array
    {
        return [
            'housekeeping_task_id' => 'integer',
            'room_id' => 'integer',
            'checklist' => 'array',
            'followup_task_id' => 'integer',
            'inspected_by' => 'integer',
            'inspected_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(HousekeepingTask::class, 'housekeeping_task_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    public function followupTask(): BelongsTo
    {
        return $this->belongsTo(HousekeepingTask::class, 'followup_task_id');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }
}
