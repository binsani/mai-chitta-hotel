<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaAsset extends Model
{
    protected $fillable = [
        'uuid', 'disk', 'path', 'original_name', 'filename', 'mime_type', 'extension',
        'size_bytes', 'width', 'height', 'alt_text', 'title', 'caption', 'category',
        'is_public', 'in_gallery', 'sort_order', 'uploaded_by',
    ];

    protected $appends = ['url'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'is_public' => 'boolean',
            'in_gallery' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $asset) {
            $asset->uuid ??= (string) Str::uuid();
        });
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getUrlAttribute(): string
    {
        if (! $this->is_public || $this->disk !== 'public') {
            return url("/api/v1/admin/media/{$this->id}/download");
        }

        return Storage::disk('public')->url($this->path);
    }
}
