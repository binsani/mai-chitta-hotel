<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use App\Services\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends Controller
{
    public function __construct(private MediaService $media) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('media.view');
        $q = trim((string) $request->query('search', ''));
        $category = $request->query('category');
        $inGallery = $request->query('in_gallery');

        $items = MediaAsset::with('uploader:id,name')
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('title', 'like', "%{$q}%")->orWhere('alt_text', 'like', "%{$q}%")->orWhere('original_name', 'like', "%{$q}%")))
            ->when(is_string($category) && $category !== '', fn ($b) => $b->where('category', $category))
            ->when($inGallery !== null && $inGallery !== '', fn ($b) => $b->where('in_gallery', filter_var($inGallery, FILTER_VALIDATE_BOOLEAN)))
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->paginate(min(100, max(6, (int) $request->query('per_page', 24))));

        return response()->json($items);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('media.upload');
        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120'],
            'alt_text' => ['required', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:160'],
            'caption' => ['nullable', 'string', 'max:500'],
            'category' => ['sometimes', 'string', Rule::in(['general', 'rooms', 'restaurant', 'facilities', 'events', 'gallery', 'branding'])],
            'is_public' => ['sometimes', 'boolean'],
            'in_gallery' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $asset = $this->media->upload($request->file('file'), $data, $request->user());

        return response()->json(['data' => $asset], 201);
    }

    public function update(Request $request, MediaAsset $medium): JsonResponse
    {
        Gate::authorize('media.upload');
        $data = $request->validate([
            'alt_text' => ['sometimes', 'required', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:160'],
            'caption' => ['nullable', 'string', 'max:500'],
            'category' => ['sometimes', 'string', Rule::in(['general', 'rooms', 'restaurant', 'facilities', 'events', 'gallery', 'branding'])],
            'in_gallery' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $updated = $this->media->update($medium, $data, $request->user());

        return response()->json(['data' => $updated]);
    }

    public function download(MediaAsset $medium): StreamedResponse
    {
        Gate::authorize('media.view');
        abort_unless(Storage::disk($medium->disk)->exists($medium->path), 404, 'File not found.');

        return Storage::disk($medium->disk)->download($medium->path, $medium->original_name, [
            'Content-Type' => $medium->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, MediaAsset $medium): JsonResponse
    {
        Gate::authorize('media.delete');
        $this->media->delete($medium, $request->user());

        return response()->json(null, 204);
    }
}
