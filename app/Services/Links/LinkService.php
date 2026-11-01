<?php

namespace App\Services\Links;

use App\Enums\ExtractionStatus;
use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Enums\SecurityStatus;
use App\Enums\SourceType;
use App\Enums\Visibility;
use App\Exceptions\ApiException;
use App\Jobs\ExtractLinkMetadataJob;
use App\Models\Category;
use App\Models\Link;
use App\Models\LinkSecurityScan;
use App\Models\SavedLink;
use App\Models\User;
use App\Services\Audit\AuditAction;
use App\Services\Audit\AuditLogger;
use App\Services\Discovery\DiscoveryCache;
use App\Services\Http\IpAddressPolicy;
use App\Services\Http\SafeHttpClient;
use App\Services\Search\SearchIndexer;
use App\Services\Security\SecurityPolicy;
use App\Services\Security\SecurityScanService;
use App\Services\Sharing\AccessGraphService;
use App\Services\Url\InvalidUrlException;
use App\Services\Url\NormalizedUrl;
use App\Services\Url\UrlNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Saved-link lifecycle. Controllers stay thin; all multi-record changes are transactional.
 */
class LinkService
{
    public function __construct(
        private readonly UrlNormalizer $normalizer,
        private readonly SafeHttpClient $fetcher,
        private readonly IpAddressPolicy $ipPolicy,
        private readonly SecurityScanService $security,
        private readonly SecurityPolicy $policy,
        private readonly TagService $tags,
        private readonly SearchIndexer $indexer,
        private readonly AccessGraphService $access,
        private readonly AuditLogger $audit,
        private readonly DiscoveryCache $discoveryCache,
    ) {}

    /**
     * Validate + normalize a user-supplied URL and run synchronous local safety checks
     * (no network access happens here).
     */
    public function validateUrl(string $input, string $field = 'url'): NormalizedUrl
    {
        try {
            $url = $this->normalizer->normalize($input);
        } catch (InvalidUrlException $e) {
            throw ApiException::unprocessable('INVALID_URL', 'api.links.invalid_url', [$field => [__('api.url_reasons.'.$e->reason)]]);
        }

        $defaultPort = $url->scheme === 'https' ? 443 : 80;
        $portAllowed = $url->port === $defaultPort || in_array($url->port, (array) config('linklibrary.fetch.allowed_ports'), true);

        if ($this->fetcher->isInternalHostname($url->host)
            || ($url->hostIsIp && ! $this->ipPolicy->isPublic($url->host))
            || ! $portAllowed) {
            throw ApiException::unprocessable('URL_NOT_ALLOWED', 'api.links.url_not_allowed', [$field => [__('api.links.url_not_allowed')]]);
        }

        return $url;
    }

