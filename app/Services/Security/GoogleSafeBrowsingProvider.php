<?php

namespace App\Services\Security;

use App\Enums\SecurityStatus;
use App\Services\Url\NormalizedUrl;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Google Safe Browsing Lookup API v4 (threatMatches:find).
 *
 * Licensing note: the Safe Browsing APIs are for non-commercial use; commercial deployments should use
 * Google Web Risk (see GoogleWebRiskProvider). Check current terms before enabling in production.
 */
class GoogleSafeBrowsingProvider implements UrlReputationProvider
{
    private const MALICIOUS_TYPES = ['MALWARE', 'SOCIAL_ENGINEERING'];

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'safe_browsing';
    }

    public function isAuthoritative(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return ! empty($this->config['api_key']);
    }

    public function check(NormalizedUrl $url): ProviderVerdict
    {
        try {
            $response = Http::timeout((int) ceil((float) ($this->config['timeout'] ?? 5)))
                ->acceptJson()
                ->withQueryParameters(['key' => (string) $this->config['api_key']])
                ->post((string) $this->config['endpoint'], [
                    'client' => [
                        'clientId' => (string) $this->config['client_id'],
                        'clientVersion' => (string) $this->config['client_version'],
                    ],
                    'threatInfo' => [
                        'threatTypes' => ['MALWARE', 'SOCIAL_ENGINEERING', 'UNWANTED_SOFTWARE', 'POTENTIALLY_HARMFUL_APPLICATION'],
                        'platformTypes' => ['ANY_PLATFORM'],
                        'threatEntryTypes' => ['URL'],
                        'threatEntries' => [['url' => $url->normalized]],
                    ],
                ]);
        } catch (Throwable) {
            return ProviderVerdict::error($this->name(), 'PROVIDER_UNREACHABLE');
        }

        if (! $response->successful()) {
            return ProviderVerdict::error($this->name(), 'PROVIDER_HTTP_'.$response->status());
        }

        $json = $response->json();
        if (! is_array($json)) {
            return ProviderVerdict::error($this->name(), 'PROVIDER_INVALID_RESPONSE');
        }

        $threats = [];
        foreach ((array) ($json['matches'] ?? []) as $match) {
            if (is_array($match) && isset($match['threatType'])) {
                $threats[] = (string) $match['threatType'];
            }
        }
        $threats = array_values(array_unique($threats));

        if ($threats === []) {
            return new ProviderVerdict($this->name(), SecurityStatus::CLEAR, true);
        }

        $malicious = array_intersect($threats, self::MALICIOUS_TYPES) !== [];

        return new ProviderVerdict($this->name(), $malicious ? SecurityStatus::MALICIOUS : SecurityStatus::SUSPICIOUS, true, $threats);
    }
}
