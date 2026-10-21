<?php

namespace App\Http\Requests\Admin;

use App\Models\Testimonial;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveTestimonialRequest extends FormRequest
{
    public function authorize(): bool
    {
        $testimonial = $this->route('testimonial');

        return $testimonial instanceof Testimonial
            ? $this->user()->can('website.edit')
            : $this->user()->can('website.create');
    }

    public function rules(): array
    {
        $testimonial = $this->route('testimonial');
        $creating = ! $testimonial instanceof Testimonial;

        return [
            'guest_name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'guest_title' => ['nullable', 'string', 'max:160'],
            'content' => [$creating ? 'required' : 'sometimes', 'string', 'max:2000'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'source' => ['nullable', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],
            'is_featured' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
