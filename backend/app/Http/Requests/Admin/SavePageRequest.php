<?php

namespace App\Http\Requests\Admin;

use App\Models\Page;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $page = $this->route('page');

        return $page instanceof Page
            ? $this->user()->can('update', $page)
            : $this->user()->can('create', Page::class);
    }

    public function rules(): array
    {
        $page = $this->route('page');
        $creating = ! $page instanceof Page;

        return [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:180'],
            'slug' => [
                $creating ? 'required' : 'sometimes',
                'string',
                'max:120',
                'regex:/^[a-z0-9-]+$/',
                Rule::unique('pages', 'slug')->ignore($page?->id),
            ],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'excerpt' => ['nullable', 'string', 'max:1000'],
            'body' => ['nullable', 'string', 'max:100000'],
            'template' => ['sometimes', 'string', Rule::in([
                'standard', 'home', 'about', 'rooms', 'booking', 'facilities',
                'restaurant', 'gallery', 'offers', 'events', 'contact', 'legal',
            ])],
            'status' => ['sometimes', 'string', Rule::in(['draft', 'published', 'archived'])],
            'featured_media_id' => ['nullable', 'integer', 'exists:media_assets,id'],
            'seo_title' => ['nullable', 'string', 'max:160'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'canonical_url' => ['nullable', 'string', 'max:255'],
            'og_image_id' => ['nullable', 'integer', 'exists:media_assets,id'],
            'noindex' => ['sometimes', 'boolean'],
            'revision_note' => ['nullable', 'string', 'max:255'],
            'sections' => ['sometimes', 'array', 'max:30'],
            'sections.*.type' => ['required_with:sections', 'string', Rule::in([
                'hero', 'rich_text', 'feature_grid', 'image_text',
                'cta_banner', 'faq', 'notice', 'contact_block',
            ])],
            'sections.*.title' => ['nullable', 'string', 'max:180'],
            'sections.*.subtitle' => ['nullable', 'string', 'max:255'],
            'sections.*.body' => ['nullable', 'string', 'max:20000'],
            'sections.*.media_id' => ['nullable', 'integer', 'exists:media_assets,id'],
            'sections.*.cta_label' => ['nullable', 'string', 'max:120'],
            'sections.*.cta_url' => ['nullable', 'string', 'max:255'],
            'sections.*.data' => ['nullable', 'array'],
            'sections.*.is_visible' => ['sometimes', 'boolean'],
            'sections.*.sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
