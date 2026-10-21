<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('settings.edit') || $this->user()->can('reservations.settings');
    }

    public function rules(): array
    {
        return [
            'hotel' => ['sometimes', 'array'],
            'hotel.name' => ['sometimes', 'required', 'string', 'max:120'],
            'hotel.address' => ['nullable', 'string', 'max:255'],
            'hotel.city' => ['nullable', 'string', 'max:80'],
            'hotel.state' => ['nullable', 'string', 'max:80'],
            'hotel.country' => ['nullable', 'string', 'max:80'],
            'hotel.timezone' => ['sometimes', 'required', 'timezone:all'],
            'hotel.currency' => ['sometimes', 'required', 'string', 'size:3', 'alpha', 'uppercase'],
            'hotel.phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+()\s-]+$/'],
            'hotel.email' => ['nullable', 'email', 'max:255'],
            'hotel.logo_path' => ['nullable', 'string', 'max:255'],
            'website' => ['sometimes', 'array'],
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
            'website.social_links' => ['sometimes', 'array'],
            'website.show_featured_rooms' => ['sometimes', 'boolean'],
            'website.show_featured_offers' => ['sometimes', 'boolean'],
            'website.show_gallery_preview' => ['sometimes', 'boolean'],
            'website.show_testimonials' => ['sometimes', 'boolean'],
            'website.maintenance_mode' => ['sometimes', 'boolean'],
            'website.show_booking_widget' => ['sometimes', 'boolean'],
            'booking' => ['sometimes', 'array'],
            'booking.online_booking_enabled' => ['sometimes', 'boolean'],
            'booking.check_in_time' => ['sometimes', 'date_format:H:i'],
            'booking.check_out_time' => ['sometimes', 'date_format:H:i'],
            'booking.hold_ttl_minutes' => ['sometimes', 'integer', 'min:5', 'max:60'],
            'booking.max_rooms_per_booking' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'booking.max_stay_nights' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'booking.tax_percentage' => ['sometimes', 'numeric', 'min:0', 'max:50'],
            'booking.service_charge_percentage' => ['sometimes', 'numeric', 'min:0', 'max:50'],
            'booking.default_cancellation_policy' => ['nullable', 'string', 'max:2000'],
            'booking.online_payment_provider' => ['sometimes', 'string', 'in:none,paystack,flutterwave'],
            'booking.require_payment_for_online_booking' => ['sometimes', 'boolean'],
            'booking.block_dirty_room_checkin' => ['sometimes', 'boolean'],
            'booking.require_inspected_room_for_checkin' => ['sometimes', 'boolean'],
            'operations' => ['sometimes', 'array'],
            'operations.auto_create_checkout_cleaning_task' => ['sometimes', 'boolean'],
            'operations.folio_adjustment_approval_threshold_minor' => ['sometimes', 'integer', 'min:0'],
            'operations.require_settled_folio_for_checkout' => ['sometimes', 'boolean'],
            'operations.pos_stock_consumption_trigger' => ['sometimes', 'string', 'in:submitted,preparing,served'],
            'operations.pos_allow_negative_stock_with_override' => ['sometimes', 'boolean'],
            'operations.pos_default_tax_percentage' => ['sometimes', 'numeric', 'min:0', 'max:50'],
            'operations.pos_default_service_charge_percentage' => ['sometimes', 'numeric', 'min:0', 'max:50'],
            'operations.room_service_charge_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
