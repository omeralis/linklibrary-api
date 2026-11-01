<?php

namespace App\Services\Security;

use App\Enums\SecurityStatus;
use App\Models\Link;

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
 */
class SecurityPolicy
{
    public function __construct(private readonly bool $fetchWhenUnknown = true) {}

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
        return $link->effectiveSecurityStatus() === SecurityStatus::CLEAR;
    }

    public function mayShare(Link $link): bool
    {
        return $link->effectiveSecurityStatus() === SecurityStatus::CLEAR;
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
