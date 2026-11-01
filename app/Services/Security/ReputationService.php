<?php

namespace App\Services\Security;

use App\Enums\SecurityStatus;
use App\Services\Url\NormalizedUrl;

/**
 * Runs all configured reputation providers and aggregates their verdicts.
 *
 *  - any MALICIOUS              -> MALICIOUS
 *  - otherwise any SUSPICIOUS   -> SUSPICIOUS
 *  - otherwise at least one authoritative provider returned CLEAR -> CLEAR
 *  - otherwise                  -> UNKNOWN (a failed/unavailable provider never yields CLEAR)
 */
class ReputationService
{
    /** @param list<UrlReputationProvider> $providers */
    public function __construct(private readonly array $providers) {}

    /** @return array{verdict: SecurityStatus, results: list<ProviderVerdict>} */
    public function evaluate(NormalizedUrl $url): array
    {
        $results = [];
        foreach ($this->providers as $provider) {
            if (! $provider->isConfigured()) {
                continue;
            }
            $results[] = $provider->check($url);
        }

        return ['verdict' => self::aggregate($results), 'results' => $results];
    }

    /** @param list<ProviderVerdict> $results */
    public static function aggregate(array $results): SecurityStatus
    {
        $worst = null;
        $authoritativeClear = false;
        foreach ($results as $r) {
            if ($r->verdict === SecurityStatus::MALICIOUS || $r->verdict === SecurityStatus::SUSPICIOUS) {
                if ($worst === null || $r->verdict->severity() > $worst->severity()) {
                    $worst = $r->verdict;
                }
            }
            if ($r->authoritative && $r->verdict === SecurityStatus::CLEAR && $r->errorCode === null) {
                $authoritativeClear = true;
            }
        }

        if ($worst !== null) {
            return $worst;
        }

        return $authoritativeClear ? SecurityStatus::CLEAR : SecurityStatus::UNKNOWN;
    }

    public function hasAuthoritativeProvider(): bool
    {
        foreach ($this->providers as $p) {
            if ($p->isConfigured() && $p->isAuthoritative()) {
                return true;
            }
        }

        return false;
    }
}
