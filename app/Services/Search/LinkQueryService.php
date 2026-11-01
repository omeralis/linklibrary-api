<?php

namespace App\Services\Search;

use App\Models\Category;
use App\Models\Collection;
use App\Models\SavedLink;
use App\Models\User;
use App\Services\Links\TagService;
use App\Services\Sharing\AccessGraphService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * All listing, filtering, searching and discovery queries. Each entry point fixes its own visibility
 * boundary first (own links / valid grants / publicly eligible) and only then applies filters, so no
 * filter combination can widen what a caller may see. Private notes are matched only in own searches.
 */
class LinkQueryService
{
    public function __construct(private readonly AccessGraphService $graph) {}

    /** @param array<string, mixed> $filters */
    public function ownLinks(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = SavedLink::query()->withPresentation()->where('saved_links.user_id', $user->id);

        match ($filters['trashed'] ?? null) {
            'only' => $query->onlyTrashed(),
            'with' => $query->withTrashed(),
            default => null,
        };

        if (array_key_exists('favorite', $filters)) {
            $query->where('is_favorite', filter_var($filters['favorite'], FILTER_VALIDATE_BOOLEAN));
        }
        if (isset($filters['visibility'])) {
            $query->where('visibility', $filters['visibility']);
        }
        if (isset($filters['security_status'])) {
            $query->whereHas('link', fn (Builder $q) => $q->where('security_status', $filters['security_status']));
        }
        if (isset($filters['collection'])) {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('collection_items')
                ->join('collections', 'collections.id', '=', 'collection_items.collection_id')
                ->whereColumn('collection_items.saved_link_id', 'saved_links.id')
                ->where('collections.public_id', $filters['collection'])
                ->where('collections.user_id', $user->id));
        }

        $this->applyCommonFilters($query, $filters);
        $ranked = $this->applySearch($query, $filters['q'] ?? null, includePrivate: true);
        $this->applySort($query, $filters['sort'] ?? ($ranked ? 'relevance' : 'latest'), $ranked);

