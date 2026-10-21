<?php

namespace App\Services;

use App\Models\NavigationMenu;
use App\Models\Offer;
use App\Models\Page;
use App\Models\PageRevision;
use App\Models\RoomContent;
use App\Models\Testimonial;
use App\Models\User;
use App\Support\HtmlSanitizer;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CmsService
{
    public const PUBLIC_CACHE_VERSION_KEY = 'cms.public_cache_version';

    public function __construct(private AuditLogger $audit) {}

    public static function cacheVersion(): int
    {
        return (int) Cache::get(self::PUBLIC_CACHE_VERSION_KEY, 1);
    }

    public static function bumpPublicCache(): void
    {
        Cache::forever(self::PUBLIC_CACHE_VERSION_KEY, self::cacheVersion() + 1);
    }

    public function createPage(array $data, User $actor): Page
    {
        return DB::transaction(function () use ($data, $actor) {
            $status = $data['status'] ?? 'draft';
            if ($status === 'published' && ! $actor->hasPermission('website.publish')) {
                throw ValidationException::withMessages(['status' => 'You do not have permission to publish pages.']);
            }

            $attrs = $this->sanitizePageAttributes($data);
            $attrs['status'] = $status;
            $attrs['published_at'] = $status === 'published' ? now() : null;
            $attrs['created_by'] = $actor->id;
            $attrs['updated_by'] = $actor->id;

            $page = Page::create($attrs);

            if (isset($data['sections']) && is_array($data['sections'])) {
                $this->syncSections($page, $data['sections']);
            }

            $this->recordRevision($page->fresh('sections'), $actor, $data['revision_note'] ?? 'Initial version');
            $this->audit->log('cms.page.created', $page, ['slug' => $page->slug, 'status' => $page->status]);
            self::bumpPublicCache();

            return $page->fresh(['sections.media', 'featuredMedia', 'ogImage', 'revisions.author:id,name']);
        });
    }

    public function updatePage(Page $page, array $data, User $actor): Page
    {
        return DB::transaction(function () use ($page, $data, $actor) {
            $attrs = $this->sanitizePageAttributes($data);

            if (array_key_exists('status', $data) && $data['status'] !== $page->status) {
                if (! $actor->hasPermission('website.publish')) {
                    throw ValidationException::withMessages(['status' => 'You do not have permission to change publication status.']);
                }
                $attrs['status'] = $data['status'];
                if ($data['status'] === 'published' && ! $page->published_at) {
                    $attrs['published_at'] = now();
                }
            }

            if ($page->is_system && isset($attrs['slug']) && $attrs['slug'] !== $page->slug) {
                unset($attrs['slug']);
            }

            $attrs['updated_by'] = $actor->id;
            $page->fill($attrs)->save();

            if (array_key_exists('sections', $data) && is_array($data['sections'])) {
                $this->syncSections($page, $data['sections']);
            }

            $this->recordRevision($page->fresh('sections'), $actor, $data['revision_note'] ?? 'Updated content');
            $this->audit->log('cms.page.updated', $page, ['slug' => $page->slug, 'status' => $page->status]);
            self::bumpPublicCache();

            return $page->fresh(['sections.media', 'featuredMedia', 'ogImage', 'revisions.author:id,name']);
        });
    }

    public function setPageStatus(Page $page, string $status, User $actor, ?string $note = null): Page
    {
        return DB::transaction(function () use ($page, $status, $actor, $note) {
            $prev = $page->status;
            $page->status = $status;
            if ($status === 'published') {
                $page->published_at = now();
            }
            $page->updated_by = $actor->id;
            $page->save();

            $this->recordRevision($page->fresh('sections'), $actor, $note ?? ucfirst($status));
            $this->audit->log("cms.page.{$status}", $page, ['slug' => $page->slug, 'from' => $prev, 'to' => $status]);
            self::bumpPublicCache();

            return $page->fresh(['sections.media', 'featuredMedia', 'ogImage', 'revisions.author:id,name']);
        });
    }

    public function restoreRevision(Page $page, PageRevision $revision, User $actor): Page
    {
        if ($revision->page_id !== $page->id) {
            throw ValidationException::withMessages(['revision' => 'Revision does not belong to this page.']);
        }

        return DB::transaction(function () use ($page, $revision, $actor) {
            $snap = $revision->snapshot;
            $page->fill(Arr::only($snap, [
                'title', 'subtitle', 'excerpt', 'body', 'template',
                'featured_media_id', 'seo_title', 'seo_description',
                'canonical_url', 'og_image_id', 'noindex',
            ]));
            $page->body = HtmlSanitizer::clean($page->body);
            $page->updated_by = $actor->id;
            $page->save();

            if (isset($snap['sections']) && is_array($snap['sections'])) {
                $this->syncSections($page, $snap['sections']);
            }

            $this->recordRevision($page->fresh('sections'), $actor, "Restored from revision v{$revision->version}");
            $this->audit->log('cms.page.revision_restored', $page, ['restored_version' => $revision->version]);
            self::bumpPublicCache();

            return $page->fresh(['sections.media', 'featuredMedia', 'ogImage', 'revisions.author:id,name']);
        });
    }

    public function recordRevision(Page $page, ?User $actor, ?string $note = null): PageRevision
    {
        $nextVersion = ((int) $page->revisions()->max('version')) + 1;
        $snapshot = [
            'title' => $page->title,
            'subtitle' => $page->subtitle,
            'excerpt' => $page->excerpt,
            'body' => $page->body,
            'template' => $page->template,
            'status' => $page->status,
            'featured_media_id' => $page->featured_media_id,
            'seo_title' => $page->seo_title,
            'seo_description' => $page->seo_description,
            'canonical_url' => $page->canonical_url,
            'og_image_id' => $page->og_image_id,
            'noindex' => $page->noindex,
            'sections' => $page->sections->map(fn ($s) => [
                'type' => $s->type,
                'title' => $s->title,
                'subtitle' => $s->subtitle,
                'body' => $s->body,
                'media_id' => $s->media_id,
                'cta_label' => $s->cta_label,
                'cta_url' => $s->cta_url,
                'data' => $s->data,
                'is_visible' => $s->is_visible,
                'sort_order' => $s->sort_order,
            ])->values()->all(),
        ];

        return PageRevision::create([
            'page_id' => $page->id,
            'version' => $nextVersion,
            'note' => $note,
            'snapshot' => $snapshot,
            'created_by' => $actor?->id,
            'created_at' => now(),
        ]);
    }

    private function sanitizePageAttributes(array $data): array
    {
        $attrs = Arr::only($data, [
            'slug', 'title', 'subtitle', 'excerpt', 'body', 'template',
            'featured_media_id', 'seo_title', 'seo_description',
            'canonical_url', 'og_image_id', 'noindex',
        ]);
        if (array_key_exists('body', $attrs)) {
            $attrs['body'] = HtmlSanitizer::clean($attrs['body']);
        }
        if (array_key_exists('excerpt', $attrs) && is_string($attrs['excerpt'])) {
            $attrs['excerpt'] = strip_tags($attrs['excerpt']);
        }
        if (array_key_exists('canonical_url', $attrs) && filled($attrs['canonical_url'])) {
            if (! HtmlSanitizer::isSafeUrl($attrs['canonical_url'])) {
                $attrs['canonical_url'] = null;
            }
        }

        return $attrs;
    }

    private function syncSections(Page $page, array $sections): void
    {
        $page->sections()->delete();
        foreach (array_values($sections) as $index => $sec) {
            $ctaUrl = $sec['cta_url'] ?? null;
            if (is_string($ctaUrl) && $ctaUrl !== '' && ! HtmlSanitizer::isSafeUrl($ctaUrl)) {
                $ctaUrl = null;
            }
            $page->sections()->create([
                'type' => $sec['type'] ?? 'rich_text',
                'title' => isset($sec['title']) ? strip_tags((string) $sec['title']) : null,
                'subtitle' => isset($sec['subtitle']) ? strip_tags((string) $sec['subtitle']) : null,
                'body' => HtmlSanitizer::clean($sec['body'] ?? null),
                'media_id' => $sec['media_id'] ?? null,
                'cta_label' => isset($sec['cta_label']) ? strip_tags((string) $sec['cta_label']) : null,
                'cta_url' => $ctaUrl,
                'data' => is_array($sec['data'] ?? null) ? $this->sanitizeSectionData($sec['data']) : null,
                'is_visible' => (bool) ($sec['is_visible'] ?? true),
                'sort_order' => (int) ($sec['sort_order'] ?? $index),
            ]);
        }
    }

    private function sanitizeSectionData(array $data): array
    {
        $clean = [];
        foreach ($data as $k => $v) {
            if (is_string($v)) {
                $clean[$k] = strip_tags($v);
            } elseif (is_array($v)) {
                $clean[$k] = $this->sanitizeSectionData($v);
            } else {
                $clean[$k] = $v;
            }
        }

        return $clean;
    }

    public function syncMenu(NavigationMenu $menu, array $items, User $actor): NavigationMenu
    {
        return DB::transaction(function () use ($menu, $items, $actor) {
            $menu->items()->delete();
            foreach (array_values($items) as $index => $item) {
                $url = (string) ($item['url'] ?? '/');
                if (! HtmlSanitizer::isSafeUrl($url)) {
                    throw ValidationException::withMessages(['items' => "Unsafe URL [{$url}] in navigation item."]);
                }
                $menu->items()->create([
                    'label' => strip_tags((string) $item['label']),
                    'url' => $url,
                    'page_id' => $item['page_id'] ?? null,
                    'target' => in_array($item['target'] ?? '_self', ['_self', '_blank'], true) ? ($item['target'] ?? '_self') : '_self',
                    'is_visible' => (bool) ($item['is_visible'] ?? true),
                    'sort_order' => (int) ($item['sort_order'] ?? $index),
                ]);
            }
            $menu->update(['updated_by' => $actor->id]);
            $this->audit->log('cms.menu.updated', $menu, ['slug' => $menu->slug, 'items_count' => count($items)]);
            self::bumpPublicCache();

            return $menu->fresh('items');
        });
    }

    public function saveRoomContent(?RoomContent $room, array $data, User $actor): RoomContent
    {
        return DB::transaction(function () use ($room, $data, $actor) {
            $status = $data['status'] ?? ($room?->status ?? 'draft');
            if ($status === 'published' && ($room?->status !== 'published') && ! $actor->hasPermission('website.publish')) {
                throw ValidationException::withMessages(['status' => 'You do not have permission to publish room content.']);
            }
            $attrs = Arr::only($data, [
                'slug', 'title', 'subtitle', 'summary', 'description',
                'occupancy_label', 'bed_configuration', 'indicative_rate',
                'amenities', 'featured_media_id', 'is_featured', 'sort_order',
                'seo_title', 'seo_description',
            ]);
            if (array_key_exists('description', $attrs)) {
                $attrs['description'] = HtmlSanitizer::clean($attrs['description']);
            }
            if (array_key_exists('summary', $attrs) && is_string($attrs['summary'])) {
                $attrs['summary'] = strip_tags($attrs['summary']);
            }
            if (array_key_exists('amenities', $attrs) && is_array($attrs['amenities'])) {
                $attrs['amenities'] = array_values(array_filter(array_map(fn ($a) => trim(strip_tags((string) $a)), $attrs['amenities'])));
            }
            $attrs['status'] = $status;
            if ($status === 'published' && ! $room?->published_at) {
                $attrs['published_at'] = now();
            }
            $attrs['updated_by'] = $actor->id;

            if (! $room) {
                $attrs['created_by'] = $actor->id;
                $room = RoomContent::create($attrs);
                $this->audit->log('cms.room_content.created', $room, ['slug' => $room->slug, 'status' => $room->status]);
            } else {
                $room->fill($attrs)->save();
                $this->audit->log('cms.room_content.updated', $room, ['slug' => $room->slug, 'status' => $room->status]);
            }
            self::bumpPublicCache();

            return $room->fresh('featuredMedia');
        });
    }

    public function saveOffer(?Offer $offer, array $data, User $actor): Offer
    {
        return DB::transaction(function () use ($offer, $data, $actor) {
            $status = $data['status'] ?? ($offer?->status ?? 'draft');
            if ($status === 'published' && ($offer?->status !== 'published') && ! $actor->hasPermission('website.publish')) {
                throw ValidationException::withMessages(['status' => 'You do not have permission to publish offers.']);
            }
            $attrs = Arr::only($data, [
                'slug', 'title', 'subtitle', 'summary', 'body', 'badge_text', 'terms_text',
                'cta_label', 'cta_url', 'featured_media_id', 'starts_at', 'ends_at',
                'is_featured', 'sort_order', 'seo_title', 'seo_description',
            ]);
            if (array_key_exists('body', $attrs)) {
                $attrs['body'] = HtmlSanitizer::clean($attrs['body']);
            }
            if (array_key_exists('summary', $attrs) && is_string($attrs['summary'])) {
                $attrs['summary'] = strip_tags($attrs['summary']);
            }
            if (array_key_exists('cta_url', $attrs) && filled($attrs['cta_url']) && ! HtmlSanitizer::isSafeUrl($attrs['cta_url'])) {
                $attrs['cta_url'] = null;
            }
            $attrs['status'] = $status;
            if ($status === 'published' && ! $offer?->published_at) {
                $attrs['published_at'] = now();
            }
            $attrs['updated_by'] = $actor->id;

            if (! $offer) {
                $attrs['created_by'] = $actor->id;
                $offer = Offer::create($attrs);
                $this->audit->log('cms.offer.created', $offer, ['slug' => $offer->slug, 'status' => $offer->status]);
            } else {
                $offer->fill($attrs)->save();
                $this->audit->log('cms.offer.updated', $offer, ['slug' => $offer->slug, 'status' => $offer->status]);
            }
            self::bumpPublicCache();

            return $offer->fresh('featuredMedia');
        });
    }

    public function saveTestimonial(?Testimonial $testimonial, array $data, User $actor): Testimonial
    {
        return DB::transaction(function () use ($testimonial, $data, $actor) {
            $status = $data['status'] ?? ($testimonial?->status ?? 'draft');
            if ($status === 'published' && ($testimonial?->status !== 'published') && ! $actor->hasPermission('website.publish')) {
                throw ValidationException::withMessages(['status' => 'You do not have permission to publish testimonials.']);
            }
            $attrs = Arr::only($data, ['guest_name', 'guest_title', 'content', 'rating', 'source', 'is_featured', 'sort_order']);
            if (isset($attrs['guest_name'])) {
                $attrs['guest_name'] = strip_tags((string) $attrs['guest_name']);
            }
            if (isset($attrs['guest_title'])) {
                $attrs['guest_title'] = strip_tags((string) $attrs['guest_title']);
            }
            if (isset($attrs['content'])) {
                $attrs['content'] = strip_tags((string) $attrs['content']);
            }
            $attrs['status'] = $status;
            if ($status === 'published' && ! $testimonial?->published_at) {
                $attrs['published_at'] = now();
            }
            $attrs['updated_by'] = $actor->id;

            if (! $testimonial) {
                $attrs['created_by'] = $actor->id;
                $testimonial = Testimonial::create($attrs);
                $this->audit->log('cms.testimonial.created', $testimonial, ['status' => $testimonial->status]);
            } else {
                $testimonial->fill($attrs)->save();
                $this->audit->log('cms.testimonial.updated', $testimonial, ['status' => $testimonial->status]);
            }
            self::bumpPublicCache();

            return $testimonial;
        });
    }
}
