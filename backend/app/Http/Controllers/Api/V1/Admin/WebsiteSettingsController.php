<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactInquiry;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use App\Support\HtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class WebsiteSettingsController extends Controller
{
    public function __construct(
        private SettingsService $settings,
        private AuditLogger $audit,
    ) {}

    public function show(): JsonResponse
    {
        Gate::authorize('website.view');
        $all = $this->settings->all();

        return response()->json([
            'data' => [
                'website' => $all['website'],
                'hotel' => $all['hotel'],
                'booking' => $all['booking'],
            ],
            'inquiries_count' => ContactInquiry::where('status', 'new')->count(),
            'recent_inquiries' => ContactInquiry::latest('id')->limit(20)->get(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        Gate::authorize('website.settings');

        $data = $request->validate([
            'website' => ['required', 'array'],
            'website.tagline' => ['nullable', 'string', 'max:160'],
            'website.hero_title' => ['nullable', 'string', 'max:180'],
            'website.hero_subtitle' => ['nullable', 'string', 'max:255'],
            'website.hero_cta_label' => ['nullable', 'string', 'max:80'],
            'website.hero_cta_url' => ['nullable', 'string', 'max:255'],
            'website.hero_media_id' => ['nullable', 'integer', 'exists:media_assets,id'],
            'website.seo_default_title' => ['nullable', 'string', 'max:160'],
            'website.seo_default_description' => ['nullable', 'string', 'max:320'],
            'website.seo_og_image_id' => ['nullable', 'integer', 'exists:media_assets,id'],
            'website.footer_notice' => ['nullable', 'string', 'max:300'],
            'website.social_links' => ['sometimes', 'array', 'max:10'],
            'website.show_featured_rooms' => ['sometimes', 'boolean'],
            'website.show_featured_offers' => ['sometimes', 'boolean'],
            'website.show_gallery_preview' => ['sometimes', 'boolean'],
            'website.show_testimonials' => ['sometimes', 'boolean'],
            'website.maintenance_mode' => ['sometimes', 'boolean'],
            'website.show_booking_widget' => ['sometimes', 'boolean'],
        ]);

        if (! empty($data['website']['hero_cta_url']) && ! HtmlSanitizer::isSafeUrl($data['website']['hero_cta_url'])) {
            throw ValidationException::withMessages(['website.hero_cta_url' => 'Enter a valid relative path or http(s) URL.']);
        }

        // Strip HTML tags from text fields
        foreach (['tagline', 'hero_title', 'hero_subtitle', 'hero_cta_label', 'seo_default_title', 'seo_default_description', 'footer_notice'] as $key) {
            if (isset($data['website'][$key]) && is_string($data['website'][$key])) {
                $data['website'][$key] = strip_tags($data['website'][$key]);
            }
        }

        $changes = $this->settings->update(['website' => $data['website']], $request->user());
        if ($changes) {
            $this->audit->log('cms.website_settings.updated', null, ['changes' => $changes]);
        }

        return response()->json([
            'data' => $this->settings->all()['website'],
            'changed' => array_keys($changes),
        ]);
    }
}
