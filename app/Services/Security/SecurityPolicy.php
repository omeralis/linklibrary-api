<?php

namespace App\Services\Security;

use App\Enums\ExtractionStatus;
use App\Enums\SecurityStatus;
use App\Models\Link;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single source of truth for what each security status permits.
 *
 * | status (effective) | fetch metadata         | publish | new sharing | open (owner) | open (viewer) | viewer access |
 * |--------------------|------------------------|---------|-------------|--------------|---------------|---------------|
 * | PENDING            | no (scan first)        | no      | no          | yes (warned) | n/a           | n/a           |
 * | UNKNOWN            | configurable (default) | no      | no          | yes (warned) | yes (warned)  | yes           |
 * | CLEAR (unexpired)  | yes                    | yes     | yes         | yes          | yes           | yes           |
 * | SUSPICIOUS         | no                     | no      | no          | yes (warned) | no            | yes (warned)  |
 * | MALICIOUS          | no                     | no      | no          | no           | no            | suspended     |
 *
 * A CLEAR verdict whose TTL has passed is treated as UNKNOWN until it is rescanned.
 *
 * Temporary, reversible policy (`security.allow_unverified_sharing`): UNKNOWN links may be published and shared
 * by their owner once metadata processing finished (extraction COMPLETED/PARTIAL, or BLOCKED because the destination
 * itself refuses automated fetching: robots.txt / login or paywall — the owner then supplies the details by hand),
 * and are then served publicly with a "not security-verified" warning. They are NEVER marked CLEAR. PENDING,
 * SUSPICIOUS and MALICIOUS stay blocked, and so does an extraction blocked for our own safety (SSRF / security policy).
 */
class SecurityPolicy
{
    /**
     * Extraction BLOCKED codes meaning "the site does not allow robots / needs a login". Not a security verdict about
     * the link; any other BLOCKED code (private address, unsafe redirect, security policy) stays unpublishable.
     */
    public const SITE_REFUSES_FETCH = ['ROBOTS_DISALLOWED', 'ACCESS_RESTRICTED'];

    public function __construct(
        private readonly bool $fetchWhenUnknown = true,
        private readonly bool $allowUnverifiedSharing = false,
        private readonly bool $scanningComingSoon = false,
    ) {}

    public function unverifiedSharingEnabled(): bool
    {
        return $this->allowUnverifiedSharing;
    }

    /** Manual scan / rescan is deferred ("Coming Soon"). */
    public function scanningComingSoon(): bool
    {
        return $this->scanningComingSoon;
    }

    /** UNKNOWN link that may circulate only because of the temporary policy. */
    public function isUnverifiedButAllowed(Link $link): bool
    {
        return $this->allowUnverifiedSharing
            && $link->effectiveSecurityStatus() === SecurityStatus::UNKNOWN
            && $this->metadataProcessed($link);
    }

    /** Fetched (COMPLETED/PARTIAL) or the site itself refuses automated access (details are entered manually). */
    private function metadataProcessed(Link $link): bool
    {
        return in_array($link->extraction_status, [ExtractionStatus::COMPLETED, ExtractionStatus::PARTIAL], true)
            || ($link->extraction_status === ExtractionStatus::BLOCKED
                && in_array($link->extraction_error_code, self::SITE_REFUSES_FETCH, true));
    }

    /**
     * Restrict a Link query to links that may be shown publicly (CLEAR and unexpired, or — under the temporary
     * policy — UNKNOWN with processed metadata, including a CLEAR verdict whose TTL has expired).
     *
     * @param  Builder<Link>  $q
     * @return Builder<Link>
     */
    public function constrainPublic(Builder $q): Builder
    {
        return $q->where(function (Builder $w) {
            $w->where(fn (Builder $c) => $c->where('security_status', SecurityStatus::CLEAR->value)->where('security_expires_at', '>', now()));
            if ($this->allowUnverifiedSharing) {
                $w->orWhere(fn (Builder $u) => $u
                    ->where(fn (Builder $m) => $m
                        ->whereIn('extraction_status', [ExtractionStatus::COMPLETED->value, ExtractionStatus::PARTIAL->value])
                        ->orWhere(fn (Builder $b) => $b
                            ->where('extraction_status', ExtractionStatus::BLOCKED->value)
                            ->whereIn('extraction_error_code', self::SITE_REFUSES_FETCH)))
                    ->where(fn (Builder $x) => $x
                        ->where('security_status', SecurityStatus::UNKNOWN->value)
                        ->orWhere(fn (Builder $e) => $e->where('security_status', SecurityStatus::CLEAR->value)
                            ->where(fn (Builder $t) => $t->whereNull('security_expires_at')->orWhere('security_expires_at', '<=', now())))));
            }
        });
    }

    public function mayFetch(Link $link): bool
    {
        return match ($link->effectiveSecurityStatus()) {
            SecurityStatus::CLEAR => true,
            SecurityStatus::UNKNOWN => $this->fetchWhenUnknown,
            default => false,
        };
    }

    public function mayPublish(Link $link): bool
    {
        return $link->effectiveSecurityStatus() === SecurityStatus::CLEAR || $this->isUnverifiedButAllowed($link);
    }

    public function mayShare(Link $link): bool
    {
        return $link->effectiveSecurityStatus() === SecurityStatus::CLEAR || $this->isUnverifiedButAllowed($link);
    }

    public function mayOpen(Link $link, bool $isOwner): bool
    {
        $status = $link->effectiveSecurityStatus();
        if ($status === SecurityStatus::MALICIOUS) {
            return false;
        }
        if ($isOwner) {
            return true;
        }

        return $status === SecurityStatus::CLEAR || $status === SecurityStatus::UNKNOWN;
    }

    /** Whether a user-facing warning must accompany the link. */
    public function requiresWarning(Link $link): bool
    {
        return $link->effectiveSecurityStatus() !== SecurityStatus::CLEAR;
    }

    public function viewerAccessSuspended(Link $link): bool
    {
        return $link->effectiveSecurityStatus() === SecurityStatus::MALICIOUS;
    }
}
