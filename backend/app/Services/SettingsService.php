<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Ordinary (non-secret) system configuration stored in the `settings` table.
 * Secrets such as SMTP passwords and payment keys stay in .env only.
 */
class SettingsService
{
    private const CACHE_KEY = 'settings.all';

    /**
     * Definitions: group => key => [default, public?]. Defaults intentionally avoid
     * inventing street address, phone, logo, rates or facilities.
     */
    public static function definitions(): array
    {
        return [
            'hotel' => [
                'name' => ['Mai Chitta Hotel', true],
                'address' => [null, true],
                'city' => ['Zaria', true],
                'state' => ['Kaduna State', true],
                'country' => ['Nigeria', true],
                'timezone' => ['Africa/Lagos', false],
                'currency' => ['NGN', true],
                'phone' => [null, true],
                'email' => [null, true],
                'logo_path' => [null, true],
            ],
            'website' => [
                'tagline' => [null, true],
                'hero_title' => ['Welcome to Mai Chitta Hotel', true],
                'hero_subtitle' => ['Warm hospitality in Zaria, Kaduna State, Nigeria.', true],
                'hero_cta_label' => ['Explore Rooms', true],
                'hero_cta_url' => ['/rooms', true],
                'hero_media_id' => [null, true],
                'seo_default_title' => ['Mai Chitta Hotel — Zaria, Kaduna State, Nigeria', true],
                'seo_default_description' => ['Official website of Mai Chitta Hotel in Zaria, Kaduna State, Nigeria.', true],
                'seo_og_image_id' => [null, true],
                'footer_notice' => [null, true],
                'social_links' => [[], true],
                'show_featured_rooms' => [true, true],
                'show_featured_offers' => [true, true],
                'show_gallery_preview' => [true, true],
                'show_testimonials' => [true, true],
                'maintenance_mode' => [false, true],
                'show_booking_widget' => [false, true],
            ],
            'booking' => [
                'online_booking_enabled' => [false, true],
                'check_in_time' => ['14:00', true],
                'check_out_time' => ['12:00', true],
                'hold_ttl_minutes' => [15, true],
                'max_rooms_per_booking' => [5, true],
                'max_stay_nights' => [30, true],
                'tax_percentage' => ['0.00', true],
                'service_charge_percentage' => ['0.00', true],
                'default_cancellation_policy' => [null, true],
                'online_payment_provider' => ['none', true], // none | paystack | flutterwave
                'require_payment_for_online_booking' => [false, true],
                'block_dirty_room_checkin' => [true, false],
                'require_inspected_room_for_checkin' => [false, false],
            ],
            'operations' => [
                'auto_create_checkout_cleaning_task' => [true, false],
                'folio_adjustment_approval_threshold_minor' => [500000, false], // 5,000.00 NGN default
                'require_settled_folio_for_checkout' => [false, false],
                'pos_stock_consumption_trigger' => ['submitted', false], // submitted | preparing | served
                'pos_allow_negative_stock_with_override' => [false, false],
                'pos_default_tax_percentage' => ['0.00', false],
                'pos_default_service_charge_percentage' => ['0.00', false],
                'room_service_charge_enabled' => [true, false],
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function all(bool $publicOnly = false): array
    {
        $stored = Cache::remember(self::CACHE_KEY, 3600, fn () => Setting::all()
            ->mapWithKeys(fn (Setting $s) => ["{$s->group}.{$s->key}" => $s->value])->all());

        $out = [];
        foreach (self::definitions() as $group => $keys) {
            foreach ($keys as $key => [$default, $public]) {
                if ($publicOnly && ! $public) {
                    continue;
                }
                $out[$group][$key] = array_key_exists("$group.$key", $stored) ? $stored["$group.$key"] : $default;
            }
        }

        return $out;
    }

    /**
     * Persist changes atomically. Returns map of "group.key" => [old, new] for changed values.
     */
    public function update(array $data, User $actor): array
    {
        $current = $this->all();
        $defs = self::definitions();
        $changes = [];

        DB::transaction(function () use ($data, $actor, $current, $defs, &$changes) {
            foreach ($data as $group => $values) {
                foreach ((array) $values as $key => $value) {
                    if (! isset($defs[$group][$key])) {
                        continue;
                    }
                    if (($current[$group][$key] ?? null) === $value) {
                        continue;
                    }
                    Setting::updateOrCreate(
                        ['group' => $group, 'key' => $key],
                        ['value' => $value, 'is_public' => $defs[$group][$key][1], 'updated_by' => $actor->id],
                    );
                    $changes["$group.$key"] = ['from' => $current[$group][$key] ?? null, 'to' => $value];
                }
            }
        });

        Cache::forget(self::CACHE_KEY);
        CmsService::bumpPublicCache();

        return $changes;
    }

    /** Read-only status of integrations configured via environment (no secrets returned). */
    public function integrationStatus(): array
    {
        $mailer = config('mail.default');

        return [
            'mail' => [
                'driver' => $mailer,
                'configured' => ! in_array($mailer, ['log', 'array'], true) && filled(config("mail.mailers.$mailer.host") ?? true),
                'from_address' => config('mail.from.address'),
            ],
            'queue' => ['driver' => config('queue.default')],
            'database' => ['driver' => config('database.default')],
            'payments' => [
                'paystack_configured' => filled(config('services.paystack.secret_key')) && filled(config('services.paystack.public_key')),
                'flutterwave_configured' => filled(config('services.flutterwave.secret_key')) && filled(config('services.flutterwave.public_key')),
            ],
            'app' => ['environment' => app()->environment(), 'debug' => (bool) config('app.debug'), 'timezone' => config('app.timezone')],
        ];
    }
}
