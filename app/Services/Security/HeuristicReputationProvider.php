<?php

namespace App\Services\Security;

use App\Enums\SecurityStatus;
use App\Services\Url\NormalizedUrl;

/**
 * Local, non-authoritative checks. Can flag a URL but can never vouch for it.
 */
class HeuristicReputationProvider implements UrlReputationProvider
{
    /** @param list<string> $blocklist */
    public function __construct(private readonly array $blocklist = []) {}

    public function name(): string
    {
        return 'heuristic';
    }

    public function isAuthoritative(): bool
    {
        return false;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function check(NormalizedUrl $url): ProviderVerdict
    {
        $host = $url->host;

        foreach ($this->blocklist as $blocked) {
            $blocked = strtolower(trim($blocked));
            if ($blocked !== '' && ($host === ltrim($blocked, '.') || str_ends_with($host, '.'.ltrim($blocked, '.')))) {
                return new ProviderVerdict($this->name(), SecurityStatus::MALICIOUS, false, ['BLOCKLISTED_HOST']);
            }
        }

        $signals = [];
        if ($url->hostIsIp) {
            $signals[] = 'IP_LITERAL_HOST';
        }
        if (substr_count($host, '.') >= 6) {
            $signals[] = 'EXCESSIVE_SUBDOMAINS';
        }
        if ($this->hasMixedScriptIdn($host)) {
            $signals[] = 'MIXED_SCRIPT_IDN';
        }

        return $signals === []
            ? new ProviderVerdict($this->name(), null, false)
            : new ProviderVerdict($this->name(), SecurityStatus::SUSPICIOUS, false, $signals);
    }

    /** Detects homograph-style labels mixing Latin with Cyrillic/Greek letters. */
    private function hasMixedScriptIdn(string $host): bool
    {
        foreach (explode('.', $host) as $label) {
            if (! str_starts_with($label, 'xn--')) {
                continue;
            }
            $unicode = idn_to_utf8($label, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($unicode === false) {
                return true;
            }
            $latin = preg_match('/\p{Latin}/u', $unicode) === 1;
            $confusable = preg_match('/[\p{Cyrillic}\p{Greek}]/u', $unicode) === 1;
            if ($latin && $confusable) {
                return true;
            }
        }

        return false;
    }
}
