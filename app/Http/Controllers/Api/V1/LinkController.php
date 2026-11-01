<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Visibility;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ImageUploadRequest;
use App\Http\Requests\ListLinksRequest;
use App\Http\Requests\StoreLinkRequest;
use App\Http\Requests\UpdateLinkRequest;
use App\Http\Resources\SavedLinkResource;
use App\Models\Collection;
use App\Models\User;
use App\Services\Discovery\ViewRecorder;
use App\Services\Links\CollectionService;
use App\Services\Links\LinkService;
use App\Services\Links\TagService;
use App\Services\Media\ImageStorage;
use App\Services\Search\LinkQueryService;
use App\Services\Search\SearchIndexer;
use App\Services\Security\SecurityPolicy;
use App\Services\Sharing\AccessGraphService;
use App\Services\Sharing\LinkAccessResolver;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LinkController extends Controller
{
    public function __construct(
        private readonly LinkService $links,
        private readonly LinkQueryService $queries,
        private readonly LinkAccessResolver $resolver,
        private readonly AccessGraphService $graph,
        private readonly TagService $tags,
        private readonly SearchIndexer $indexer,
        private readonly SecurityPolicy $policy,
        private readonly ImageStorage $images,
        private readonly CollectionService $collections,
        private readonly ViewRecorder $views,
    ) {}

    public function index(ListLinksRequest $request): JsonResponse
    {
        $page = $this->queries->ownLinks($request->user(), $request->validated(), ApiResponse::perPage());

        return ApiResponse::success(SavedLinkResource::collection($page));
    }

    public function store(StoreLinkRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        [$savedLink, $created] = $this->links->save($user, $data['url'], $data);

        if (! empty($data['collections'])) {
            foreach (Collection::query()->where('user_id', $user->id)->whereIn('public_id', $data['collections'])->get() as $collection) {
                $this->collections->addLinks($collection, $user, [$savedLink->public_id]);
            }
        }

        $savedLink = $this->resolver->resolveOwned($savedLink->public_id, $user);
        $resource = SavedLinkResource::forRole($savedLink, 'owner');

        return $created
            ? ApiResponse::created($resource, 'api.links.saved')
            : ApiResponse::success($resource, 'api.links.already_saved', 200, ['already_saved' => true]);
    }

    public function show(Request $request, string $publicId): JsonResponse
    {
        $context = $this->resolver->resolve($publicId, $request->user());
        if (! $context->isOwner()) {
            $this->views->record($context->savedLink, $request);
        }
        $canReshare = $context->isOwner() || ($request->user() !== null && $this->graph->canReshare($context->savedLink, $request->user()));

        return ApiResponse::success(SavedLinkResource::fromContext($context, $canReshare));
    }

    public function update(UpdateLinkRequest $request, string $publicId): JsonResponse
    {
        $savedLink = $this->resolver->resolveOwned($publicId, $request->user());
        $updated = $this->links->update($savedLink, $request->user(), $request->validated());

        return ApiResponse::success(SavedLinkResource::forRole($this->resolver->resolveOwned($updated->public_id, $request->user()), 'owner'), 'api.links.updated');
    }

    public function destroy(Request $request, string $publicId): JsonResponse
    {
        $request->validate(['permanent' => ['sometimes', 'boolean']]);
        $savedLink = $this->resolver->resolveOwned($publicId, $request->user(), withTrashed: $request->boolean('permanent'));
        if ($savedLink->custom_image_path !== null && $request->boolean('permanent')) {
            $this->images->delete($savedLink->custom_image_path);
        }
        $this->links->delete($savedLink, $request->user(), $request->boolean('permanent'));

        return ApiResponse::message('api.links.deleted');
    }

    public function restore(Request $request, string $publicId): JsonResponse
    {
        $savedLink = $this->resolver->resolveOwned($publicId, $request->user(), withTrashed: true);
        if (! $savedLink->trashed()) {
            throw ApiException::conflict('NOT_DELETED', 'api.links.not_deleted');
        }
        $this->links->restore($savedLink, $request->user());

        return ApiResponse::success(SavedLinkResource::forRole($this->resolver->resolveOwned($publicId, $request->user()), 'owner'), 'api.links.restored');
    }

    public function favorite(Request $request, string $publicId): JsonResponse
    {
        $request->validate(['favorite' => ['sometimes', 'boolean']]);
        $savedLink = $this->resolver->resolveOwned($publicId, $request->user());
        $value = $request->has('favorite') ? $request->boolean('favorite') : ! $savedLink->is_favorite;
        $this->links->setFavorite($savedLink, $value);

        return ApiResponse::success(['public_id' => $savedLink->public_id, 'is_favorite' => $value], 'api.links.updated');
    }

    public function visibility(Request $request, string $publicId): JsonResponse
    {
        $data = $request->validate(['visibility' => ['required', Rule::in(Visibility::values())]]);
        $savedLink = $this->resolver->resolveOwned($publicId, $request->user());
        $this->links->changeVisibility($savedLink, $request->user(), Visibility::from($data['visibility']));

        return ApiResponse::success(SavedLinkResource::forRole($this->resolver->resolveOwned($publicId, $request->user()), 'owner'), 'api.links.visibility_updated');
    }

    public function sharingSettings(Request $request, string $publicId): JsonResponse
    {
        $data = $request->validate(['allow_resharing' => ['required', 'boolean']]);
        $savedLink = $this->resolver->resolveOwned($publicId, $request->user());
        $this->links->changeSharingSettings($savedLink, $request->user(), (bool) $data['allow_resharing']);

        return ApiResponse::success(['public_id' => $savedLink->public_id, 'allow_resharing' => (bool) $data['allow_resharing']], 'api.links.updated');
    }

    public function addTags(Request $request, string $publicId): JsonResponse
    {
        $data = $request->validate(['tags' => ['required', 'array', 'min:1', 'max:20'], 'tags.*' => ['string', 'min:1', 'max:50']]);
        $savedLink = $this->resolver->resolveOwned($publicId, $request->user());
        $this->tags->addUserTags($savedLink, $data['tags']);
        $this->indexer->reindex($savedLink->fresh() ?? $savedLink);

        return ApiResponse::success(SavedLinkResource::forRole($this->resolver->resolveOwned($publicId, $request->user()), 'owner'), 'api.links.updated');
    }

    public function removeTag(Request $request, string $publicId, string $tagSlug): JsonResponse
    {
        $savedLink = $this->resolver->resolveOwned($publicId, $request->user());
        $this->tags->removeTags($savedLink, [$tagSlug]);
        $this->indexer->reindex($savedLink->fresh() ?? $savedLink);

        return ApiResponse::success(SavedLinkResource::forRole($this->resolver->resolveOwned($publicId, $request->user()), 'owner'), 'api.links.updated');
    }

    public function uploadCover(ImageUploadRequest $request, string $publicId): JsonResponse
    {
        $savedLink = $this->resolver->resolveOwned($publicId, $request->user());
        $path = $this->images->store($request->file('image'), 'covers');
        $this->images->delete($savedLink->custom_image_path);
        $savedLink->forceFill(['custom_image_path' => $path])->save();

        return ApiResponse::success(SavedLinkResource::forRole($this->resolver->resolveOwned($publicId, $request->user()), 'owner'), 'api.links.updated');
    }

    public function deleteCover(Request $request, string $publicId): JsonResponse
    {
        $savedLink = $this->resolver->resolveOwned($publicId, $request->user());
        $this->images->delete($savedLink->custom_image_path);
        $savedLink->forceFill(['custom_image_path' => null])->save();

        return ApiResponse::success(SavedLinkResource::forRole($this->resolver->resolveOwned($publicId, $request->user()), 'owner'), 'api.links.updated');
    }

    public function reanalyze(Request $request, string $publicId): JsonResponse
    {
        $savedLink = $this->resolver->resolveOwned($publicId, $request->user());
        if ($savedLink->link->security_status->isUnsafe()) {
            throw ApiException::unprocessable('LINK_BLOCKED', 'api.links.security_blocked');
        }
        $this->links->reanalyze($savedLink);

        return ApiResponse::success(SavedLinkResource::processing($savedLink->link->fresh() ?? $savedLink->link), 'api.links.reanalysis_queued', 202);
    }

    public function processingStatus(Request $request, string $publicId): JsonResponse
    {
        $savedLink = $this->resolver->resolveOwned($publicId, $request->user());

        return ApiResponse::success(SavedLinkResource::processing($savedLink->link));
    }

    /**
     * Platform-mediated opening: returns the destination only when the security policy allows it
     * for this caller (MALICIOUS links can never be opened through the platform).
     */
    public function open(Request $request, string $publicId): JsonResponse
    {
        $context = $this->resolver->resolve($publicId, $request->user());
        $link = $context->savedLink->link;
        if (! $this->policy->mayOpen($link, $context->isOwner())) {
            throw new ApiException('LINK_SECURITY_BLOCKED', 403, 'api.links.security_blocked');
        }

        return ApiResponse::success([
            'url' => $link->normalized_url,
            'security_status' => $link->effectiveSecurityStatus()->value,
            'warning' => $this->policy->requiresWarning($link),
        ]);
    }
}
