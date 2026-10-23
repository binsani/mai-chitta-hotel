<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ContactInquiry;
use App\Models\MediaAsset;
use App\Models\NavigationMenu;
use App\Models\Offer;
use App\Models\Page;
use App\Models\RoomContent;
use App\Models\Testimonial;
use App\Services\AuditLogger;
use App\Services\CmsService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Public read-only endpoints for the Mai Chitta Hotel website.
 * Strictly returns published/public records only; drafts and archived items are never exposed.
 */
class PublicWebsiteController extends Controller
{
    public function __construct(
        private SettingsService $settings,
        private AuditLogger $audit,
    ) {}

    public function bootstrap(): JsonResponse
    {
        $ver = CmsService::cacheVersion();
        $payload = Cache::remember("cms.public.bootstrap.arrays.v{$ver}", 600, function () {
            $publicSettings = $this->settings->all(publicOnly: true);
            $menus = NavigationMenu::with(['items' => fn ($q) => $q->where('is_visible', true)->orderBy('sort_order')])
                ->get()
                ->keyBy('slug')
                ->map(fn (NavigationMenu $m) => $m->items->map(fn ($i) => [
                    'id' => $i->id,
                    'label' => $i->label,
                    'url' => $i->url,
                    'target' => $i->target,
                ])->values());

            $heroMediaId = $publicSettings['website']['hero_media_id'] ?? null;
            $heroMedia = $heroMediaId ? MediaAsset::where('is_public', true)->find($heroMediaId) : null;

            return [
                'settings' => $publicSettings,
                'hero_media' => $heroMedia ? $heroMedia->toArray() : null,
                'menus' => [
                    'header' => $menus->get('header')?->values()->all() ?? [],
                    'footer' => $menus->get('footer')?->values()->all() ?? [],
                ],
                'featured_rooms' => RoomContent::published()
                    ->with('featuredMedia')
                    ->orderByDesc('is_featured')
                    ->orderBy('sort_order')
                    ->limit(6)
                    ->get()->toArray(),
                'featured_offers' => Offer::published()
                    ->with('featuredMedia')
                    ->orderByDesc('is_featured')
                    ->orderBy('sort_order')
                    ->limit(4)
                    ->get()->toArray(),
                'gallery_preview' => MediaAsset::where('is_public', true)
                    ->where('in_gallery', true)
                    ->orderBy('sort_order')
                    ->orderByDesc('id')
                    ->limit(6)
                    ->get()->toArray(),
                'testimonials' => Testimonial::published()
                    ->orderByDesc('is_featured')
                    ->orderBy('sort_order')
                    ->limit(6)
                    ->get()->toArray(),
            ];
        });

        return response()->json(['data' => $payload]);
    }

    public function pages(): JsonResponse
    {
        $pages = Page::published()
            ->select(['id', 'slug', 'title', 'subtitle', 'excerpt', 'template', 'published_at'])
            ->orderBy('title')
            ->get();

        return response()->json(['data' => $pages]);
    }

    public function showPage(string $slug): JsonResponse
    {
        $ver = CmsService::cacheVersion();
        $page = Cache::remember("cms.public.page.{$slug}.v{$ver}", 600, function () use ($slug) {
            $model = Page::published()
                ->where('slug', $slug)
                ->with([
                    'featuredMedia',
                    'ogImage',
                    'sections' => fn ($q) => $q->where('is_visible', true)->orderBy('sort_order')->with('media'),
                ])
                ->first();

            return $model ? $model->toArray() : null;
        });

        abort_if(! $page, 404, 'Page not found.');

        return response()->json(['data' => $page]);
    }

    public function rooms(): JsonResponse
    {
        $rooms = RoomContent::published()
            ->with('featuredMedia')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $rooms]);
    }

    public function showRoom(string $slug): JsonResponse
    {
        $room = RoomContent::published()
            ->where('slug', $slug)
            ->with('featuredMedia')
            ->firstOrFail();

        return response()->json(['data' => $room]);
    }

    public function offers(): JsonResponse
    {
        $offers = Offer::published()
            ->with('featuredMedia')
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $offers]);
    }

    public function showOffer(string $slug): JsonResponse
    {
        $offer = Offer::published()
            ->where('slug', $slug)
            ->with('featuredMedia')
            ->firstOrFail();

        return response()->json(['data' => $offer]);
    }

    public function gallery(Request $request): JsonResponse
    {
        $category = $request->query('category');
        $items = MediaAsset::where('is_public', true)
            ->where('in_gallery', true)
            ->when(is_string($category) && $category !== '', fn ($q) => $q->where('category', $category))
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->paginate(24);

        return response()->json($items);
    }

    public function testimonials(): JsonResponse
    {
        $items = Testimonial::published()
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $items]);
    }

    public function submitContact(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+()\s-]*$/'],
            'subject' => ['required', 'string', 'max:180'],
            'message' => ['required', 'string', 'min:10', 'max:3000'],
        ]);

        $inquiry = ContactInquiry::create([
            'name' => strip_tags($data['name']),
            'email' => $data['email'],
            'phone' => isset($data['phone']) ? strip_tags($data['phone']) : null,
            'subject' => strip_tags($data['subject']),
            'message' => strip_tags($data['message']),
            'ip_address' => $request->ip(),
            'status' => 'new',
        ]);

        $this->audit->log('public.contact_submitted', $inquiry, ['subject' => $inquiry->subject]);

        return response()->json([
            'message' => 'Thank you for contacting Mai Chitta Hotel. Our team has received your inquiry.',
        ], 201);
    }
}
