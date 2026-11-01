<?php

namespace App\Services\Security;

use App\Services\Url\NormalizedUrl;

interface UrlReputationProvider
{
    public function name(): string;

    /**
     * Authoritative providers (real threat-intelligence services) can vouch for a URL (CLEAR).
     * Non-authoritative ones (heuristics, AI) can only raise concerns.
     */
    public function isAuthoritative(): bool;

    /** Whether the provider is configured (credentials present). Unconfigured providers are skipped. */
    public function isConfigured(): bool;

    /** Must never throw for provider/network failures: return an UNKNOWN verdict with an error code. */
    public function check(NormalizedUrl $url): ProviderVerdict;
}
