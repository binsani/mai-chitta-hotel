<?php

namespace Database\Seeders;

use App\Models\MediaAsset;
use App\Models\NavigationMenu;
use App\Models\Page;
use App\Services\CmsService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Idempotent CMS seeder for Phase 2.
 * Seeds replaceable SVG placeholder illustrations, default header/footer menus,
 * and the 14 core public website pages using only verified Mai Chitta Hotel
 * information (Zaria, Kaduna State, Nigeria) without fabricating addresses,
 * phone numbers, room inventory, prices, facilities, or testimonials.
 */
class CmsContentSeeder extends Seeder
{
    public function run(CmsService $cms): void
    {
        DB::transaction(function () use ($cms) {
            $placeholders = $this->seedPlaceholders();
            $this->seedCorePages($cms, $placeholders);
            $this->seedMenus();
        });
    }

    /** @return array<string, MediaAsset> */
    private function seedPlaceholders(): array
    {
        $defs = [
            'exterior' => [
                'title' => 'Hotel Exterior Placeholder',
                'alt_text' => 'Architectural illustration placeholder for Mai Chitta Hotel in Zaria, Kaduna State',
                'caption' => 'Placeholder illustration — replace with official hotel exterior photography in the Media Library.',
                'category' => 'general',
                'in_gallery' => true,
                'bg1' => '#3f2810', 'bg2' => '#7a4f1e', 'accent' => '#e8d1a2',
                'label' => 'MAI CHITTA HOTEL · ZARIA',
                'sublabel' => 'Official Photography Placeholder — Exterior View',
            ],
            'rooms' => [
                'title' => 'Guest Room Placeholder',
                'alt_text' => 'Placeholder illustration representing guest accommodation at Mai Chitta Hotel',
                'caption' => 'Placeholder illustration — replace with verified guest room photography in the Media Library.',
                'category' => 'rooms',
                'in_gallery' => true,
                'bg1' => '#1e293b', 'bg2' => '#475569', 'accent' => '#f4e9d2',
                'label' => 'GUEST ACCOMMODATION',
                'sublabel' => 'Official Photography Placeholder — Guest Rooms',
            ],
            'dining' => [
                'title' => 'Restaurant & Dining Placeholder',
                'alt_text' => 'Placeholder illustration representing dining at Mai Chitta Hotel',
                'caption' => 'Placeholder illustration — replace with verified restaurant photography in the Media Library.',
                'category' => 'restaurant',
                'in_gallery' => true,
                'bg1' => '#422006', 'bg2' => '#854d0e', 'accent' => '#fef08a',
                'label' => 'DINING & HOSPITALITY',
                'sublabel' => 'Official Photography Placeholder — Restaurant',
            ],
            'events' => [
                'title' => 'Events & Gatherings Placeholder',
                'alt_text' => 'Placeholder illustration representing events and meetings at Mai Chitta Hotel',
                'caption' => 'Placeholder illustration — replace with verified event space photography in the Media Library.',
                'category' => 'events',
                'in_gallery' => true,
                'bg1' => '#172554', 'bg2' => '#1e40af', 'accent' => '#bfdbfe',
                'label' => 'MEETINGS & GATHERINGS',
                'sublabel' => 'Official Photography Placeholder — Events',
            ],
        ];

        $out = [];
        $order = 1;
        foreach ($defs as $key => $d) {
            $path = "placeholders/mai-chitta-{$key}.svg";
            $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 750" width="1200" height="750" role="img" aria-label="{$d['alt_text']}">
  <defs>
    <linearGradient id="g" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="{$d['bg1']}"/>
      <stop offset="100%" stop-color="{$d['bg2']}"/>
    </linearGradient>
  </defs>
  <rect width="1200" height="750" fill="url(#g)"/>
  <rect x="60" y="60" width="1080" height="630" rx="16" fill="none" stroke="{$d['accent']}" stroke-opacity="0.35" stroke-width="2"/>
  <circle cx="600" cy="300" r="64" fill="none" stroke="{$d['accent']}" stroke-opacity="0.5" stroke-width="2"/>
  <path d="M565 325 L600 265 L635 325 Z" fill="none" stroke="{$d['accent']}" stroke-width="3"/>
  <text x="600" y="430" text-anchor="middle" fill="#ffffff" font-family="Georgia, serif" font-size="32" letter-spacing="4">{$d['label']}</text>
  <text x="600" y="475" text-anchor="middle" fill="{$d['accent']}" font-family="sans-serif" font-size="18">{$d['sublabel']}</text>
</svg>
SVG;
            Storage::disk('public')->put($path, $svg);

            $out[$key] = MediaAsset::firstOrCreate(
                ['path' => $path, 'disk' => 'public'],
                [
                    'original_name' => "mai-chitta-{$key}.svg",
                    'filename' => "mai-chitta-{$key}.svg",
                    'mime_type' => 'image/svg+xml',
                    'extension' => 'svg',
                    'size_bytes' => strlen($svg),
                    'width' => 1200,
                    'height' => 750,
                    'alt_text' => $d['alt_text'],
                    'title' => $d['title'],
                    'caption' => $d['caption'],
                    'category' => $d['category'],
                    'is_public' => true,
                    'in_gallery' => $d['in_gallery'],
                    'sort_order' => $order++,
                ]
            );
        }

        return $out;
    }

