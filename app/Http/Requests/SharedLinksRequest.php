<?php

namespace App\Http\Requests;

use App\Enums\SourceType;
use Illuminate\Validation\Rule;

/** Filters supported by GET /shared-with-me (owner-only filters such as notes/visibility do not apply). */
class SharedLinksRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'min:'.config('linklibrary.search.min_query_length'), 'max:'.config('linklibrary.search.max_query_length')],
            'category' => ['sometimes', 'string', 'max:80'],
            'tags' => ['sometimes', 'array', 'max:5'],
            'tags.*' => ['string', 'max:60'],
            'tag' => ['sometimes', 'string', 'max:60'],
            'source_type' => ['sometimes', Rule::in(SourceType::values())],
            'sort' => ['sometimes', Rule::in(['latest', 'oldest', 'title', 'popular', 'relevance'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('linklibrary.pagination.max')],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ];
    }
}
