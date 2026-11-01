<?php

namespace App\Http\Requests;

use App\Enums\SourceType;
use Illuminate\Validation\Rule;

class UpdateLinkRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'custom_title' => ['sometimes', 'nullable', 'string', 'max:300'],
            'custom_description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'custom_image_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'custom_source_type' => ['sometimes', 'nullable', Rule::in(SourceType::values())],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'category' => ['sometimes', 'nullable', 'string', Rule::exists('categories', 'slug')->where('is_active', true)],
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['string', 'min:1', 'max:50'],
        ];
    }
}