    /**
     * @param  array<string, mixed>  $attributes  custom_title, custom_description, notes, category, tags
     * @return array{0: SavedLink, 1: bool} [savedLink, created]
     */
    public function save(User $user, string $inputUrl, array $attributes = []): array
    {
        $url = $this->validateUrl($inputUrl);

        [$savedLink, $created, $link, $linkIsNew] = DB::transaction(function () use ($user, $url, $attributes) {
            DB::table('links')->insertOrIgnore([
                'url_hash' => $url->hash(),
                'normalized_url' => $url->normalized,
                'original_url' => $url->original,
                'hostname' => $url->host,
                'source_type' => SourceType::WEBSITE->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            /** @var Link $link */
            $link = Link::query()->where('url_hash', $url->hash())->firstOrFail();
            $linkIsNew = $link->wasRecentlyCreated || ($link->security_status === SecurityStatus::PENDING && ! $link->securityScans()->exists());

            $existing = SavedLink::withTrashed()->where('user_id', $user->id)->where('link_id', $link->id)->lockForUpdate()->first();
            if ($existing !== null && ! $existing->trashed()) {
                return [$existing, false, $link, $linkIsNew];
            }

            if ($existing !== null) {
                // Re-saving a deleted link restores it as a fresh private draft.
                $existing->restore();
                $existing->forceFill([
                    'visibility' => Visibility::PRIVATE,
                    'allow_resharing' => false,
                    'published_at' => null,
                ])->save();
                $savedLink = $existing;
            } else {
                $savedLink = new SavedLink;
                $savedLink->user_id = $user->id;
                $savedLink->link_id = $link->id;
                $savedLink->visibility = Visibility::PRIVATE;
                $savedLink->allow_resharing = false;
                $savedLink->is_favorite = false;
                try {
                    DB::transaction(fn () => $savedLink->save()); // savepoint
                } catch (UniqueConstraintViolationException) {
                    // Concurrent save of the same URL by the same user: return the winner.
                    return [SavedLink::query()->where('user_id', $user->id)->where('link_id', $link->id)->firstOrFail(), false, $link, $linkIsNew];
                }
            }

            $this->applyAttributes($savedLink, $attributes);

            return [$savedLink, true, $link, $linkIsNew];
        });

        if ($created) {
            $this->audit->log(AuditAction::LINK_CREATED, $user, 'saved_link', $savedLink->public_id, ['host' => $url->host]);
        }

        $this->ensureProcessing($link, $linkIsNew);
        $this->indexer->reindex($savedLink->fresh() ?? $savedLink);

        return [$savedLink->fresh() ?? $savedLink, $created];
    }

    /** Queue the security scan (always first) and, when permitted, metadata extraction. */
    public function ensureProcessing(Link $link, bool $forceScan = false): void
    {
        $link->refresh();
        $scanPending = LinkSecurityScan::query()->where('link_id', $link->id)
            ->whereIn('status', [ScanStatus::PENDING->value, ScanStatus::PROCESSING->value])
            ->where('created_at', '>', now()->subMinutes(15))
            ->exists();

        if (($forceScan || $link->security_status === SecurityStatus::PENDING) && ! $scanPending) {
            $this->security->requestScan($link, ScanTrigger::INITIAL);

            return; // extraction is queued by the scan once the policy permits fetching
        }

        if ($link->extraction_status === ExtractionStatus::PENDING && $this->policy->mayFetch($link)) {
            ExtractLinkMetadataJob::dispatch($link->id, $link->extraction_version)->afterCommit();
        }
    }

    /** @param array<string, mixed> $attributes */
    public function update(SavedLink $savedLink, User $actor, array $attributes): SavedLink
    {
        if (array_key_exists('custom_image_url', $attributes) && $attributes['custom_image_url'] !== null) {
            $attributes['custom_image_url'] = $this->validateUrl((string) $attributes['custom_image_url'], 'custom_image_url')->normalized;
        }

        DB::transaction(fn () => $this->applyAttributes($savedLink, $attributes));

        $this->audit->log(AuditAction::LINK_UPDATED, $actor, 'saved_link', $savedLink->public_id, ['fields' => array_values(array_intersect(array_keys($attributes), ['custom_title', 'custom_description', 'custom_image_url', 'custom_source_type', 'notes', 'category', 'tags']))]);
        $this->indexer->reindex($savedLink->fresh() ?? $savedLink);
        if ($savedLink->isPublic()) {
            $this->discoveryCache->invalidate();
        }

        return $savedLink->fresh() ?? $savedLink;
    }

    /** @param array<string, mixed> $attributes */
    private function applyAttributes(SavedLink $savedLink, array $attributes): void
    {
        $fill = array_intersect_key($attributes, array_flip(['custom_title', 'custom_description', 'custom_image_url', 'custom_source_type', 'notes']));
        foreach (['custom_title', 'custom_description', 'notes'] as $key) {
            if (array_key_exists($key, $fill) && is_string($fill[$key])) {
                $fill[$key] = trim($fill[$key]) === '' ? null : trim($fill[$key]);
            }
        }

        if (array_key_exists('category', $attributes)) {
            $fill['category_id'] = $attributes['category'] === null
                ? null
                : Category::query()->where('slug', $attributes['category'])->where('is_active', true)->value('id');
        }

        if ($fill !== []) {
            $savedLink->forceFill($fill)->save();
        }

        if (array_key_exists('tags', $attributes) && is_array($attributes['tags'])) {
            $this->tags->syncUserTags($savedLink, array_values(array_map('strval', $attributes['tags'])));
        }
    }

    public function setFavorite(SavedLink $savedLink, bool $favorite): SavedLink
    {
        $savedLink->forceFill(['is_favorite' => $favorite])->save();

        return $savedLink;
    }

    public function changeVisibility(SavedLink $savedLink, User $actor, Visibility $visibility): SavedLink
    {
        if ($visibility === $savedLink->visibility) {
            return $savedLink;
        }

        if ($visibility === Visibility::PUBLIC) {
            if (! $actor->hasVerifiedEmail()) {
                throw new ApiException('EMAIL_NOT_VERIFIED', 403, 'api.auth.email_not_verified');
            }
            $savedLink->loadMissing('link');
            if (! $this->policy->mayPublish($savedLink->link)) {
                throw ApiException::unprocessable('LINK_NOT_PUBLISHABLE', 'api.links.not_publishable', [
                    'security_status' => [$savedLink->link->effectiveSecurityStatus()->value],
                ]);
            }
        }

        $from = $savedLink->visibility;
        // Grant history is untouched by visibility changes: going back to PRIVATE restores exactly the
        // grants that are still valid.
        $savedLink->forceFill([
            'visibility' => $visibility,
            'published_at' => $visibility === Visibility::PUBLIC ? now() : null,
        ])->save();

        $this->discoveryCache->invalidate();
        $this->audit->log(AuditAction::VISIBILITY_CHANGED, $actor, 'saved_link', $savedLink->public_id, ['from' => $from->value, 'to' => $visibility->value]);

        return $savedLink;
    }

    public function changeSharingSettings(SavedLink $savedLink, User $actor, bool $allowResharing): SavedLink
    {
        if ($savedLink->allow_resharing !== $allowResharing) {
            // Disabling resharing blocks NEW downstream invitations only; existing valid grants remain.
            $savedLink->forceFill(['allow_resharing' => $allowResharing])->save();
            $this->audit->log(AuditAction::SHARING_SETTINGS_CHANGED, $actor, 'saved_link', $savedLink->public_id, ['allow_resharing' => $allowResharing]);
        }

        return $savedLink;
    }

    public function delete(SavedLink $savedLink, User $actor, bool $permanent = false): void
    {
        $wasPublic = $savedLink->isPublic();

        // Deleting invalidates every grant and pending invitation.
        $this->access->revokeAllForSavedLink($savedLink, $actor, 'LINK_DELETED');

        DB::transaction(function () use ($savedLink, $permanent) {
            if ($permanent) {
                $savedLink->forceDelete();
            } else {
                $savedLink->forceFill(['visibility' => Visibility::PRIVATE, 'published_at' => null])->save();
                $savedLink->delete();
            }
        });

        if ($wasPublic) {
            $this->discoveryCache->invalidate();
        }
        $this->audit->log(AuditAction::LINK_DELETED, $actor, 'saved_link', $savedLink->public_id, ['permanent' => $permanent]);
    }

    public function restore(SavedLink $savedLink, User $actor): SavedLink
    {
        $savedLink->restore();
        $this->audit->log(AuditAction::LINK_RESTORED, $actor, 'saved_link', $savedLink->public_id);

        return $savedLink;
    }

    /** Re-run extraction and AI for the canonical link (manual overrides are preserved). */
    public function reanalyze(SavedLink $savedLink): void
    {
        $link = $savedLink->link;
        DB::transaction(function () use ($link) {
            $locked = Link::query()->whereKey($link->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill([
                'extraction_version' => $locked->extraction_version + 1,
                'ai_version' => $locked->ai_version + 1,
                'extraction_status' => ExtractionStatus::PENDING,
                'extraction_error_code' => null,
            ])->save();
        });

        $this->ensureProcessing($link->fresh() ?? $link);
    }

    public function requestRescan(SavedLink $savedLink, User $actor): LinkSecurityScan
    {
        $link = $savedLink->link;
        $recent = LinkSecurityScan::query()->where('link_id', $link->id)
            ->whereIn('status', [ScanStatus::PENDING->value, ScanStatus::PROCESSING->value])
            ->where('created_at', '>', now()->subMinutes(10))
            ->latest('id')->first();
        if ($recent !== null) {
            return $recent;
        }

        $this->audit->log(AuditAction::SECURITY_RESCAN_REQUESTED, $actor, 'saved_link', $savedLink->public_id);

        return $this->security->requestScan($link, ScanTrigger::RESCAN_REQUEST, $actor);
    }
}
