<?php

namespace Tests\Unit\Security;

use App\Enums\SecurityStatus;
use App\Services\Security\HeuristicReputationProvider;
use App\Services\Security\ProviderVerdict;
use App\Services\Security\ReputationService;
use App\Services\Url\UrlNormalizer;
use PHPUnit\Framework\TestCase;

class ReputationAggregationTest extends TestCase
{
    public function test_failed_provider_never_yields_clear(): void
    {
        $this->assertSame(SecurityStatus::UNKNOWN, ReputationService::aggregate([ProviderVerdict::error('sb', 'PROVIDER_HTTP_503')]));
        $this->assertSame(SecurityStatus::UNKNOWN, ReputationService::aggregate([]));
    }

    public function test_heuristics_alone_cannot_clear(): void
    {
        $this->assertSame(SecurityStatus::UNKNOWN, ReputationService::aggregate([new ProviderVerdict('heuristic', null, false)]));
        $this->assertSame(SecurityStatus::UNKNOWN, ReputationService::aggregate([new ProviderVerdict('ai', SecurityStatus::CLEAR, false)]));
    }

    public function test_worst_verdict_wins(): void
    {
        $this->assertSame(SecurityStatus::MALICIOUS, ReputationService::aggregate([
            new ProviderVerdict('a', SecurityStatus::CLEAR, true),
            new ProviderVerdict('b', SecurityStatus::SUSPICIOUS, false),
            new ProviderVerdict('c', SecurityStatus::MALICIOUS, true),
        ]));
        $this->assertSame(SecurityStatus::SUSPICIOUS, ReputationService::aggregate([
            new ProviderVerdict('a', SecurityStatus::CLEAR, true),
            new ProviderVerdict('h', SecurityStatus::SUSPICIOUS, false),
        ]));
    }

    public function test_authoritative_clear(): void
    {
        $this->assertSame(SecurityStatus::CLEAR, ReputationService::aggregate([
            new ProviderVerdict('a', SecurityStatus::CLEAR, true),
            ProviderVerdict::error('b', 'TIMEOUT'),
        ]));
    }

    public function test_heuristic_signals(): void
    {
        $n = new UrlNormalizer;
        $h = new HeuristicReputationProvider(['evil.example']);
        $this->assertSame(SecurityStatus::MALICIOUS, $h->check($n->normalize('https://login.evil.example/'))->verdict);
        $this->assertSame(SecurityStatus::SUSPICIOUS, $h->check($n->normalize('http://93.184.216.34/'))->verdict);
        // Cyrillic "а" mixed with Latin letters (homograph)
        $this->assertSame(SecurityStatus::SUSPICIOUS, $h->check($n->normalize('https://pаypal.com/'))->verdict);
        $this->assertNull($h->check($n->normalize('https://example.com/'))->verdict);
    }
}
