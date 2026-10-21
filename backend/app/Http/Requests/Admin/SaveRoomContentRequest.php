<?php

namespace App\Http\Requests\Admin;

use App\Models\RoomContent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveRoomContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $room = $this->route('room_content');

        return $room instanceof RoomContent
            ? $this->user()->can('website.edit')
            : $this->user()->can('website.create');
    }

    public function rules(): array
    {
        $room = $this->route('room_content');
        $creating = ! $room instanceof RoomContent;

        return [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:180'],
            'slug' => [
                $creating ? 'required' : 'sometimes',
                'string',
                'max:120',
                'regex:/^[a-z0-9-]+$/',
                Rule::unique('room_contents', 'slug')->ignore($room?->id),
            ],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:1000'],
            'description' => ['nullable', 'string', 'max:50000'],
            'occupancy_label' => ['nullable', 'string', 'max:120'],
            'bed_configuration' => ['nullable', 'string', 'max:120'],
            'indicative_rate' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'amenities' => ['nullable', 'array', 'max:30'],
            'amenities.*' => ['string', 'max:80'],
            'featured_media_id' => ['nullable', 'integer', 'exists:media_assets,id'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],
            'is_featured' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'seo_title' => ['nullable', 'string', 'max:160'],
            'seo_description' => ['nullable', 'string', 'max:320'],
        ];
    }
}
