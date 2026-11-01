<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublicListRequest;
use App\Http\Requests\SearchRequest;
use App\Http\Resources\CollectionResource;
use App\Http\Resources\SavedLinkResource;
use App\Models\User;
use App\Services\Discovery\DiscoveryCache;
use App\Services\Search\LinkQueryService;
use App\Services\Sharing\LinkViewContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class SearchController extends Controller
{
    public function __construct(private readonly LinkQueryService $queries, private readonly DiscoveryCache $cache) {}

    /** Authenticated search: own links (incl. notes), links shared with me (public fields only), or both. */
    public function index(SearchRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $filters = $request->validated();

        if (($filters['type'] ?? 'links') === 'collections') {
            return ApiResponse::success(CollectionResource::collection($this->queries->searchCollections($user, $filters['q'], ApiResponse::perPage())));
        }

        $page = $this->queries->search($user, $filters, ApiResponse::perPage());

        return ApiResponse::paginated($page, fn ($savedLink) => SavedLinkResource::forRole(
            $savedLink,
            $savedLink->user_id === $user->id ? LinkViewContext::OWNER : LinkViewContext::VIEWER,
        ), ['scope' => $filters['scope'] ?? 'own']);
    }

    /** Public search over publicly eligible links only. */
    public function public(PublicListRequest $request): JsonResponse
    {
        $filters = $request->validated();
        if (empty($filters['q'])) {
            throw ValidationException::withMessages(['q' => [__('validation.required', ['attribute' => 'q'])]]);
        }
        $perPage = ApiResponse::perPage();

        $payload = $this->cache->remember('search:'.md5(json_encode([$filters, $perPage, app()->getLocale()]) ?: ''), function () use ($filters, $perPage) {
            return ApiResponse::paginated($this->queries->publicLinks($filters, $perPage), fn ($s) => SavedLinkResource::forRole($s, LinkViewContext::PUBLIC))->getData(true);
        });

        return response()->json($payload);
    }
}
