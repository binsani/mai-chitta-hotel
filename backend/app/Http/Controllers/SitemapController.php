<?php

namespace App\Http\Controllers;

use App\Models\Offer;
use App\Models\Page;
use App\Models\RoomContent;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(): Response
    {
        $base = rtrim(config('app.url'), '/');
        $corePages = ['about', 'rooms', 'booking', 'facilities', 'restaurant', 'gallery', 'offers', 'events', 'contact', 'privacy-policy', 'terms-and-conditions', 'cancellation-policy'];
        $xml = new \DOMDocument('1.0', 'UTF-8');
        $root = $xml->createElementNS('http://www.sitemaps.org/schemas/sitemap/0.9', 'urlset');
        $xml->appendChild($root);
        $append = function (string $path, ?string $modified) use ($xml, $root, $base): void {
            $entry = $xml->createElement('url');
            $location = $xml->createElement('loc');
            $location->appendChild($xml->createTextNode($base.$path));
            $entry->appendChild($location);
            if ($modified) {
                $entry->appendChild($xml->createElement('lastmod', $modified));
            }
            $root->appendChild($entry);
        };

        foreach (Page::published()->where('noindex', false)->orderBy('id')->get(['slug', 'updated_at']) as $page) {
            $path = $page->slug === 'home' ? '/' : (in_array($page->slug, $corePages, true) ? '/'.$page->slug : '/p/'.$page->slug);
            $append($path, $page->updated_at?->toAtomString());
        }
        foreach (RoomContent::published()->orderBy('id')->get(['slug', 'updated_at']) as $room) {
            $append('/rooms/'.$room->slug, $room->updated_at?->toAtomString());
        }
        foreach (Offer::published()->orderBy('id')->get(['slug', 'updated_at']) as $offer) {
            $append('/offers/'.$offer->slug, $offer->updated_at?->toAtomString());
        }

        return response($xml->saveXML(), 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
