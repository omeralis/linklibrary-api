<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CollectionRequest;
use App\Http\Resources\CollectionResource;
use App\Models\Collection;
use App\Models\User;
use App\Services\Links\CollectionService;
use App\Services\Links\LinkService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Owner-only collection management (public viewing is in PublicController). */
class CollectionController extends Controller
{
    public function __construct(private readonly CollectionService $collections, private readonly LinkService $links) {}

    public function index(Request $request): JsonResponse
    {
        $page = Collection::query()->with('user')->withCount('items')
            ->where('user_id', $request->user()->id)
            ->orderBy('sort_order')->orderByDesc('id')
            ->paginate(ApiResponse::perPage());

        return ApiResponse::success(CollectionResource::collection($page));
    }

    public function store(CollectionRequest $request): JsonResponse
    {
        $data = $this->validatedWithCover($request);
        $collection = $this->collections->create($request->user(), $data);

        return ApiResponse::created(new CollectionResource($collection->load('user')->loadCount('items')), 'api.collections.created');
    }

    public function show(Request $request, string $publicId): JsonResponse
    {
        $collection = $this->owned($request->user(), $publicId);
        $items = $collection->savedLinks()->withPresentation()->limit(CollectionService::MAX_ITEMS)->get();

        return ApiResponse::success((new CollectionResource($collection->loadCount('items')))->withItems($items));
    }

    public function update(CollectionRequest $request, string $publicId): JsonResponse
    {
        $collection = $this->owned($request->user(), $publicId);
        $collection = $this->collections->update($collection, $request->user(), $this->validatedWithCover($request));

        return ApiResponse::success(new CollectionResource($collection->loadCount('items')), 'api.collections.updated');
    }

    public function destroy(Request $request, string $publicId): JsonResponse
    {
        $this->collections->delete($this->owned($request->user(), $publicId));

        return ApiResponse::message('api.collections.deleted');
    }

    public function addLinks(Request $request, string $publicId): JsonResponse
    {
        $data = $request->validate([
            'links' => ['required', 'array', 'min:1', 'max:100'],
            'links.*' => ['string', 'uuid'],
        ]);
        $collection = $this->owned($request->user(), $publicId);
        $added = $this->collections->addLinks($collection, $request->user(), $data['links']);

        return ApiResponse::success(['added' => $added], 'api.collections.links_added');
    }

    public function removeLink(Request $request, string $publicId, string $savedLinkPublicId): JsonResponse
    {
        $collection = $this->owned($request->user(), $publicId);
        $this->collections->removeLink($collection, $request->user(), $savedLinkPublicId);

        return ApiResponse::message('api.collections.link_removed');
    }

    public function reorder(Request $request, string $publicId): JsonResponse
    {
        $data = $request->validate([
            'links' => ['required', 'array', 'min:1', 'max:'.CollectionService::MAX_ITEMS],
            'links.*' => ['string', 'uuid', 'distinct'],
        ]);
        $collection = $this->owned($request->user(), $publicId);
        $this->collections->reorder($collection, $request->user(), $data['links']);

        return ApiResponse::message('api.collections.reordered');
    }

    private function owned(User $user, string $publicId): Collection
    {
        if (! Collection::isValidPublicId($publicId)) {
            throw ApiException::notFound();
        }

        return Collection::query()->with('user')->where('public_id', $publicId)->where('user_id', $user->id)->first()
            ?? throw ApiException::notFound();
    }

    /** @return array<string, mixed> */
    private function validatedWithCover(CollectionRequest $request): array
    {
        $data = $request->validated();
        if (! empty($data['cover_image_url'])) {
            $data['cover_image_url'] = $this->links->validateUrl((string) $data['cover_image_url'], 'cover_image_url')->normalized;
        }

        return $data;
    }
}
