<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\RoomContent;
use App\Models\Setting;
use App\Models\User;
use App\Services\CmsService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://maichittahotel.com']);
    }

    private function home(array $attributes = []): Page
    {
        return Page::create([...['slug' => 'home', 'title' => 'Mai Chitta Hotel in Zaria', 'body' => '<p>Official hotel accommodation in Zaria.</p>', 'status' => 'published'], ...$attributes]);
    }

    private function graph(string $html): array
    {
        preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $matches);
        $this->assertArrayHasKey(1, $matches);

        return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR)['@graph'];
    }

    public function test_public_html_contains_readable_published_content_and_truthful_hotel_identity(): void
    {
        $page = $this->home();
        $page->sections()->create(['type' => 'faq', 'title' => 'Guest questions', 'is_visible' => true, 'data' => ['items' => [['question' => 'Where is the hotel?', 'answer' => 'Zaria, Kaduna State, Nigeria.']]]]);
        $response = $this->get('/')->assertOk()->assertSee('Official hotel accommodation in Zaria.')->assertSee('Where is the hotel?')->assertSee('Official hotel details')->assertSee('14:00');
        $graph = $this->graph($response->getContent());

        $this->assertSame('Hotel', $graph[0]['@type']);
        $this->assertSame('https://maichittahotel.com/#hotel', $graph[0]['@id']);
        $this->assertSame('Zaria', $graph[0]['address']['addressLocality']);
        $this->assertArrayNotHasKey('telephone', $graph[0]);
        $this->assertArrayNotHasKey('streetAddress', $graph[0]['address']);
        $this->assertArrayNotHasKey('starRating', $graph[0]);
        $this->assertArrayNotHasKey('aggregateRating', $graph[0]);
        $this->assertSame('WebSite', $graph[1]['@type']);
        $this->assertSame('https://maichittahotel.com/', $graph[2]['url']);
    }

    public function test_schema_and_initial_html_escape_untrusted_content(): void
    {
        $this->home(['title' => '</script><script>alert("title")</script>', 'body' => '<p>Safe guest information</p><script>alert("body")</script><a href="javascript:alert(1)">Unsafe link</a>']);
        $response = $this->get('/')->assertOk()->assertSee('Safe guest information')->assertDontSee('<script>alert', false)->assertDontSee('href="javascript:', false);
        $graph = $this->graph($response->getContent());

        $this->assertSame('</script><script>alert("title")</script>', $graph[2]['name']);
    }

    public function test_missing_and_draft_content_return_404_without_leaking_content(): void
    {
        Page::create(['title' => 'Draft confidential page', 'slug' => 'draft', 'body' => 'Unpublished secret', 'status' => 'draft']);
        $this->get('/p/draft')->assertNotFound()->assertDontSee('Unpublished secret')->assertDontSee('application/ld+json')->assertSee('noindex,nofollow');
        $this->get('/unknown-public-url')->assertNotFound();
        $this->get('/rooms/unpublished-room')->assertNotFound();
    }

    public function test_staff_pages_and_tokenized_booking_urls_do_not_emit_public_schema(): void
    {
        $this->home();
        $this->get('/admin/login')->assertOk()->assertDontSee('application/ld+json')->assertDontSee('Official hotel accommodation in Zaria.')->assertSee('noindex,nofollow');
        $this->get('/?token=guest-secret')->assertOk()->assertDontSee('application/ld+json')->assertDontSee('guest-secret')->assertSee('noindex,nofollow')->assertHeader('X-Robots-Tag', 'noindex,nofollow');
    }

    public function test_noindex_page_remains_readable_but_has_no_structured_data(): void
    {
        Page::create(['slug' => 'noindex-page', 'title' => 'Unlisted published information', 'status' => 'published', 'noindex' => true]);
        $this->get('/p/noindex-page')->assertOk()->assertSee('Unlisted published information')->assertSee('noindex,nofollow')->assertDontSee('application/ld+json');
    }

    public function test_room_listing_and_details_include_only_published_room_information(): void
    {
        Page::create(['slug' => 'rooms', 'title' => 'Rooms and Suites', 'status' => 'published']);
        RoomContent::create(['title' => 'Published Deluxe', 'slug' => 'deluxe', 'status' => 'published', 'description' => '<p>A published room description.</p>', 'amenities' => ['Work desk']]);
        RoomContent::create(['title' => 'Private Draft Room', 'slug' => 'secret', 'status' => 'draft']);
        $this->get('/rooms')->assertOk()->assertSee('Published Deluxe')->assertSee('/rooms/deluxe', false)->assertDontSee('Private Draft Room');
        $this->get('/rooms/deluxe')->assertOk()->assertSee('A published room description.')->assertSee('Work desk');
    }

    public function test_visible_content_and_schema_are_the_same_for_people_and_ai_search_bots(): void
    {
        $this->home();
        $normal = $this->get('/')->assertOk()->getContent();
        $bot = $this->withHeader('User-Agent', 'OAI-SearchBot/1.4')->get('/')->assertOk()->getContent();

        $this->assertSame($normal, $bot);
    }

    public function test_published_edits_and_contact_updates_invalidate_cached_discovery_content(): void
    {
        config(['cache.default' => 'database']);
        $page = $this->home();
        $this->get('/')->assertOk()->assertSee('Official hotel accommodation in Zaria.');
        $this->get('/')->assertOk()->assertSee('Official hotel accommodation in Zaria.');
        $actor = User::factory()->create();
        app(CmsService::class)->updatePage($page, ['body' => '<p>Updated official room information.</p>'], $actor);
        app(SettingsService::class)->update(['hotel' => ['phone' => '+234 800 000 0000']], $actor);
        $response = $this->get('/')->assertOk()->assertSee('Updated official room information.')->assertSee('+234 800 000 0000');

        $this->assertSame('+234 800 000 0000', $this->graph($response->getContent())[0]['telephone']);
    }

    public function test_maintenance_mode_hides_published_content_and_returns_503(): void
    {
        $this->home();
        Setting::create(['group' => 'website', 'key' => 'maintenance_mode', 'value' => true, 'is_public' => true]);
        $this->get('/')->assertStatus(503)->assertSee('scheduled updates')->assertDontSee('Official hotel accommodation in Zaria.')->assertDontSee('application/ld+json');
    }
}