    /** @param array<string, MediaAsset> $placeholders */
    private function seedCorePages(CmsService $cms, array $placeholders): void
    {
        $pages = [
            [
                'slug' => 'home',
                'title' => 'Welcome to Mai Chitta Hotel',
                'subtitle' => 'Hospitality in Zaria, Kaduna State, Nigeria',
                'template' => 'home',
                'excerpt' => 'Official website of Mai Chitta Hotel, located in Zaria, Kaduna State, Nigeria.',
                'body' => '<p>Welcome to <strong>Mai Chitta Hotel</strong> in Zaria, Kaduna State, Nigeria. Our team is dedicated to delivering attentive hospitality for business travellers, academic visitors, families, and guests visiting Zaria.</p>',
                'featured_media_id' => $placeholders['exterior']->id,
                'seo_title' => 'Mai Chitta Hotel — Zaria, Kaduna State, Nigeria',
                'seo_description' => 'Official website of Mai Chitta Hotel in Zaria, Kaduna State, Nigeria. Explore our accommodation, dining, events, and guest services.',
                'sections' => [
                    [
                        'type' => 'image_text',
                        'title' => 'A Welcoming Stay in Zaria',
                        'subtitle' => 'Kaduna State, Nigeria',
                        'body' => '<p>Situated in the historic city of Zaria, Mai Chitta Hotel provides a calm, well-managed setting for guests visiting the city. All information on this website is managed directly by hotel administration.</p>',
                        'media_id' => $placeholders['exterior']->id,
                        'cta_label' => 'Learn About Us',
                        'cta_url' => '/about',
                    ],
                    [
                        'type' => 'cta_banner',
                        'title' => 'Planning a Visit to Zaria?',
                        'subtitle' => 'Reach out to our team for reservation assistance or general inquiries.',
                        'cta_label' => 'Contact the Hotel',
                        'cta_url' => '/contact',
                    ],
                ],
            ],
            [
                'slug' => 'about',
                'title' => 'About Mai Chitta Hotel',
                'subtitle' => 'Located in Zaria, Kaduna State, Nigeria',
                'template' => 'about',
                'excerpt' => 'Learn about Mai Chitta Hotel and our commitment to welcoming guests in Zaria, Kaduna State.',
                'body' => '<p><strong>Mai Chitta Hotel</strong> serves guests in Zaria, Kaduna State, Nigeria. Whether you are visiting for academic engagements, business, family occasions, or transit through Kaduna State, our staff are committed to a comfortable and dependable stay.</p><p>Administrators can update this page at any time through the Website Management dashboard as official hotel milestones, photographs, and detailed background information are approved for publication.</p>',
                'featured_media_id' => $placeholders['exterior']->id,
                'seo_title' => 'About Us | Mai Chitta Hotel, Zaria',
                'seo_description' => 'About Mai Chitta Hotel in Zaria, Kaduna State, Nigeria.',
                'sections' => [
                    [
                        'type' => 'feature_grid',
                        'title' => 'Our Hospitality Principles',
                        'subtitle' => 'What guides our daily operations in Zaria',
                        'data' => [
                            'items' => [
                                ['title' => 'Attentive Service', 'description' => 'Courteous reception and responsive guest support throughout your stay.'],
                                ['title' => 'Local Warmth', 'description' => 'Rooted in the hospitality traditions of Zaria, Kaduna State.'],
                                ['title' => 'Transparent Information', 'description' => 'Verified hotel policies, room details, and direct communication with our front desk.'],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'slug' => 'rooms',
                'title' => 'Rooms & Accommodation',
                'subtitle' => 'Guest accommodation at Mai Chitta Hotel, Zaria',
                'template' => 'rooms',
                'excerpt' => 'Browse published room categories and accommodation details at Mai Chitta Hotel.',
                'body' => '<p>Explore our published room categories below. Room descriptions, amenities, and rates are published directly by hotel management once verified.</p>',
                'featured_media_id' => $placeholders['rooms']->id,
                'seo_title' => 'Rooms & Accommodation | Mai Chitta Hotel, Zaria',
                'seo_description' => 'View guest room categories and accommodation options at Mai Chitta Hotel in Zaria, Kaduna State.',
                'sections' => [],
            ],
            [
                'slug' => 'booking',
                'title' => 'Book Your Stay',
                'subtitle' => 'Reservation inquiries and booking information',
                'template' => 'booking',
                'excerpt' => 'Reservation entry point and check-in/check-out schedule for Mai Chitta Hotel.',
                'body' => '<p>Online self-service reservation and instant room availability checking will be activated in the Reservations phase. In the meantime, please contact our reservation team directly or submit an inquiry through our contact form and a staff member will assist you promptly.</p>',
                'featured_media_id' => $placeholders['rooms']->id,
                'seo_title' => 'Reservations & Booking | Mai Chitta Hotel, Zaria',
                'seo_description' => 'Reservation information and check-in/check-out times at Mai Chitta Hotel in Zaria, Kaduna State.',
                'sections' => [],
            ],
            [
                'slug' => 'facilities',
                'title' => 'Hotel Facilities & Guest Services',
                'subtitle' => 'Amenities and services at Mai Chitta Hotel',
                'template' => 'facilities',
                'excerpt' => 'Verified guest facilities and services at Mai Chitta Hotel in Zaria.',
                'body' => '<p>Details of on-site facilities and guest services at <strong>Mai Chitta Hotel</strong> are published here as they are verified by hotel management. For specific facility inquiries prior to arrival, please contact our front desk team.</p>',
                'featured_media_id' => $placeholders['exterior']->id,
                'seo_title' => 'Facilities & Services | Mai Chitta Hotel, Zaria',
                'seo_description' => 'Guest facilities and services at Mai Chitta Hotel in Zaria, Kaduna State.',
                'sections' => [
                    [
                        'type' => 'notice',
                        'title' => 'Facility Information Notice',
                        'body' => '<p>To ensure accuracy, only verified hotel amenities approved by management are listed on this page. Contact us directly if you have specific accessibility or service requirements.</p>',
                    ],
                ],
            ],
            [
                'slug' => 'restaurant',
                'title' => 'Restaurant & Dining',
                'subtitle' => 'Dining and refreshments at Mai Chitta Hotel',
                'template' => 'restaurant',
                'excerpt' => 'Information on dining, meals, and room service at Mai Chitta Hotel in Zaria.',
                'body' => '<p>Our dining team prepares meals and refreshments for resident guests and visitors at <strong>Mai Chitta Hotel</strong>. Operating hours, curated menus, and room-service details are managed through the CMS and updated by our hospitality team.</p>',
                'featured_media_id' => $placeholders['dining']->id,
                'seo_title' => 'Restaurant & Dining | Mai Chitta Hotel, Zaria',
                'seo_description' => 'Dining and restaurant information at Mai Chitta Hotel in Zaria, Kaduna State.',
                'sections' => [],
            ],
            [
                'slug' => 'gallery',
                'title' => 'Photo Gallery',
                'subtitle' => 'Visual tour of Mai Chitta Hotel',
                'template' => 'gallery',
                'excerpt' => 'Browse photographs and illustrations from Mai Chitta Hotel in Zaria, Kaduna State.',
                'body' => '<p>Browse images from our media library below. Placeholder illustrations are clearly labelled until official hotel photography is uploaded by administration.</p>',
                'featured_media_id' => $placeholders['exterior']->id,
                'seo_title' => 'Gallery | Mai Chitta Hotel, Zaria',
                'seo_description' => 'Photo gallery of Mai Chitta Hotel in Zaria, Kaduna State, Nigeria.',
                'sections' => [],
            ],
            [
                'slug' => 'offers',
                'title' => 'Special Offers & Packages',
                'subtitle' => 'Seasonal promotions and packages at Mai Chitta Hotel',
                'template' => 'offers',
                'excerpt' => 'View current published offers and packages from Mai Chitta Hotel.',
                'body' => '<p>All active offers and promotional packages approved by management appear below.</p>',
                'featured_media_id' => $placeholders['rooms']->id,
                'seo_title' => 'Offers & Packages | Mai Chitta Hotel, Zaria',
                'seo_description' => 'Current special offers and stay packages at Mai Chitta Hotel in Zaria, Kaduna State.',
                'sections' => [],
            ],
            [
                'slug' => 'events',
                'title' => 'Meetings & Events',
                'subtitle' => 'Host your meetings, workshops, and gatherings in Zaria',
                'template' => 'events',
                'excerpt' => 'Inquire about hosting meetings, academic workshops, or private events at Mai Chitta Hotel.',
                'body' => '<p>Planning a business meeting, workshop, or private gathering in Zaria? Send our team your event requirements, preferred dates, and expected attendance, and we will advise on suitable arrangements.</p>',
                'featured_media_id' => $placeholders['events']->id,
                'seo_title' => 'Meetings & Events | Mai Chitta Hotel, Zaria',
                'seo_description' => 'Meetings, workshops, and event inquiries at Mai Chitta Hotel in Zaria, Kaduna State.',
                'sections' => [
                    [
                        'type' => 'cta_banner',
                        'title' => 'Request an Event Proposal',
                        'subtitle' => 'Share your event date and guest count with our team.',
                        'cta_label' => 'Send Event Inquiry',
                        'cta_url' => '/contact',
                    ],
                ],
            ],
            [
                'slug' => 'contact',
                'title' => 'Contact Us',
                'subtitle' => 'Get in touch with Mai Chitta Hotel in Zaria, Kaduna State',
                'template' => 'contact',
                'excerpt' => 'Contact details and online inquiry form for Mai Chitta Hotel in Zaria, Kaduna State, Nigeria.',
                'body' => '<p>Have a question about reservations, events, or guest services? Use the form below to send a message directly to our team, or reach us using the official contact details configured by hotel administration.</p>',
                'featured_media_id' => $placeholders['exterior']->id,
                'seo_title' => 'Contact Us | Mai Chitta Hotel, Zaria',
                'seo_description' => 'Contact Mai Chitta Hotel in Zaria, Kaduna State, Nigeria.',
                'sections' => [],
            ],
            [
                'slug' => 'privacy-policy',
                'title' => 'Privacy Policy',
                'subtitle' => 'How Mai Chitta Hotel handles guest and website visitor information',
                'template' => 'legal',
                'excerpt' => 'Privacy practices for guests and visitors of Mai Chitta Hotel.',
                'body' => '<h2>1. Overview</h2><p>Mai Chitta Hotel (Zaria, Kaduna State, Nigeria) respects your privacy and is committed to protecting personal information shared with us through our website or during your stay.</p><h2>2. Information We Collect</h2><p>When you submit a contact inquiry or reservation request, we collect only the details necessary to respond to your request, such as your name, email address, phone number, and message content.</p><h2>3. Data Protection & Retention</h2><p>Personal information is accessed only by authorized hotel personnel for legitimate operational and guest-service purposes and is never sold to third parties.</p>',
                'featured_media_id' => null,
                'seo_title' => 'Privacy Policy | Mai Chitta Hotel',
                'seo_description' => 'Privacy Policy of Mai Chitta Hotel in Zaria, Kaduna State, Nigeria.',
                'sections' => [],
            ],
            [
                'slug' => 'terms-and-conditions',
                'title' => 'Terms and Conditions',
                'subtitle' => 'Website and guest stay terms for Mai Chitta Hotel',
                'template' => 'legal',
                'excerpt' => 'General terms and conditions governing the use of the Mai Chitta Hotel website and services.',
                'body' => '<h2>1. Use of Website</h2><p>The content on this website is provided for general information regarding Mai Chitta Hotel in Zaria, Kaduna State, Nigeria.</p><h2>2. Reservations & Identification</h2><p>All reservations are subject to availability and confirmation by hotel management. Guests are required to present valid identification upon check-in in accordance with Nigerian hospitality regulations.</p><h2>3. Currency</h2><p>Unless stated otherwise, all rates and charges at Mai Chitta Hotel are denominated in Nigerian Naira (NGN).</p>',
                'featured_media_id' => null,
                'seo_title' => 'Terms and Conditions | Mai Chitta Hotel',
                'seo_description' => 'Terms and Conditions for Mai Chitta Hotel in Zaria, Kaduna State, Nigeria.',
                'sections' => [],
            ],
            [
                'slug' => 'cancellation-policy',
                'title' => 'Cancellation Policy',
                'subtitle' => 'Reservation modification and cancellation guidelines',
                'template' => 'legal',
                'excerpt' => 'Guidelines for modifying or cancelling reservations at Mai Chitta Hotel.',
                'body' => '<h2>1. Reservation Changes & Cancellations</h2><p>If you need to modify or cancel a reservation at Mai Chitta Hotel, please notify our front desk or reservations team as early as possible.</p><h2>2. Specific Rate Terms</h2><p>Specific deposit, refund, and notice-period terms applicable to your booking will be communicated at the time of reservation confirmation.</p>',
                'featured_media_id' => null,
                'seo_title' => 'Cancellation Policy | Mai Chitta Hotel',
                'seo_description' => 'Reservation cancellation policy for Mai Chitta Hotel in Zaria, Kaduna State, Nigeria.',
                'sections' => [],
            ],
        ];

        foreach ($pages as $def) {
            $existing = Page::where('slug', $def['slug'])->first();
            if ($existing) {
                continue;
            }
            $sections = $def['sections'] ?? [];
            unset($def['sections']);

            $page = Page::create([
                ...$def,
                'status' => 'published',
                'is_system' => true,
                'published_at' => now(),
            ]);

            foreach ($sections as $idx => $sec) {
                $page->sections()->create([
                    'type' => $sec['type'],
                    'title' => $sec['title'] ?? null,
                    'subtitle' => $sec['subtitle'] ?? null,
                    'body' => $sec['body'] ?? null,
                    'media_id' => $sec['media_id'] ?? null,
                    'cta_label' => $sec['cta_label'] ?? null,
                    'cta_url' => $sec['cta_url'] ?? null,
                    'data' => $sec['data'] ?? null,
                    'is_visible' => true,
                    'sort_order' => $idx,
                ]);
            }

            $cms->recordRevision($page->fresh('sections'), null, 'Initial seeded page');
        }
    }

    private function seedMenus(): void
    {
        $header = NavigationMenu::firstOrCreate(
            ['slug' => 'header'],
            ['name' => 'Primary Header Navigation', 'description' => 'Main navigation bar across the top of the public website.']
        );

        if ($header->items()->count() === 0) {
            $headerLinks = [
                ['label' => 'Home', 'url' => '/'],
                ['label' => 'About', 'url' => '/about'],
                ['label' => 'Rooms', 'url' => '/rooms'],
                ['label' => 'Facilities', 'url' => '/facilities'],
                ['label' => 'Restaurant', 'url' => '/restaurant'],
                ['label' => 'Gallery', 'url' => '/gallery'],
                ['label' => 'Offers', 'url' => '/offers'],
                ['label' => 'Events', 'url' => '/events'],
                ['label' => 'Contact', 'url' => '/contact'],
            ];
            foreach ($headerLinks as $idx => $link) {
                $header->items()->create([
                    'label' => $link['label'],
                    'url' => $link['url'],
                    'target' => '_self',
                    'is_visible' => true,
                    'sort_order' => $idx,
                ]);
            }
        }

        $footer = NavigationMenu::firstOrCreate(
            ['slug' => 'footer'],
            ['name' => 'Footer Navigation', 'description' => 'Quick links and legal policy links in the website footer.']
        );

        if ($footer->items()->count() === 0) {
            $footerLinks = [
                ['label' => 'About Us', 'url' => '/about'],
                ['label' => 'Rooms', 'url' => '/rooms'],
                ['label' => 'Book a Stay', 'url' => '/booking'],
                ['label' => 'Contact Us', 'url' => '/contact'],
                ['label' => 'Privacy Policy', 'url' => '/privacy-policy'],
                ['label' => 'Terms & Conditions', 'url' => '/terms-and-conditions'],
                ['label' => 'Cancellation Policy', 'url' => '/cancellation-policy'],
            ];
            foreach ($footerLinks as $idx => $link) {
                $footer->items()->create([
                    'label' => $link['label'],
                    'url' => $link['url'],
                    'target' => '_self',
                    'is_visible' => true,
                    'sort_order' => $idx,
                ]);
            }
        }
    }
}
