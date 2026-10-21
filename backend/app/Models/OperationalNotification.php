<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationalNotification extends Model
{
    protected $fillable = [
        'type', 'severity', 'title', 'message',
        'target_permission', 'target_user_id',
        'subject_type', 'subject_id', 'metadata',
        'is_read', 'read_at', 'read_by',
    ];

    protected function casts(): array
    {
        return [
            'target_user_id' => 'integer',
            'subject_id' => 'integer',
            'metadata' => 'array',
            'is_read' => 'boolean',
            'read_at' => 'datetime',
            'read_by' => 'integer',
        ];
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
