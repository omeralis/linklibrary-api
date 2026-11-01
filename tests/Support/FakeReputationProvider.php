<?php

namespace Tests\Support;

use App\Enums\SecurityStatus;
use App\Services\Security\ProviderVerdict;
use App\Services\Security\UrlReputationProvider;
use App\Services\Url\NormalizedUrl;

class FakeReputationProvider implements UrlReputationProvider
{
    /** @var array<string, SecurityStatus|string> host => verdict or error code */
    public array $byHost = [];

    public function __construct(public SecurityStatus|string $default = SecurityStatus::CLEAR) {}

    public function name(): string
    {
        return 'fake_intel';
    }

    public function isAuthoritative(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function check(NormalizedUrl $url): ProviderVerdict
    {
        $verdict = $this->byHost[$url->host] ?? $this->default;
        if (is_string($verdict)) {
            return ProviderVerdict::error($this->name(), $verdict);
        }

        return new ProviderVerdict($this->name(), $verdict, true, $verdict->isUnsafe() ? ['MALWARE'] : []);
    }
}
