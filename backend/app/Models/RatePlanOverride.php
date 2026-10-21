<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RatePlanOverride extends Model
{
    protected $fillable = [
        'rate_plan_id', 'room_type_id', 'label', 'override_type',
        'start_date', 'end_date',
        'nightly_rate_minor', 'nightly_rate',
        'extra_adult_rate_minor', 'extra_adult_rate',
        'extra_child_rate_minor', 'extra_child_rate',
        'min_stay_nights', 'max_stay_nights',
        'closed_to_sell', 'closed_to_arrival', 'closed_to_departure',
        'priority', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'rate_plan_id' => 'integer',
            'room_type_id' => 'integer',
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'nightly_rate_minor' => 'integer',
            'nightly_rate' => 'float',
            'extra_adult_rate_minor' => 'integer',
            'extra_adult_rate' => 'float',
            'extra_child_rate_minor' => 'integer',
            'extra_child_rate' => 'float',
            'min_stay_nights' => 'integer',
            'max_stay_nights' => 'integer',
            'closed_to_sell' => 'boolean',
            'closed_to_arrival' => 'boolean',
            'closed_to_departure' => 'boolean',
            'priority' => 'integer',
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
