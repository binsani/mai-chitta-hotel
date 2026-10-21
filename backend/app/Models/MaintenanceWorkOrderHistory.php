<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class MaintenanceWorkOrderHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'maintenance_work_order_id', 'from_status', 'to_status',
        'notes', 'metadata', 'changed_by', 'created_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Maintenance work order history records are immutable.');
        });
        static::deleting(function (): void {
            throw new LogicException('Maintenance work order history records cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'maintenance_work_order_id' => 'integer',
            'metadata' => 'array',
            'changed_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(MaintenanceWorkOrder::class, 'maintenance_work_order_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
