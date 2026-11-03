<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountStatus;
use App\Enums\Visibility;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\PublicListRequest;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\CollectionResource;
use App\Http\Resources\PublicUserResource;
use App\Http\Resources\SavedLinkResource;
use App\Http\Resources\TagResource;
use App\Models\Category;
use App\Models\Collection;
use App\Models\SavedLink;
use App\Models\Tag;
use App\Models\User;
use App\Services\Discovery\DiscoveryCache;
use App\Services\Discovery\ViewRecorder;
use App\Services\Links\TagService;
use App\Services\Search\LinkQueryService;
use App\Services\Security\SecurityPolicy;
use App\Services\Sharing\LinkViewContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Anonymous discovery. Every query here is bounded by SavedLink::publiclyEligible() (PUBLIC + CLEAR and
 * unexpired + not deleted + active owner). Responses contain only public fields and are cached under
 * a global discovery version that is bumped on any visibility/security change.
 */
class PublicController extends Controller
{
    public function __construct(
        private readonly LinkQueryService $queries,
        private readonly DiscoveryCache $cache,
        private readonly ViewRecorder $views,
    ) {}

    public function links(PublicListRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = ApiResponse::perPage();

        return $this->cached('links', [$filters, $perPage], fn () => ApiResponse::paginated(
            $this->queries->publicLinks($filters, $perPage),
            fn ($s) => SavedLinkResource::forRole($s, LinkViewContext::PUBLIC),
        ));
    }

    public function link(Request $request, string $publicId): JsonResponse
    {
        if (! SavedLink::isValidPublicId($publicId)) {
            throw ApiException::notFound();
        }
        $savedLink = SavedLink::query()->withPresentation()->publiclyEligible()->where('public_id', $publicId)->first()
            ?? throw ApiException::notFound();

        $this->views->record($savedLink, $request);

        return ApiResponse::success(SavedLinkResource::forRole($savedLink, LinkViewContext::PUBLIC));
    }

    /** Feature flags the frontend needs (no secrets): temporary security-scanning / sharing policy. */
    public function features(SecurityPolicy $policy): JsonResponse
    {
        return ApiResponse::success([
            'security_scanning' => $policy->scanningComingSoon() ? 'COMING_SOON' : 'AVAILABLE',
            'unverified_sharing' => $policy->unverifiedSharingEnabled(),
        ]);
    }

    public function categories(): JsonResponse
    {
        return $this->cached('categories', [], function () {
            $counts = SavedLink::query()->publiclyEligible()
                ->selectRaw('COALESCE(category_id, ai_category_id) AS cid, COUNT(*) AS c')
                ->groupByRaw('COALESCE(category_id, ai_category_id)')
                ->pluck('c', 'cid');

            $categories = Category::query()->with('parent')->where('is_active', true)->orderBy('sort_order')->orderBy('slug')->get()
                ->each(fn (Category $c) => $c->setAttribute('links_count', (int) ($counts[$c->id] ?? 0)));

            return ApiResponse::success(CategoryResource::collection($categories)->collection);
        });
    }

