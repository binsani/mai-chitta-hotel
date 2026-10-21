<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomContent extends Model
{
    protected $fillable = [
        'slug', 'title', 'subtitle', 'summary', 'description',
        'occupancy_label', 'bed_configuration', 'indicative_rate', 'amenities',
        'featured_media_id', 'status', 'is_featured', 'sort_order',
        'seo_title', 'seo_description', 'published_at', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'indicative_rate' => 'float',
            'amenities' => 'array',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    public function featuredMedia(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'featured_media_id');
    }
}
