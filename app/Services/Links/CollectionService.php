<?php

namespace App\Services\Links;

use App\Enums\Visibility;
use App\Exceptions\ApiException;
use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\SavedLink;
use App\Models\User;
use App\Services\Audit\AuditAction;
use App\Services\Audit\AuditLogger;
use App\Services\Discovery\DiscoveryCache;
use Illuminate\Support\Facades\DB;

/**
 * Collections organise a user's own saved links. Membership never grants access, and a public
 * collection never exposes private links (filtering happens on read).
 */
class CollectionService
{
    public const MAX_ITEMS = 1000;

    public function __construct(private readonly AuditLogger $audit, private readonly DiscoveryCache $cache) {}

    /** @param array<string, mixed> $data */
    public function create(User $user, array $data): Collection
    {
        $this->assertVisibilityAllowed($user, $data['visibility'] ?? null);
        $collection = new Collection($data);
        $collection->user_id = $user->id;
        $collection->visibility = Visibility::from($data['visibility'] ?? Visibility::PRIVATE->value);
        $collection->sort_order = (int) ($data['sort_order'] ?? 0);
        $collection->save();

        if ($collection->isPublic()) {
            $this->cache->invalidate();
        }

        return $collection;
    }

    /** @param array<string, mixed> $data */
    public function update(Collection $collection, User $actor, array $data): Collection
    {
        $this->assertVisibilityAllowed($actor, $data['visibility'] ?? null);
        $before = $collection->visibility;
        $collection->fill($data)->save();

        if ($before !== $collection->visibility) {
            $this->audit->log(AuditAction::COLLECTION_VISIBILITY_CHANGED, $actor, 'collection', $collection->public_id, ['from' => $before->value, 'to' => $collection->visibility->value]);
        }
        $this->cache->invalidate();

        return $collection;
    }

    public function delete(Collection $collection): void
    {
        $collection->delete();
        $this->cache->invalidate();
    }

    /** @param list<string> $savedLinkPublicIds */
    public function addLinks(Collection $collection, User $user, array $savedLinkPublicIds): int
    {
        $ids = $this->ownedSavedLinkIds($user, $savedLinkPublicIds);

        return DB::transaction(function () use ($collection, $user, $ids) {
            Collection::query()->whereKey($collection->id)->lockForUpdate()->first();
            $count = CollectionItem::query()->where('collection_id', $collection->id)->count();
            $position = (int) CollectionItem::query()->where('collection_id', $collection->id)->max('position');
            $added = 0;
            foreach ($ids as $id) {
                if ($count + $added >= self::MAX_ITEMS) {
                    throw ApiException::unprocessable('COLLECTION_FULL', 'api.collections.full');
                }
                $inserted = DB::table('collection_items')->insertOrIgnore([
                    'collection_id' => $collection->id,
                    'saved_link_id' => $id,
                    'user_id' => $user->id,
                    'position' => ++$position,
                    'created_at' => now(),
                ]);
                $added += $inserted;
            }
            $this->cache->invalidate();

            return $added;
        });
    }

    public function removeLink(Collection $collection, User $user, string $savedLinkPublicId): void
    {
        $ids = $this->ownedSavedLinkIds($user, [$savedLinkPublicId], includeTrashed: true);
        $deleted = CollectionItem::query()->where('collection_id', $collection->id)->whereIn('saved_link_id', $ids)->delete();
        if ($deleted === 0) {
            throw ApiException::notFound();
        }
        $this->cache->invalidate();
    }

    /**
     * Assign positions following the given order; items not listed keep their relative order after.
     *
     * @param  list<string>  $orderedPublicIds
     */
    public function reorder(Collection $collection, User $user, array $orderedPublicIds): void
    {
        $map = SavedLink::withTrashed()->where('user_id', $user->id)->whereIn('public_id', $orderedPublicIds)->pluck('id', 'public_id');

        DB::transaction(function () use ($collection, $orderedPublicIds, $map) {
            $items = CollectionItem::query()->where('collection_id', $collection->id)->lockForUpdate()->orderBy('position')->orderBy('id')->get()->keyBy('saved_link_id');
            $position = 0;
            $seen = [];
            foreach ($orderedPublicIds as $publicId) {
                $id = $map[$publicId] ?? null;
                if ($id === null || ! $items->has($id) || isset($seen[$id])) {
                    throw ApiException::unprocessable('INVALID_ORDER', 'api.collections.invalid_order');
                }
                $seen[$id] = true;
                CollectionItem::query()->whereKey($items[$id]->id)->update(['position' => ++$position]);
            }
            foreach ($items as $savedLinkId => $item) {
                if (! isset($seen[$savedLinkId])) {
                    CollectionItem::query()->whereKey($item->id)->update(['position' => ++$position]);
                }
            }
        });
        $this->cache->invalidate();
    }

    /**
     * @param  list<string>  $publicIds
     * @return list<int>
     */
    private function ownedSavedLinkIds(User $user, array $publicIds, bool $includeTrashed = false): array
    {
        $publicIds = array_values(array_unique(array_filter($publicIds, fn ($id) => SavedLink::isValidPublicId($id))));
        $query = SavedLink::query()->where('user_id', $user->id)->whereIn('public_id', $publicIds);
        if ($includeTrashed) {
            $query->withTrashed();
        }
        $found = $query->pluck('id', 'public_id');

        // Any identifier that is not the caller's own link is treated as not found (no cross-user use).
        if ($found->count() !== count($publicIds)) {
            throw ApiException::unprocessable('INVALID_LINKS', 'api.collections.invalid_links');
        }

        return $found->values()->map(fn ($v) => (int) $v)->all();
    }

    private function assertVisibilityAllowed(User $user, mixed $visibility): void
    {
        if ($visibility === Visibility::PUBLIC->value && ! $user->hasVerifiedEmail()) {
            throw new ApiException('EMAIL_NOT_VERIFIED', 403, 'api.auth.email_not_verified');
        }
    }
}
