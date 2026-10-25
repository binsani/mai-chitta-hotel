<?php

namespace App\Services;

use App\Models\Offer;
use App\Models\Page;
use App\Models\RoomContent;
use App\Support\HtmlSanitizer;
use Illuminate\Support\Facades\Cache;

class PublicDiscoveryService
{
    public function __construct(private SettingsService $settings) {}

    /** @return array{status: int, html: string, graph: array} */
    public function forPath(string $path): array
    {
        $path = trim($path, '/');
        $key = 'cms.discovery.v'.CmsService::cacheVersion().'.'.hash('sha256', config('app.url').'|'.$path);

        return Cache::remember($key, 60, fn () => $this->build($path));
    }

    /** @return array{status: int, html: string, graph: array} */
    private function build(string $path): array
    {
        $settings = $this->settings->all(publicOnly: true);
        $hotel = $settings['hotel'];
        $booking = $settings['booking'];
        $base = rtrim(config('app.url'), '/');
        if ($settings['website']['maintenance_mode']) {
            return ['status' => 503, 'html' => '<main class="mx-auto max-w-3xl p-8"><h1>'.e($hotel['name']).'</h1><p>Our website is undergoing scheduled updates. Please check back shortly.</p></main>', 'graph' => []];
        }

        $room = null;
        $offer = null;
        $page = null;
        $corePages = ['about', 'rooms', 'booking', 'facilities', 'restaurant', 'gallery', 'offers', 'events', 'contact', 'privacy-policy', 'terms-and-conditions', 'cancellation-policy'];
        if (str_starts_with($path, 'rooms/')) {
            $room = RoomContent::published()->where('slug', substr($path, 6))->first();
        } elseif (str_starts_with($path, 'offers/')) {
            $offer = Offer::published()->where('slug', substr($path, 7))->first();
        } elseif ($path === '' || in_array($path, $corePages, true) || str_starts_with($path, 'p/')) {
            $slug = $path === '' ? 'home' : (str_starts_with($path, 'p/') ? substr($path, 2) : $path);
            $page = Page::published()->where('slug', $slug)
                ->with(['sections' => fn ($query) => $query->where('is_visible', true)->orderBy('sort_order')])->first();
        }
        if (! $page && ! $room && ! $offer && $path !== '') {
            return ['status' => 404, 'html' => '<main class="mx-auto max-w-3xl p-8"><h1>Page not found</h1><a href="/">Return to the hotel website</a></main>', 'graph' => []];
        }

        $title = $page?->title ?? $room?->title ?? $offer?->title ?? $hotel['name'];
        $summary = $page?->excerpt ?? $room?->summary ?? $offer?->summary ?? $settings['website']['seo_default_description'];
        $body = $page?->body ?? $room?->description ?? $offer?->body;
        $sections = $page?->sections->map(fn ($section) => [
            'title' => $section->title, 'subtitle' => $section->subtitle,
            'body' => HtmlSanitizer::clean($section->body), 'items' => is_array($section->data['items'] ?? null) ? $section->data['items'] : [],
        ])->all() ?? [];
        $rooms = in_array($path, ['', 'rooms'], true)
            ? RoomContent::published()->orderBy('sort_order')->get(['slug', 'title', 'summary'])->toArray() : [];
        $offers = $path === 'offers'
            ? Offer::published()->orderBy('sort_order')->get(['slug', 'title', 'summary'])->toArray() : [];
        $html = view('public-discovery', [
            'hotel' => $hotel, 'booking' => $booking, 'title' => $title, 'summary' => $summary,
            'body' => HtmlSanitizer::clean($body), 'sections' => $sections,
            'rooms' => $rooms, 'offers' => $offers, 'amenities' => $room?->amenities ?? [],
        ])->render();

        $address = array_filter([
            '@type' => 'PostalAddress', 'streetAddress' => $hotel['address'],
            'addressLocality' => $hotel['city'], 'addressRegion' => $hotel['state'], 'addressCountry' => $hotel['country'],
        ], fn ($value) => $value !== null && $value !== '');
        $identity = array_filter([
            '@type' => 'Hotel', '@id' => $base.'/#hotel', 'name' => $hotel['name'],
            'url' => $base.'/', 'logo' => $base.'/logo.webp', 'address' => $address,
            'telephone' => $hotel['phone'], 'email' => $hotel['email'],
            'currenciesAccepted' => $hotel['currency'], 'checkinTime' => $booking['check_in_time'], 'checkoutTime' => $booking['check_out_time'],
        ], fn ($value) => $value !== null && $value !== '');
        $social = array_values(array_filter(array_column($settings['website']['social_links'] ?? [], 'url'),
            fn ($value) => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) && in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true)));
        if ($social !== []) {
            $identity['sameAs'] = $social;
        }
        $canonical = $page?->canonical_url ?: $base.($path === '' ? '/' : '/'.$path);
        $graph = [
            $identity,
            ['@type' => 'WebSite', '@id' => $base.'/#website', 'url' => $base.'/', 'name' => $hotel['name'], 'publisher' => ['@id' => $base.'/#hotel']],
            ['@type' => 'WebPage', '@id' => $canonical.'#webpage', 'url' => $canonical, 'name' => $title,
                'description' => trim(strip_tags((string) $summary)), 'isPartOf' => ['@id' => $base.'/#website'], 'about' => ['@id' => $base.'/#hotel']],
        ];

        return ['status' => 200, 'html' => $html, 'graph' => $page?->noindex ? [] : $graph];
    }
}
