<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Page extends Model
{
    protected $fillable = [
        'slug', 'title', 'subtitle', 'excerpt', 'body', 'template', 'status',
        'is_system', 'featured_media_id', 'seo_title', 'seo_description',
        'canonical_url', 'og_image_id', 'noindex', 'published_at', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'noindex' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(PageSection::class)->orderBy('sort_order')->orderBy('id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(PageRevision::class)->orderByDesc('version');
    }

    public function featuredMedia(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'featured_media_id');
    }

    public function ogImage(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'og_image_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
