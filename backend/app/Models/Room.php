<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    protected $fillable = [
        'room_number', 'room_type_id', 'floor', 'building',
        'operational_status', 'housekeeping_status',
        'last_cleaned_at', 'last_inspected_at', 'last_inspected_by',
        'out_of_service_from', 'out_of_service_until', 'out_of_service_reason',
        'internal_notes', 'sort_order', 'created_by', 'updated_by',
    ];

    protected $appends = [
        'readiness_status',
    ];

    protected function casts(): array
    {
        return [
            'room_type_id' => 'integer',
            'last_cleaned_at' => 'datetime',
            'last_inspected_at' => 'datetime',
            'last_inspected_by' => 'integer',
            'out_of_service_from' => 'date:Y-m-d',
            'out_of_service_until' => 'date:Y-m-d',
            'sort_order' => 'integer',
        ];
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class, 'room_type_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'assigned_room_id');
    }

    public function reservationNights(): HasMany
    {
        return $this->hasMany(ReservationNight::class, 'room_id');
    }

    public function housekeepingTasks(): HasMany
    {
        return $this->hasMany(HousekeepingTask::class, 'room_id');
    }

    public function maintenanceWorkOrders(): HasMany
    {
        return $this->hasMany(MaintenanceWorkOrder::class, 'room_id');
    }

    public function lastInspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_inspected_by');
    }

    /**
     * Computed room readiness state combining operational_status and housekeeping_status.
     */
    public function getReadinessStatusAttribute(): string
    {
        if ($this->operational_status === 'inactive') {
            return 'inactive';
        }
        if ($this->operational_status === 'out_of_service') {
            return 'out_of_service';
        }
        if ($this->housekeeping_status === 'maintenance') {
            return 'blocked_by_maintenance';
        }
        if ($this->housekeeping_status === 'dirty') {
            return 'awaiting_cleaning';
        }
        if ($this->housekeeping_status === 'inspecting') {
            return 'awaiting_inspection';
        }

        return 'ready';
    }

    /**
     * Check whether this room is ready for guest check-in based on hotel policy settings.
     */
    public function isReadyForCheckIn(bool $blockDirtyRoom = true, bool $requireInspectedRoom = false): bool
    {
        if ($this->operational_status !== 'active') {
            return false;
        }
        if ($this->housekeeping_status === 'maintenance') {
            return false;
        }
        if ($blockDirtyRoom && in_array($this->housekeeping_status, ['dirty', 'inspecting'], true)) {
            return false;
        }
        if ($requireInspectedRoom && $this->housekeeping_status !== 'inspected') {
            return false;
        }

        return true;
    }

    /**
     * Determine whether this room is sellable on a specific stay date (Y-m-d).
     */
    public function isSellableOnDate(string $stayDate): bool
    {
        if ($this->operational_status === 'inactive') {
            return false;
        }

        if ($this->operational_status === 'out_of_service') {
            $from = $this->out_of_service_from?->format('Y-m-d');
            $until = $this->out_of_service_until?->format('Y-m-d');

            // If no date bounds are set while status is out_of_service, the room is out of service indefinitely.
            if (! $from && ! $until) {
                return false;
            }
            if ($from && ! $until && $stayDate >= $from) {
                return false;
            }
            if (! $from && $until && $stayDate <= $until) {
                return false;
            }
            if ($from && $until && $stayDate >= $from && $stayDate <= $until) {
                return false;
            }
        }

        return true;
    }
}