    /** Only tags attached to publicly eligible links (private tagging never leaks). */
    public function tags(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:50']]);

        return $this->cached('tags', [$data], function () use ($data) {
            $eligible = SavedLink::query()->publiclyEligible()->select('saved_links.id');
            $query = Tag::query()
                ->select('tags.id', 'tags.name', 'tags.slug')
                ->selectRaw('COUNT(*) AS links_count')
                ->join('saved_link_tags', 'saved_link_tags.tag_id', '=', 'tags.id')
                ->whereIn('saved_link_tags.saved_link_id', $eligible)
                ->groupBy('tags.id', 'tags.name', 'tags.slug')
                ->orderByDesc('links_count')->orderBy('tags.slug')
                ->limit(50);
            if (! empty($data['q'])) {
                $prefix = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], TagService::slug($data['q']));
                $query->where('tags.slug', 'like', $prefix.'%');
            }

            return ApiResponse::success(TagResource::collection($query->get())->collection);
        });
    }

    public function user(string $username): JsonResponse
    {
        $user = $this->publicUser($username);

        return $this->cached('user', [$user->id], function () use ($user) {
            $links = SavedLink::query()->publiclyEligible()->where('saved_links.user_id', $user->id)->count();
            $collections = Collection::query()->where('user_id', $user->id)->where('visibility', Visibility::PUBLIC->value)->count();

            return ApiResponse::success((new PublicUserResource($user))->resolve() + [
                'stats' => ['public_links' => $links, 'public_collections' => $collections],
            ]);
        });
    }

    public function userLinks(PublicListRequest $request, string $username): JsonResponse
    {
        $user = $this->publicUser($username);
        $filters = $request->validated();
        $perPage = ApiResponse::perPage();

        return $this->cached('user-links', [$user->id, $filters, $perPage], fn () => ApiResponse::paginated(
            $this->queries->publicLinks($filters, $perPage, $user),
            fn ($s) => SavedLinkResource::forRole($s, LinkViewContext::PUBLIC),
        ));
    }

    public function userCollections(Request $request, string $username): JsonResponse
    {
        $user = $this->publicUser($username);
        $perPage = ApiResponse::perPage();
        $page = max(1, (int) $request->integer('page', 1));

        return $this->cached('user-collections', [$user->id, $perPage, $page], function () use ($user, $perPage) {
            $eligible = SavedLink::query()->publiclyEligible()->select('saved_links.id');
            $collections = Collection::query()->with('user')
                ->where('user_id', $user->id)
                ->where('visibility', Visibility::PUBLIC->value)
                ->withCount(['items' => fn ($q) => $q->whereIn('saved_link_id', $eligible)])
                ->orderBy('sort_order')->orderByDesc('id')
                ->paginate($perPage);

            return ApiResponse::paginated($collections, fn ($c) => (new CollectionResource($c))->asPublic());
        });
    }

    /**
     * A public collection lists ONLY publicly eligible links; private members are silently excluded
     * (membership never makes a link public).
     */
    public function collection(Request $request, string $publicId): JsonResponse
    {
        if (! Collection::isValidPublicId($publicId)) {
            throw ApiException::notFound();
        }
        $collection = Collection::query()->with('user')
            ->where('public_id', $publicId)
            ->where('visibility', Visibility::PUBLIC->value)
            ->whereHas('user', fn ($q) => $q->where('status', AccountStatus::ACTIVE->value))
            ->first() ?? throw ApiException::notFound();

        $perPage = ApiResponse::perPage();
        $pageNo = max(1, (int) $request->integer('page', 1));

        return $this->cached('collection', [$collection->id, $perPage, $pageNo], function () use ($collection, $perPage) {
            $page = SavedLink::query()->withPresentation()->publiclyEligible()
                ->join('collection_items', 'collection_items.saved_link_id', '=', 'saved_links.id')
                ->where('collection_items.collection_id', $collection->id)
                ->select('saved_links.*')
                ->orderBy('collection_items.position')->orderBy('collection_items.id')
                ->paginate($perPage);

            $collection->setAttribute('items_count', $page->total());

            return ApiResponse::paginated($page, fn ($s) => SavedLinkResource::forRole($s, LinkViewContext::PUBLIC), [
                'collection' => (new CollectionResource($collection))->asPublic()->resolve(),
            ]);
        });
    }

    private function publicUser(string $username): User
    {
        $username = User::normalizeUsername($username);
        if (preg_match('/^[a-z0-9_]{3,30}$/', $username) !== 1) {
            throw ApiException::notFound();
        }

        return User::query()
            ->where('username', $username)
            ->where('profile_public', true)
            ->where('status', AccountStatus::ACTIVE->value)
            ->first() ?? throw ApiException::notFound();
    }

    /**
     * @param  array<mixed>  $key
     * @param  callable(): JsonResponse  $build
     */
    private function cached(string $name, array $key, callable $build): JsonResponse
    {
        $payload = $this->cache->remember($name.':'.app()->getLocale().':'.md5((string) json_encode($key)), fn () => $build()->getData(true));

        return response()->json($payload)->setPublic()->setMaxAge(30);
    }
}
