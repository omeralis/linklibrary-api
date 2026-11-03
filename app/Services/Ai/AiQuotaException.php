<?php

namespace App\Services\Ai;

/**
 * The provider reported that a rate limit or the free quota is exhausted (HTTP 429).
 *
 * Never triggers a fallback provider or a paid upgrade: the job simply waits until the quota resets.
 */
class AiQuotaException extends AiException
{
    public const CODE = 'AI_QUOTA_EXHAUSTED';

    /**
     * @param  ?int  $retryAfterSeconds  provider hint (Retry-After / retryDelay), when known
     * @param  bool  $daily  true when the daily quota (not just the per-minute rate) is exhausted
     */
    public function __construct(public readonly ?int $retryAfterSeconds = null, public readonly bool $daily = false)
    {
        parent::__construct(self::CODE, true);
    }
}
