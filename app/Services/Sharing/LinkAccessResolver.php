<?php

namespace App\Services\Sharing;

use App\Exceptions\ApiException;
use App\Models\SavedLink;
use App\Models\User;
use App\Services\Security\SecurityPolicy;

/**
 * Resolves who is looking at a saved link and in which capacity. Unauthorised requests always
 * receive the same 404 as non-existent identifiers, so private resources cannot be enumerated.
 */
class LinkAccessResolver
{
    public function __construct(
        private readonly AccessGraphService $graph,
        private readonly SecurityPolicy $policy,
    ) {}

    public function resolve(string $publicId, ?User $user): LinkViewContext
    {
        if (! SavedLink::isValidPublicId($publicId)) {
            throw ApiException::notFound();
        }

        /** @var SavedLink|null $savedLink */
        $savedLink = SavedLink::query()->withPresentation()->where('public_id', $publicId)->first();
        if ($savedLink === null) {
            throw ApiException::notFound();
        }

        if ($user !== null && $savedLink->user_id === $user->id) {
            return new LinkViewContext($savedLink, LinkViewContext::OWNER);
        }

        if (! $savedLink->user->isActive()) {
            throw ApiException::notFound();
        }

        if ($user !== null) {
            $grant = $this->graph->bestValidGrant($savedLink, $user);
            if ($grant !== null) {
                if ($this->policy->viewerAccessSuspended($savedLink->link)) {
                    throw new ApiException('LINK_SECURITY_BLOCKED', 403, 'api.links.security_blocked');
                }

                return new LinkViewContext($savedLink, LinkViewContext::VIEWER, $grant);
            }
        }

        if ($savedLink->isPubliclyEligible()) {
            return new LinkViewContext($savedLink, LinkViewContext::PUBLIC);
        }

        throw ApiException::notFound();
    }

    /** Owner-only resolution (management endpoints). Non-owners get 404. */
    public function resolveOwned(string $publicId, User $user, bool $withTrashed = false): SavedLink
    {
        if (! SavedLink::isValidPublicId($publicId)) {
            throw ApiException::notFound();
        }
        $query = SavedLink::query()->withPresentation()->where('public_id', $publicId)->where('user_id', $user->id);
        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query->first() ?? throw ApiException::notFound();
    }
}
