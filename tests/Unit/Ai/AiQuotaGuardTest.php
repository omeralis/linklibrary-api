<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiQuotaException;
use App\Services\Ai\AiQuotaGuard;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AiQuotaGuardTest extends TestCase
{
    private function guard(int $rpm = 3, int $daily = 5): AiQuotaGuard
    {
        return new AiQuotaGuard($rpm, $daily, 'America/Los_Angeles', 30);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_per_minute_limit_waits_for_the_next_minute(): void
    {
        Carbon::setTestNow('2026-11-03 10:00:10');
        $g = $this->guard(rpm: 2, daily: 100);

        $this->assertNull($g->reserve());
        $this->assertNull($g->reserve());
        $retry = $g->reserve();
        $this->assertNotNull($retry);
        $this->assertTrue($retry->greaterThan(Carbon::parse('2026-11-03 10:01:00')));
        $this->assertTrue($retry->lessThan(Carbon::parse('2026-11-03 10:01:30')));
    }

    public function test_daily_cap_waits_until_midnight_pacific(): void
    {
        Carbon::setTestNow('2026-11-03 18:00:00'); // 10:00 in Los Angeles (PST)
        $g = $this->guard(rpm: 100, daily: 2);

        $this->assertNull($g->reserve());
        $this->assertNull($g->reserve());
        $retry = $g->reserve();

        $this->assertNotNull($retry);
        $this->assertSame('2026-11-04 08:02:00', $retry->copy()->utc()->format('Y-m-d H:i:s')); // 00:00 PST + 2 min margin
        $this->assertNotNull($g->unavailableUntil());
    }

    public function test_provider_429_blocks_everyone_until_reset_and_never_earlier(): void
    {
        Carbon::setTestNow('2026-11-03 18:00:00');
        $g = $this->guard(rpm: 100, daily: 100);

        $until = $g->markExhausted(new AiQuotaException(null, daily: true));
        $this->assertSame('2026-11-04 08:02:00', $until->copy()->utc()->format('Y-m-d H:i:s'));
        $this->assertEquals($until->getTimestamp(), $g->reserve()->getTimestamp());

        Carbon::setTestNow('2026-11-04 08:03:00');
        $this->assertNull($g->blockedUntil());
        $this->assertNull($g->reserve());
    }

    public function test_short_provider_hint_has_a_one_minute_floor(): void
    {
        Carbon::setTestNow('2026-11-03 18:00:00');
        $until = $this->guard()->markExhausted(new AiQuotaException(5, daily: false));
        $this->assertSame(60, (int) now()->diffInSeconds($until));
    }

    public function test_only_one_request_in_flight(): void
    {
        $g = $this->guard();
        [$ran, $inner] = $g->exclusive(function () use ($g) {
            [$ranInner] = $g->exclusive(fn () => 'nested');

            return $ranInner;
        });

        $this->assertTrue($ran);
        $this->assertFalse($inner);
    }
}
