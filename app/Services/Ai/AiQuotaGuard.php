<?php

namespace App\Services\Ai;

use Closure;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Free-tier protection for external AI providers. Everything is counted locally BEFORE a request is made,
 * so the application stays below the provider's free limits instead of discovering them through 429s:
 *
 *  - requests per minute (`rpm`),
 *  - requests per provider-day (`daily`), where the day rolls over at midnight in `timezone`
 *    (Gemini: midnight America/Los_Angeles),
 *  - one request in flight at a time (cache lock),
 *  - a shared "blocked until" marker set when the provider itself answers 429, so every worker backs off together.
 *
 * It never switches provider, never raises a limit and never retries before the quota can have reset.
 */
class AiQuotaGuard
{
    public const KEY_BLOCKED_UNTIL = 'ai:quota:blocked_until';

    private const MIN_BACKOFF_SECONDS = 60;

    private const RESET_MARGIN_SECONDS = 120;

    public function __construct(
        private readonly int $requestsPerMinute,
        private readonly int $dailyLimit,
        private readonly string $timezone,
        private readonly int $lockSeconds,
    ) {}

    /** When AI work may resume, or null when the quota is available right now. */
    public function blockedUntil(): ?Carbon
    {
        $until = Cache::get(self::KEY_BLOCKED_UNTIL);
        if (! is_int($until) || $until <= now()->getTimestamp()) {
            return null;
        }

        return Carbon::createFromTimestamp($until);
    }

    /** When the quota is known to be unavailable (provider 429 or local daily cap reached), for display only. */
    public function unavailableUntil(): ?Carbon
    {
        $blocked = $this->blockedUntil();
        if ($blocked !== null) {
            return $blocked;
        }

        return $this->dailyLimit > 0 && $this->dailyUsed() >= $this->dailyLimit ? $this->nextReset() : null;
    }

    /**
     * Reserve one request. Returns null when allowed, otherwise the moment to try again.
     */
    public function reserve(): ?Carbon
    {
        if (($blocked = $this->blockedUntil()) !== null) {
            return $blocked;
        }

        if ($this->dailyLimit > 0) {
            $key = 'ai:daily:'.$this->providerDay();
            Cache::add($key, 0, now()->addDays(2));
            if ((int) Cache::increment($key) > $this->dailyLimit) {
                return $this->nextReset();
            }
        }

        if ($this->requestsPerMinute > 0) {
            $key = 'ai:rpm:'.now()->format('YmdHi');
            Cache::add($key, 0, now()->addMinutes(2));
            if ((int) Cache::increment($key) > $this->requestsPerMinute) {
                return now()->startOfMinute()->addMinute()->addSeconds(random_int(1, 15));
            }
        }

        return null;
    }

    /** Record a provider 429 and stop all AI requests until the quota can have reset. */
    public function markExhausted(AiQuotaException $e): Carbon
    {
        $until = $e->daily
            ? $this->nextReset()
            : now()->addSeconds(max(self::MIN_BACKOFF_SECONDS, (int) ($e->retryAfterSeconds ?? 0)));
        // A provider hint longer than a minute is honoured as is (but never shorter than the minimum).
        if ($e->daily && $e->retryAfterSeconds !== null && now()->addSeconds($e->retryAfterSeconds)->greaterThan($until)) {
            $until = now()->addSeconds($e->retryAfterSeconds);
        }

        Cache::put(self::KEY_BLOCKED_UNTIL, $until->getTimestamp(), $until);

        return $until;
    }

    /**
     * Run $callback holding the single in-flight slot. Returns [true, result] or [false, retryAt] when busy.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return array{0: bool, 1: mixed}
     */
    public function exclusive(Closure $callback): array
    {
        $lock = Cache::lock('ai:inflight', $this->lockSeconds);
        if (! $lock->get()) {
            return [false, now()->addSeconds(random_int(10, 25))];
        }

        try {
            return [true, $callback()];
        } finally {
            $lock->release();
        }
    }

    /** First instant (plus a safety margin) after the provider's current quota day. */
    public function nextReset(): Carbon
    {
        $tz = new DateTimeZone($this->timezone);

        return Carbon::now($tz)->addDay()->startOfDay()->addSeconds(self::RESET_MARGIN_SECONDS)->setTimezone(config('app.timezone'));
    }

    private function providerDay(): string
    {
        return Carbon::now(new DateTimeZone($this->timezone))->format('Ymd');
    }

    public function dailyUsed(): int
    {
        return (int) Cache::get('ai:daily:'.$this->providerDay(), 0);
    }

    public function dailyLimit(): int
    {
        return $this->dailyLimit;
    }
}
