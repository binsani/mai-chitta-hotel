<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationNight extends Model
{
    protected $fillable = [
        'reservation_id', 'room_type_id', 'rate_plan_id', 'room_id', 'active_room_id',
        'unit_index', 'stay_date', 'currency_code',
        'base_rate_minor', 'extra_guest_minor', 'total_night_minor', 'total_night_amount',
        'pricing_source', 'status',
    ];

    protected function casts(): array
    {
        return [
            'reservation_id' => 'integer',
            'room_type_id' => 'integer',
            'rate_plan_id' => 'integer',
            'room_id' => 'integer',
            'active_room_id' => 'integer',
            'unit_index' => 'integer',
            'stay_date' => 'date:Y-m-d',
            'base_rate_minor' => 'integer',
            'extra_guest_minor' => 'integer',
            'total_night_minor' => 'integer',
            'total_night_amount' => 'float',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id');
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class, 'room_type_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }
}
