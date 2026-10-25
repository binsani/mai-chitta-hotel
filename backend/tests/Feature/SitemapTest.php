<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Page;
use App\Models\RoomContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SitemapTest extends TestCase
{
    use RefreshDatabase;

    public function test_sitemap_contains_only_published_indexable_pages_rooms_and_current_offers(): void
    {
        config(['app.url' => 'https://maichittahotel.com']);
        $this->freezeTime();
        foreach (['home', 'about', 'local-guide'] as $slug) {
            Page::create(['title' => $slug, 'slug' => $slug, 'status' => 'published', 'noindex' => false]);
        }
        Page::create(['title' => 'Hidden', 'slug' => 'hidden-page', 'status' => 'published', 'noindex' => true]);
        Page::create(['title' => 'Draft', 'slug' => 'draft-page', 'status' => 'draft']);
        RoomContent::create(['title' => 'Deluxe', 'slug' => 'deluxe', 'status' => 'published']);
        RoomContent::create(['title' => 'Draft room', 'slug' => 'draft-room', 'status' => 'draft']);
        Offer::create(['title' => 'Current', 'slug' => 'current-offer', 'status' => 'published']);
        Offer::create(['title' => 'Expired', 'slug' => 'expired-offer', 'status' => 'published', 'ends_at' => now()->subDay()]);
        Offer::create(['title' => 'Future', 'slug' => 'future-offer', 'status' => 'published', 'starts_at' => now()->addDay()]);

        $response = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $document = simplexml_load_string($response->getContent());
        $this->assertNotFalse($document);
        $locations = array_map('strval', $document->xpath('//*[local-name()="loc"]'));
        $this->assertSame(['https://maichittahotel.com/', 'https://maichittahotel.com/about', 'https://maichittahotel.com/p/local-guide', 'https://maichittahotel.com/rooms/deluxe', 'https://maichittahotel.com/offers/current-offer'], $locations);
        $this->assertCount(5, $document->xpath('//*[local-name()="lastmod"]'));
    }

    public function test_empty_sitemap_is_valid_xml_and_does_not_expose_staff_routes(): void
    {
        $response = $this->get('/sitemap.xml')->assertOk();
        $document = simplexml_load_string($response->getContent());
        $this->assertNotFalse($document);
        $this->assertCount(0, $document->xpath('//*[local-name()="url"]'));
    }
}
