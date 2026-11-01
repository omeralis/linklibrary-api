<?php

namespace App\Http\Requests;

use App\Enums\SourceType;
use Illuminate\Validation\Rule;

class SearchRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:'.config('linklibrary.search.min_query_length'), 'max:'.config('linklibrary.search.max_query_length')],
            'scope' => ['sometimes', Rule::in(['own', 'shared', 'all'])],
            'type' => ['sometimes', Rule::in(['links', 'collections'])],
            'category' => ['sometimes', 'string', 'max:80'],
            'tags' => ['sometimes', 'array', 'max:5'],
            'tags.*' => ['string', 'max:60'],
            'tag' => ['sometimes', 'string', 'max:60'],
            'source_type' => ['sometimes', Rule::in(SourceType::values())],
            'favorite' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', Rule::in(['relevance', 'latest', 'popular'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('linklibrary.pagination.max')],
            'page' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ];
    }
}
