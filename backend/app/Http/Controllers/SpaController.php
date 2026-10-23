<?php

namespace App\Http\Controllers;

use App\Models\Offer;
use App\Models\Page;
use App\Models\RoomContent;
use App\Services\CmsService;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Serves the React SPA shell for both the public website (/) and the
 * staff administration portal (/admin/*), injecting server-rendered SEO
 * metadata (<title>, description, canonical, OpenGraph, robots) into <head>.
 */
class SpaController extends Controller
{
    public function __construct(private SettingsService $settings) {}

    public function __invoke(Request $request): Response
    {
        $shellPath = public_path('spa.html');
        if (! is_file($shellPath)) {
            $shellPath = public_path('admin/index.html');
        }
        abort_unless(is_file($shellPath), 503, 'Frontend build not found. Run "npm run build" in /frontend.');

        $html = (string) file_get_contents($shellPath);
        try {
            $version = CmsService::cacheVersion();
            $key = 'cms.spa.meta.v'.$version.'.'.hash('sha256', $request->root().'|'.$request->path());
            $meta = Cache::remember($key, 300, fn () => $this->resolveMeta($request));
        } catch (\Throwable) {
            $meta = $this->resolveMeta($request);
        }

        $tags = implode("\n    ", array_filter([
            '<link rel="icon" type="image/webp" href="/logo.webp" />',
            '<link rel="apple-touch-icon" href="/logo.jpg" />',
            '<title>'.e($meta['title']).'</title>',
            '<meta name="description" content="'.e($meta['description']).'" />',
            '<meta name="robots" content="'.e($meta['robots']).'" />',
            $meta['canonical'] ? '<link rel="canonical" href="'.e($meta['canonical']).'" />' : null,
            '<meta property="og:site_name" content="'.e($meta['site_name']).'" />',
            '<meta property="og:title" content="'.e($meta['title']).'" />',
            '<meta property="og:description" content="'.e($meta['description']).'" />',
            '<meta property="og:type" content="website" />',
            $meta['canonical'] ? '<meta property="og:url" content="'.e($meta['canonical']).'" />' : null,
            $meta['og_image'] ? '<meta property="og:image" content="'.e($meta['og_image']).'" />' : null,
        ]));

        // Replace default <title> and robots meta in the SPA shell with resolved SEO tags.
        $html = preg_replace('/<title>.*?<\/title>/is', '', $html) ?? $html;
        $html = preg_replace('/<meta\s+name=["\']robots["\'][^>]*>/i', '', $html) ?? $html;
        $html = str_replace('</head>', "    {$tags}\n  </head>", $html);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /** @return array{title: string, description: string, robots: string, canonical: ?string, site_name: string, og_image: ?string} */
    private function resolveMeta(Request $request): array
    {
        $path = trim($request->path(), '/');
        $isAdmin = $path === 'admin' || str_starts_with($path, 'admin/');

        $hotelName = 'Mai Chitta Hotel';
        $defaultTitle = 'Mai Chitta Hotel — Zaria, Kaduna State, Nigeria';
        $defaultDesc = 'Official website of Mai Chitta Hotel in Zaria, Kaduna State, Nigeria.';
        $ogImage = null;

        try {
            if (Schema::hasTable('settings')) {
                $pub = $this->settings->all(publicOnly: true);
                $hotelName = $pub['hotel']['name'] ?: $hotelName;
                $defaultTitle = $pub['website']['seo_default_title'] ?: "{$hotelName} — Zaria, Kaduna State, Nigeria";
                $defaultDesc = $pub['website']['seo_default_description'] ?: $defaultDesc;
            }
        } catch (\Throwable) {
            // Fall back to safe defaults if migrations have not run yet.
        }

        if ($isAdmin) {
            return [
                'title' => "{$hotelName} — Staff Portal",
                'description' => "Administration portal for {$hotelName}.",
                'robots' => 'noindex,nofollow',
                'canonical' => null,
                'site_name' => $hotelName,
                'og_image' => null,
            ];
        }

        $title = $defaultTitle;
        $desc = $defaultDesc;
        $canonical = url($path === '' ? '/' : "/{$path}");
        $robots = 'index,follow';

        try {
            if (str_starts_with($path, 'rooms/') && Schema::hasTable('room_contents')) {
                $slug = substr($path, 6);
                $room = RoomContent::published()->where('slug', $slug)->with('featuredMedia')->first();
                if ($room) {
                    $title = $room->seo_title ?: "{$room->title} | {$hotelName}";
                    $desc = $room->seo_description ?: ($room->summary ?: $defaultDesc);
                    $ogImage = $room->featuredMedia?->url;
                }
            } elseif (str_starts_with($path, 'offers/') && Schema::hasTable('offers')) {
                $slug = substr($path, 7);
                $offer = Offer::published()->where('slug', $slug)->with('featuredMedia')->first();
                if ($offer) {
                    $title = $offer->seo_title ?: "{$offer->title} | {$hotelName}";
                    $desc = $offer->seo_description ?: ($offer->summary ?: $defaultDesc);
                    $ogImage = $offer->featuredMedia?->url;
                }
            } elseif (Schema::hasTable('pages')) {
                $pageSlug = $path === '' ? 'home' : (str_starts_with($path, 'p/') ? substr($path, 2) : $path);
                $page = Page::published()->where('slug', $pageSlug)->with(['ogImage', 'featuredMedia'])->first();
                if ($page) {
                    $title = $page->seo_title ?: ($pageSlug === 'home' ? $defaultTitle : "{$page->title} | {$hotelName}");
                    $desc = $page->seo_description ?: ($page->excerpt ?: $defaultDesc);
                    $canonical = $page->canonical_url ?: $canonical;
                    $robots = $page->noindex ? 'noindex,nofollow' : 'index,follow';
                    $ogImage = $page->ogImage?->url ?? $page->featuredMedia?->url;
                }
            }
        } catch (\Throwable) {
            // Keep defaults on error.
        }

        return [
            'title' => $title,
            'description' => $desc,
            'robots' => $robots,
            'canonical' => $canonical,
            'site_name' => $hotelName,
            'og_image' => $ogImage,
        ];
    }
}
