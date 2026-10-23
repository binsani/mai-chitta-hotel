<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\Page;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CmsContentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Phase2CmsTest extends TestCase
{
    use RefreshDatabase;

    public function test_cached_spa_metadata_refreshes_after_published_page_edits(): void
    {
        $page = Page::where('slug', 'about')->firstOrFail();
        $this->actingAs($this->userWithRole('super-admin'));
        $this->putJson('/api/v1/admin/pages/'.$page->id, ['seo_title' => 'Original Hotel Title'])->assertOk();
        $this->get('/about')->assertOk()->assertSee('<title>Original Hotel Title</title>', false);
        $this->get('/about')->assertOk()->assertSee('<title>Original Hotel Title</title>', false);
        $this->putJson('/api/v1/admin/pages/'.$page->id, ['seo_title' => 'Updated Hotel Title'])->assertOk();
        $this->get('/about')->assertOk()->assertSee('<title>Updated Hotel Title</title>', false)->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->seed([
            RolesAndPermissionsSeeder::class,
            CmsContentSeeder::class,
        ]);
        $this->withHeaders(['Referer' => 'http://localhost:8000', 'Origin' => 'http://localhost:8000']);
    }

    private function userWithRole(string $slug): User
    {
        $user = User::factory()->create(['password' => 'Secret-pass-123', 'is_active' => true]);
        $user->roles()->attach(Role::where('slug', $slug)->value('id'), ['created_at' => now()]);

        return $user;
    }

    public function test_seeded_core_public_pages_and_menus_are_accessible_without_fabricated_data(): void
    {
        $boot = $this->getJson('/api/v1/public/bootstrap')->assertOk();
        $boot->assertJsonPath('data.settings.hotel.name', 'Mai Chitta Hotel')
            ->assertJsonPath('data.settings.hotel.city', 'Zaria')
            ->assertJsonPath('data.settings.hotel.state', 'Kaduna State')
            ->assertJsonPath('data.settings.hotel.country', 'Nigeria')
            ->assertJsonPath('data.settings.hotel.phone', null)
            ->assertJsonPath('data.settings.hotel.address', null)
            ->assertJsonCount(0, 'data.testimonials')
            ->assertJsonCount(0, 'data.featured_rooms')
            ->assertJsonCount(0, 'data.featured_offers');

        $expectedSlugs = [
            'home', 'about', 'rooms', 'booking', 'facilities', 'restaurant',
            'gallery', 'offers', 'events', 'contact',
            'privacy-policy', 'terms-and-conditions', 'cancellation-policy',
        ];
        foreach ($expectedSlugs as $slug) {
            $this->getJson("/api/v1/pages/{$slug}")->assertOk()->assertJsonPath('data.slug', $slug);
        }
    }

    public function test_cms_permission_enforcement_across_roles(): void
    {
        // Receptionist has no website permissions
        $receptionist = $this->userWithRole('receptionist');
        $this->actingAs($receptionist)->getJson('/api/v1/admin/pages')->assertForbidden();
        $this->actingAs($receptionist)->postJson('/api/v1/admin/pages', [
            'title' => 'Unauthorized', 'slug' => 'unauth',
        ])->assertForbidden();

        // Hotel Manager can create & edit drafts, but cannot publish or delete
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $manager = $this->userWithRole('hotel-manager');
        $res = $this->actingAs($manager)->postJson('/api/v1/admin/pages', [
            'title' => 'Draft Guide', 'slug' => 'draft-guide', 'status' => 'draft',
        ])->assertCreated();
        $pageId = $res->json('data.id');

        $this->actingAs($manager)->postJson("/api/v1/admin/pages/{$pageId}/publish")->assertForbidden();
        $this->actingAs($manager)->deleteJson("/api/v1/admin/pages/{$pageId}")->assertForbidden();

        // General Manager can publish
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $gm = $this->userWithRole('general-manager');
        $this->actingAs($gm)->postJson("/api/v1/admin/pages/{$pageId}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');
    }

    public function test_draft_vs_published_visibility_and_live_cms_updates(): void
    {
        $admin = $this->userWithRole('super-admin');

        // Create a draft page, draft room content, draft offer, and draft testimonial
        $pageRes = $this->actingAs($admin)->postJson('/api/v1/admin/pages', [
            'title' => 'Conference Hall',
            'slug' => 'conference-hall',
            'status' => 'draft',
            'body' => '<p>Initial draft description.</p>',
            'sections' => [
                ['type' => 'cta_banner', 'title' => 'Book Hall', 'cta_label' => 'Inquire', 'cta_url' => '/contact', 'is_visible' => true],
            ],
        ])->assertCreated();
        $pageId = $pageRes->json('data.id');

        $roomRes = $this->actingAs($admin)->postJson('/api/v1/admin/room-contents', [
            'title' => 'Executive Room', 'slug' => 'executive-room', 'status' => 'draft',
        ])->assertCreated();
        $roomId = $roomRes->json('data.id');

        $offerRes = $this->actingAs($admin)->postJson('/api/v1/admin/offers', [
            'title' => 'Academic Visitor Package', 'slug' => 'academic-package', 'status' => 'draft',
        ])->assertCreated();
        $offerId = $offerRes->json('data.id');

        // Drafts must NOT be visible on public endpoints
        $this->getJson('/api/v1/pages/conference-hall')->assertNotFound();
        $this->getJson('/api/v1/rooms/executive-room')->assertNotFound();
        $this->getJson('/api/v1/offers/academic-package')->assertNotFound();

        // Publish them via admin API -> immediately visible on public endpoints
        $this->actingAs($admin)->postJson("/api/v1/admin/pages/{$pageId}/publish")->assertOk();
        $this->actingAs($admin)->putJson("/api/v1/admin/room-contents/{$roomId}", ['status' => 'published'])->assertOk();
        $this->actingAs($admin)->putJson("/api/v1/admin/offers/{$offerId}", ['status' => 'published'])->assertOk();

        $this->getJson('/api/v1/pages/conference-hall')
            ->assertOk()
            ->assertJsonPath('data.title', 'Conference Hall')
            ->assertJsonPath('data.sections.0.title', 'Book Hall');
        $this->getJson('/api/v1/rooms/executive-room')->assertOk()->assertJsonPath('data.title', 'Executive Room');
        $this->getJson('/api/v1/offers/academic-package')->assertOk()->assertJsonPath('data.title', 'Academic Visitor Package');

        // Unpublish page -> disappears from public endpoint again
        $this->actingAs($admin)->postJson("/api/v1/admin/pages/{$pageId}/unpublish")->assertOk();
        $this->getJson('/api/v1/pages/conference-hall')->assertNotFound();
    }

    public function test_revision_history_and_restoration(): void
    {
        $admin = $this->userWithRole('super-admin');
        $about = Page::where('slug', 'about')->firstOrFail();

        // Update page twice
        $this->actingAs($admin)->putJson("/api/v1/admin/pages/{$about->id}", [
            'title' => 'About Mai Chitta v2',
            'body' => '<p>Second version body.</p>',
            'revision_note' => 'Second revision',
        ])->assertOk();

        $this->actingAs($admin)->putJson("/api/v1/admin/pages/{$about->id}", [
            'title' => 'About Mai Chitta v3',
            'body' => '<p>Third version body.</p>',
            'revision_note' => 'Third revision',
        ])->assertOk();

        $revisions = $this->actingAs($admin)->getJson("/api/v1/admin/pages/{$about->id}/revisions")
            ->assertOk()
            ->json('data');
        $this->assertGreaterThanOrEqual(3, count($revisions));

        $v2 = collect($revisions)->firstWhere('version', 2);
        $this->assertNotNull($v2);

        // Restore v2
        $this->actingAs($admin)->postJson("/api/v1/admin/pages/{$about->id}/revisions/{$v2['id']}/restore")
            ->assertOk()
            ->assertJsonPath('data.title', 'About Mai Chitta v2');

        // Public website immediately reflects restored revision
        $this->getJson('/api/v1/pages/about')
            ->assertOk()
            ->assertJsonPath('data.title', 'About Mai Chitta v2');
    }

    public function test_xss_sanitization_on_pages_sections_and_offers(): void
    {
        $admin = $this->userWithRole('super-admin');

        $res = $this->actingAs($admin)->postJson('/api/v1/admin/pages', [
            'title' => 'XSS Check',
            'slug' => 'xss-check',
            'status' => 'published',
            'body' => '<p onclick="evil()">Safe paragraph</p><script>alert("xss")</script><iframe src="https://evil.test"></iframe><a href="javascript:alert(1)" target="_blank">Bad Link</a><a href="https://example.com" target="_blank">Good Link</a>',
            'sections' => [
                [
                    'type' => 'rich_text',
                    'title' => '<b>Clean Heading</b>',
                    'body' => '<img src=x onerror=alert(1)><p>Section text</p>',
                    'cta_label' => 'Click',
                    'cta_url' => 'javascript:evil()',
                ],
            ],
        ])->assertCreated();

        $body = $res->json('data.body');
        $this->assertStringContainsString('<p>Safe paragraph</p>', $body);
        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('<iframe', $body);
        $this->assertStringNotContainsString('onclick', $body);
        $this->assertStringNotContainsString('javascript:', $body);
        $this->assertStringContainsString('rel="noopener noreferrer"', $body);

        $sec = $res->json('data.sections.0');
        $this->assertSame('Clean Heading', $sec['title']);
        $this->assertStringNotContainsString('onerror', $sec['body']);
        $this->assertNull($sec['cta_url']);
    }

    public function test_media_upload_validation_and_private_file_access_control(): void
    {
        $admin = $this->userWithRole('super-admin');

        // Reject SVG/script upload
        $badFile = UploadedFile::fake()->create('xss.svg', 10, 'image/svg+xml');
        $this->actingAs($admin)->postJson('/api/v1/admin/media', [
            'file' => $badFile,
            'alt_text' => 'Bad SVG',
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        // Require alt_text for accessibility
        $goodImage = UploadedFile::fake()->image('lobby.jpg', 800, 600);
        $this->actingAs($admin)->postJson('/api/v1/admin/media', [
            'file' => $goodImage,
        ])->assertUnprocessable()->assertJsonValidationErrors('alt_text');

        // Upload a private PDF document
        $privatePdf = UploadedFile::fake()->create('internal-policy.pdf', 120, 'application/pdf');
        $uploadRes = $this->actingAs($admin)->postJson('/api/v1/admin/media', [
            'file' => $privatePdf,
            'alt_text' => 'Internal Policy Document',
            'is_public' => false,
            'in_gallery' => true, // must be forced false because it is private
        ])->assertCreated();

        $mediaId = $uploadRes->json('data.id');
        $asset = MediaAsset::findOrFail($mediaId);
        $this->assertSame('local', $asset->disk);
        $this->assertFalse($asset->is_public);
        $this->assertFalse($asset->in_gallery);

        // Authorized admin with media.view can download
        $this->actingAs($admin)->get("/api/v1/admin/media/{$mediaId}/download")->assertOk();

        // Receptionist (no media.view) is forbidden from downloading private media
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $receptionist = $this->userWithRole('receptionist');
        $this->actingAs($receptionist)->getJson("/api/v1/admin/media/{$mediaId}/download")->assertForbidden();

        // Unauthenticated visitor is rejected
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->getJson("/api/v1/admin/media/{$mediaId}/download")->assertUnauthorized();
    }

    public function test_menu_management_and_unsafe_url_rejection(): void
    {
        $admin = $this->userWithRole('super-admin');

        $this->actingAs($admin)->putJson('/api/v1/admin/menus/header', [
            'items' => [
                ['label' => 'Evil', 'url' => 'javascript:alert(1)'],
            ],
        ])->assertUnprocessable();

        $this->actingAs($admin)->putJson('/api/v1/admin/menus/header', [
            'items' => [
                ['label' => 'Home', 'url' => '/', 'is_visible' => true],
                ['label' => 'Rooms & Suites', 'url' => '/rooms', 'is_visible' => true],
                ['label' => 'Hidden Item', 'url' => '/events', 'is_visible' => false],
            ],
        ])->assertOk();

        // Public bootstrap only returns the 2 visible items
        $this->getJson('/api/v1/public/bootstrap')
            ->assertOk()
            ->assertJsonCount(2, 'data.menus.header')
            ->assertJsonPath('data.menus.header.1.label', 'Rooms & Suites');
    }

    public function test_contact_form_submission_and_website_settings_update(): void
    {
        $this->postJson('/api/v1/contact', [
            'name' => 'Amina Bello',
            'email' => 'amina@example.test',
            'phone' => '+234 800 111 2222',
            'subject' => 'Workshop Accommodation Inquiry',
            'message' => 'We would like to inquire about group accommodation for a university workshop in Zaria.',
        ])->assertCreated();

        $this->assertDatabaseHas('contact_inquiries', [
            'email' => 'amina@example.test',
            'subject' => 'Workshop Accommodation Inquiry',
        ]);

        $admin = $this->userWithRole('super-admin');
        $this->actingAs($admin)->putJson('/api/v1/admin/website-settings', [
            'website' => [
                'hero_title' => 'Mai Chitta Hotel · Zaria',
                'seo_default_title' => 'Mai Chitta Hotel — Official Site',
            ],
        ])->assertOk();

        $this->getJson('/api/v1/public/bootstrap')
            ->assertOk()
            ->assertJsonPath('data.settings.website.hero_title', 'Mai Chitta Hotel · Zaria');
    }

    public function test_spa_controller_serves_public_and_nested_admin_routes_with_seo_metadata(): void
    {
        // Public page receives index,follow and page-specific SEO title
        $this->get('/about')
            ->assertOk()
            ->assertSee('<title>About Us | Mai Chitta Hotel, Zaria</title>', false)
            ->assertSee('<meta name="robots" content="index,follow" />', false)
            ->assertSee('<link rel="canonical"', false);

        // Nested admin routes survive hard browser refreshes and receive noindex,nofollow
        foreach (['/admin', '/admin/login', '/admin/website/pages', '/admin/users'] as $adminPath) {
            $this->get($adminPath)
                ->assertOk()
                ->assertSee('<meta name="robots" content="noindex,nofollow" />', false)
                ->assertSee('/assets/index-', false);
        }
    }
}
