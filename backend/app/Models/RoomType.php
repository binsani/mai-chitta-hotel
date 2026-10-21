<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoomType extends Model
{
    protected $fillable = [
        'name', 'slug', 'code', 'subtitle', 'description', 'public_description',
        'max_adults', 'max_children', 'max_occupancy', 'base_occupancy',
        'bed_configuration', 'size_label', 'amenities', 'featured_media_id',
        'gallery_media_ids', 'starting_rate_minor', 'starting_rate', 'currency_code',
        'is_active', 'is_public', 'is_featured', 'sort_order',
        'housekeeping_notes', 'operational_notes', 'room_content_id',
        'created_by', 'updated_by',
    ];

    protected $hidden = [
        'housekeeping_notes',
        'operational_notes',
    ];

    protected function casts(): array
    {
        return [
            'max_adults' => 'integer',
            'max_children' => 'integer',
            'max_occupancy' => 'integer',
            'base_occupancy' => 'integer',
            'amenities' => 'array',
            'gallery_media_ids' => 'array',
            'starting_rate_minor' => 'integer',
            'starting_rate' => 'float',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_public', true);
    }

    public function featuredMedia(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'featured_media_id');
    }

    public function roomContent(): BelongsTo
    {
        return $this->belongsTo(RoomContent::class, 'room_content_id');
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class, 'room_type_id');
    }

    public function ratePlanPivot(): HasMany
    {
        return $this->hasMany(RatePlanRoomType::class, 'room_type_id');
    }

    public function ratePlans(): BelongsToMany
    {
        return $this->belongsToMany(RatePlan::class, 'rate_plan_room_types')
            ->withPivot([
                'id', 'base_rate_minor', 'base_rate', 'extra_adult_rate_minor',
                'extra_adult_rate', 'extra_child_rate_minor', 'extra_child_rate',
                'weekday_rates_minor', 'weekday_rates', 'is_active',
            ])
            ->withTimestamps();
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(RatePlanOverride::class, 'room_type_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'room_type_id');
    }
}
