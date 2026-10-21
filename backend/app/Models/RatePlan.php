<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RatePlan extends Model
{
    protected $fillable = [
        'name', 'code', 'description', 'meal_Basis',
        'cancellation_policy_type', 'free_cancellation_hours',
        'cancellation_penalty_type', 'cancellation_penalty_value', 'cancellation_terms',
        'currency_code', 'default_base_rate_minor', 'default_base_rate',
        'extra_adult_rate_minor', 'extra_adult_rate', 'extra_child_rate_minor', 'extra_child_rate',
        'effective_from', 'effective_until', 'min_stay_nights', 'max_stay_nights',
        'closed_to_arrival', 'closed_to_departure', 'is_active', 'is_public',
        'is_refundable', 'sort_order', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'free_cancellation_hours' => 'integer',
            'cancellation_penalty_value' => 'float',
            'default_base_rate_minor' => 'integer',
            'default_base_rate' => 'float',
            'extra_adult_rate_minor' => 'integer',
            'extra_adult_rate' => 'float',
            'extra_child_rate_minor' => 'integer',
            'extra_child_rate' => 'float',
            'effective_from' => 'date:Y-m-d',
            'effective_until' => 'date:Y-m-d',
            'min_stay_nights' => 'integer',
            'max_stay_nights' => 'integer',
            'closed_to_arrival' => 'boolean',
            'closed_to_departure' => 'boolean',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'is_refundable' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_public', true);
    }

    public function roomTypeRates(): HasMany
    {
        return $this->hasMany(RatePlanRoomType::class, 'rate_plan_id');
    }

    public function roomTypes(): BelongsToMany
    {
        return $this->belongsToMany(RoomType::class, 'rate_plan_room_types')
            ->withPivot([
                'id', 'base_rate_minor', 'base_rate', 'extra_adult_rate_minor',
                'extra_adult_rate', 'extra_child_rate_minor', 'extra_child_rate',
                'weekday_rates_minor', 'weekday_rates', 'is_active',
            ])
            ->withTimestamps();
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(RatePlanOverride::class, 'rate_plan_id');
    }
}
