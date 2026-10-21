<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RatePlanRoomType extends Model
{
    protected $table = 'rate_plan_room_types';

    protected $fillable = [
        'rate_plan_id', 'room_type_id',
        'base_rate_minor', 'base_rate',
        'extra_adult_rate_minor', 'extra_adult_rate',
        'extra_child_rate_minor', 'extra_child_rate',
        'weekday_rates_minor', 'weekday_rates', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'rate_plan_id' => 'integer',
            'room_type_id' => 'integer',
            'base_rate_minor' => 'integer',
            'base_rate' => 'float',
            'extra_adult_rate_minor' => 'integer',
            'extra_adult_rate' => 'float',
            'extra_child_rate_minor' => 'integer',
            'extra_child_rate' => 'float',
            'weekday_rates_minor' => 'array',
            'weekday_rates' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class, 'rate_plan_id');
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class, 'room_type_id');
    }
}
