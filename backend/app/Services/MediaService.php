<?php

namespace App\Services;

use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MediaService
{
    /** Strict allowlist of safe upload MIME types and extensions. SVG is excluded from user uploads to prevent stored XSS. */
    public const ALLOWED_MIMES = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'application/pdf' => ['pdf'],
    ];

    public const MAX_SIZE_BYTES = 5 * 1024 * 1024; // 5 MB

    public function __construct(private AuditLogger $audit) {}

    public function upload(UploadedFile $file, array $meta, User $actor): MediaAsset
    {
        $mime = strtolower((string) $file->getMimeType());
        $ext = strtolower($file->getClientOriginalExtension());

        if (! isset(self::ALLOWED_MIMES[$mime]) || ! in_array($ext, self::ALLOWED_MIMES[$mime], true)) {
            throw ValidationException::withMessages([
                'file' => 'Unsupported file type. Allowed formats: JPG, PNG, WEBP, PDF.',
            ]);
        }

        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            throw ValidationException::withMessages([
                'file' => 'File exceeds the 5 MB limit.',
            ]);
        }

        $isPublic = (bool) ($meta['is_public'] ?? true);
        $disk = $isPublic ? 'public' : 'local';
        $uuid = (string) Str::uuid();
        $filename = $uuid.'.'.$ext;
        $directory = 'media/'.now()->format('Y/m');
        $path = $file->storeAs($directory, $filename, $disk);

        $width = null;
        $height = null;
        if (str_starts_with($mime, 'image/')) {
            $info = @getimagesize($file->getRealPath());
            if (is_array($info)) {
                [$width, $height] = [$info[0] ?? null, $info[1] ?? null];
            }
        }

        $asset = MediaAsset::create([
            'uuid' => $uuid,
            'disk' => $disk,
            'path' => $path,
            'original_name' => substr($file->getClientOriginalName(), 0, 255),
            'filename' => $filename,
            'mime_type' => $mime,
            'extension' => $ext,
            'size_bytes' => (int) $file->getSize(),
            'width' => $width,
            'height' => $height,
            'alt_text' => strip_tags((string) $meta['alt_text']),
            'title' => isset($meta['title']) ? strip_tags((string) $meta['title']) : pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'caption' => isset($meta['caption']) ? strip_tags((string) $meta['caption']) : null,
            'category' => $meta['category'] ?? 'general',
            'is_public' => $isPublic,
            'in_gallery' => $isPublic && (bool) ($meta['in_gallery'] ?? false),
            'sort_order' => (int) ($meta['sort_order'] ?? 0),
            'uploaded_by' => $actor->id,
        ]);

        $this->audit->log('cms.media.uploaded', $asset, [
            'filename' => $asset->original_name,
            'mime_type' => $asset->mime_type,
            'is_public' => $asset->is_public,
        ]);
        CmsService::bumpPublicCache();

        return $asset;
    }

    public function update(MediaAsset $asset, array $meta, User $actor): MediaAsset
    {
        $attrs = [];
        foreach (['alt_text', 'title', 'caption', 'category'] as $field) {
            if (array_key_exists($field, $meta)) {
                $attrs[$field] = $meta[$field] !== null ? strip_tags((string) $meta[$field]) : null;
            }
        }
        if (array_key_exists('in_gallery', $meta)) {
            $attrs['in_gallery'] = $asset->is_public && (bool) $meta['in_gallery'];
        }
        if (array_key_exists('sort_order', $meta)) {
            $attrs['sort_order'] = (int) $meta['sort_order'];
        }
        $asset->fill($attrs)->save();
        $this->audit->log('cms.media.updated', $asset, ['fields' => array_keys($attrs)]);
        CmsService::bumpPublicCache();

        return $asset;
    }

    public function delete(MediaAsset $asset, User $actor): void
    {
        Storage::disk($asset->disk)->delete($asset->path);
        $this->audit->log('cms.media.deleted', $asset, ['filename' => $asset->original_name]);
        $asset->delete();
        CmsService::bumpPublicCache();
    }
}
