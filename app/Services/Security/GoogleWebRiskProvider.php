<?php

namespace App\Services\Security;

use App\Enums\SecurityStatus;
use App\Services\Url\NormalizedUrl;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Google Web Risk API (uris.search) — the commercial counterpart of Safe Browsing.
 */
class GoogleWebRiskProvider implements UrlReputationProvider
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'web_risk';
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
        $query = http_build_query(['uri' => $url->normalized, 'key' => (string) $this->config['api_key']])
            .'&threatTypes=MALWARE&threatTypes=SOCIAL_ENGINEERING&threatTypes=UNWANTED_SOFTWARE';

        try {
            $response = Http::timeout((int) ceil((float) ($this->config['timeout'] ?? 5)))
                ->acceptJson()
                ->get((string) $this->config['endpoint'].'?'.$query);
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

        $threats = array_values(array_map('strval', (array) ($json['threat']['threatTypes'] ?? [])));
        if ($threats === []) {
            return new ProviderVerdict($this->name(), SecurityStatus::CLEAR, true);
        }

        $malicious = array_intersect($threats, ['MALWARE', 'SOCIAL_ENGINEERING']) !== [];

        return new ProviderVerdict($this->name(), $malicious ? SecurityStatus::MALICIOUS : SecurityStatus::SUSPICIOUS, true, $threats);
    }
}
