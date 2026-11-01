<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use App\Services\Links\TagService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The caller's own tags (with usage counts), optionally filtered by prefix for autocomplete. */
class TagController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:50']]);
        $userId = $request->user()->id;

        $query = Tag::query()
            ->select('tags.id', 'tags.name', 'tags.slug')
            ->selectRaw('COUNT(saved_link_tags.saved_link_id) AS links_count')
            ->join('saved_link_tags', 'saved_link_tags.tag_id', '=', 'tags.id')
            ->join('saved_links', 'saved_links.id', '=', 'saved_link_tags.saved_link_id')
            ->where('saved_links.user_id', $userId)
            ->whereNull('saved_links.deleted_at')
            ->groupBy('tags.id', 'tags.name', 'tags.slug')
            ->orderByDesc('links_count')->orderBy('tags.slug')
            ->limit(100);

        if (! empty($data['q'])) {
            $prefix = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], TagService::slug($data['q']));
            $query->where('tags.slug', 'like', $prefix.'%');
        }

        return ApiResponse::success(TagResource::collection($query->get())->collection);
    }
}
