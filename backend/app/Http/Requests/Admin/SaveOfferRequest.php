<?php

namespace App\Http\Requests\Admin;

use App\Models\Offer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        $offer = $this->route('offer');

        return $offer instanceof Offer
            ? $this->user()->can('website.edit')
            : $this->user()->can('website.create');
    }

    public function rules(): array
    {
        $offer = $this->route('offer');
        $creating = ! $offer instanceof Offer;

        return [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:180'],
            'slug' => [
                $creating ? 'required' : 'sometimes',
                'string',
                'max:120',
                'regex:/^[a-z0-9-]+$/',
                Rule::unique('offers', 'slug')->ignore($offer?->id),
            ],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:1000'],
            'body' => ['nullable', 'string', 'max:50000'],
            'badge_text' => ['nullable', 'string', 'max:64'],
            'terms_text' => ['nullable', 'string', 'max:2000'],
            'cta_label' => ['nullable', 'string', 'max:120'],
            'cta_url' => ['nullable', 'string', 'max:255'],
            'featured_media_id' => ['nullable', 'integer', 'exists:media_assets,id'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],
            'is_featured' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'seo_title' => ['nullable', 'string', 'max:160'],
            'seo_description' => ['nullable', 'string', 'max:320'],
        ];
    }
}