        return $query->paginate($perPage);
    }

    /** @param array<string, mixed> $filters */
    public function sharedWithMe(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        $ids = $this->graph->accessibleSavedLinkIds($user);

        $query = SavedLink::query()->withPresentation()
            ->whereIn('saved_links.id', $ids ?: [0])
            ->where('saved_links.user_id', '!=', $user->id)
            ->whereHas('user', fn (Builder $q) => $q->where('status', 'ACTIVE'));

        $this->applyCommonFilters($query, $filters);
        $ranked = $this->applySearch($query, $filters['q'] ?? null, includePrivate: false);
        $this->applySort($query, $filters['sort'] ?? ($ranked ? 'relevance' : 'latest'), $ranked);

        return $query->paginate($perPage);
    }

    /** @param array<string, mixed> $filters */
    public function publicLinks(array $filters, int $perPage, ?User $owner = null): LengthAwarePaginator
    {
        $query = SavedLink::query()->withPresentation()->publiclyEligible();
        if ($owner !== null) {
            $query->where('saved_links.user_id', $owner->id);
        }
        if (isset($filters['language'])) {
            $query->whereHas('link', fn (Builder $q) => $q->where('language', $filters['language']));
        }

        $this->applyCommonFilters($query, $filters);
        $ranked = $this->applySearch($query, $filters['q'] ?? null, includePrivate: false);
        $sort = $filters['sort'] ?? ($ranked ? 'relevance' : 'latest');
        $this->applySort($query, $sort === 'latest' ? 'published' : $sort, $ranked);

        return $query->paginate($perPage);
    }

    /**
     * Combined search for an authenticated user.
     *
     * @param  array<string, mixed>  $filters
     */
    public function search(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        $scope = $filters['scope'] ?? 'own';

        if ($scope === 'shared') {
            return $this->sharedWithMe($user, $filters, $perPage);
        }
        if ($scope === 'own') {
            return $this->ownLinks($user, $filters, $perPage);
        }

        // "all": own links (incl. notes) + links shared with me (public fields only).
        $sharedIds = $this->graph->accessibleSavedLinkIds($user);
        $parsed = $this->parse($filters['q'] ?? null);

        $query = SavedLink::query()->withPresentation()->where(function (Builder $q) use ($user, $sharedIds, $parsed) {
            $q->where(function (Builder $own) use ($user, $parsed) {
                $own->where('saved_links.user_id', $user->id);
                if ($parsed !== null) {
                    [$pub, $pubB] = $parsed->matchSql('saved_links.search_public');
                    [$priv, $privB] = $parsed->matchSql('saved_links.search_private');
                    $own->whereRaw("({$pub} OR {$priv})", [...$pubB, ...$privB]);
                }
            })->orWhere(function (Builder $shared) use ($user, $sharedIds, $parsed) {
                $shared->whereIn('saved_links.id', $sharedIds ?: [0])->where('saved_links.user_id', '!=', $user->id);
                if ($parsed !== null) {
                    [$pub, $pubB] = $parsed->matchSql('saved_links.search_public');
                    $shared->whereRaw($pub, $pubB);
                }
            });
        });

        $this->applyCommonFilters($query, $filters);
        if ($parsed !== null && ($filters['sort'] ?? 'relevance') === 'relevance') {
            [$rank, $rankB] = $parsed->rankSql('saved_links.search_public');
            $query->orderByRaw("{$rank} DESC", $rankB)->orderByDesc('saved_links.id');
        } else {
            $this->applySort($query, $filters['sort'] ?? 'latest');
        }

        return $query->paginate($perPage);
    }

    /** Own collections by name (simple, bounded ILIKE on a short column). */
    public function searchCollections(User $user, string $q, int $perPage): LengthAwarePaginator
    {
        $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr(trim($q), 0, 100)).'%';

        return Collection::query()->with('user')->withCount('items')
            ->where('user_id', $user->id)
            ->where(fn (Builder $b) => $b->where('name', 'ILIKE', $needle)->orWhere('description', 'ILIKE', $needle))
            ->orderBy('name')
            ->paginate($perPage);
    }

    /** @param array<string, mixed> $filters */
    private function applyCommonFilters(Builder $query, array $filters): void
    {
        if (isset($filters['category'])) {
            $category = Category::query()->where('slug', $filters['category'])->first();
            $ids = $category === null ? [0] : [$category->id, ...$category->children()->pluck('id')->all()];
            // Effective category = user's choice, else AI suggestion.
            $query->where(fn (Builder $q) => $q->whereIn('saved_links.category_id', $ids)
                ->orWhere(fn (Builder $q2) => $q2->whereNull('saved_links.category_id')->whereIn('saved_links.ai_category_id', $ids)));
        }

        $tags = array_values(array_filter(array_merge((array) ($filters['tags'] ?? []), isset($filters['tag']) ? [(string) $filters['tag']] : [])));
        foreach (array_slice($tags, 0, 5) as $tag) {
            $slug = TagService::slug((string) $tag);
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('saved_link_tags')
                ->join('tags', 'tags.id', '=', 'saved_link_tags.tag_id')
                ->whereColumn('saved_link_tags.saved_link_id', 'saved_links.id')
                ->where('tags.slug', $slug));
        }

        if (isset($filters['source_type'])) {
            $type = $filters['source_type'];
            $query->where(fn (Builder $q) => $q->where('saved_links.custom_source_type', $type)
                ->orWhere(fn (Builder $q2) => $q2->whereNull('saved_links.custom_source_type')
                    ->whereHas('link', fn (Builder $l) => $l->where('source_type', $type))));
        }
    }

    private function applySearch(Builder $query, ?string $q, bool $includePrivate): bool
    {
        $parsed = $this->parse($q);
        if ($parsed === null) {
            return false;
        }

        [$pub, $pubB] = $parsed->matchSql('saved_links.search_public');
        if ($includePrivate) {
            [$priv, $privB] = $parsed->matchSql('saved_links.search_private');
            $query->whereRaw("({$pub} OR {$priv})", [...$pubB, ...$privB]);
        } else {
            $query->whereRaw($pub, $pubB);
        }

        [$rank, $rankB] = $parsed->rankSql('saved_links.search_public');
        $query->selectRaw('saved_links.*')->selectRaw("{$rank} AS search_rank", $rankB);

        return true;
    }

    private function applySort(Builder $query, string $sort, bool $hasRank = false): void
    {
        match ($sort) {
            'oldest' => $query->orderBy('saved_links.created_at')->orderBy('saved_links.id'),
            'title' => $query->orderByRaw('COALESCE(saved_links.custom_title, (SELECT title FROM links WHERE links.id = saved_links.link_id)) ASC NULLS LAST')->orderBy('saved_links.id'),
            'popular' => $query->orderByDesc('saved_links.popularity_score')->orderByDesc('saved_links.id'),
            'published' => $query->orderByDesc('saved_links.published_at')->orderByDesc('saved_links.id'),
            'relevance' => $hasRank
                ? $query->orderByDesc('search_rank')->orderByDesc('saved_links.id')
                : $query->orderByDesc('saved_links.created_at')->orderByDesc('saved_links.id'),
            default => $query->orderByDesc('saved_links.created_at')->orderByDesc('saved_links.id'),
        };
    }

    private function parse(?string $q): ?SearchQuery
    {
        if ($q === null || trim($q) === '') {
            return null;
        }
        $parsed = SearchQuery::parse($q, (int) config('linklibrary.search.max_terms', 12));
        if ($parsed === null) {
            throw ValidationException::withMessages(['q' => [__('api.validation.search_query')]]);
        }

        return $parsed;
    }
}
