<?php

namespace App\Jobs;

use App\Models\Link;
use App\Services\Ai\AiService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class AnalyzeLinkWithAiJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Real failures allowed. Waiting for the free quota does not use an attempt (see retryUntil). */
    public int $tries = 0;

    public int $maxFailures;

    public int $timeout = 120;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $linkId, public readonly int $version)
    {
        $this->maxFailures = (int) config('linklibrary.ai.max_attempts', 3);
        $this->onQueue('ai');
    }

    /** Quota waits can span up to a day; give up after two days at most. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addDays(2);
    }

    public function uniqueId(): string
    {
        return $this->linkId.':'.$this->version;
    }

    public function handle(AiService $ai): void
    {
        $link = Link::query()->find($this->linkId);
        if ($link === null || $link->ai_version !== $this->version) {
            return;
        }

        $result = $ai->analyze($link, $this->version);

        if (isset($result['retry_at'])) {
            // Quota / concurrency wait: not a failure. Re-run when allowed (never earlier than the reset).
            $waits = (int) Cache::increment($this->waitKey());
            Cache::put($this->waitKey(), $waits, now()->addDays(3));
            $this->release(max(10, (int) ceil(now()->diffInSeconds($result['retry_at'], false))));

            return;
        }

        if ($result['retryable']) {
            $failures = $this->attempts() - (int) Cache::get($this->waitKey(), 0);
            if ($failures < $this->maxFailures) {
                $this->release([30, 120, 600][min(max($failures, 1) - 1, 2)]);

                return;
            }
            $ai->markFailed($link, $this->version);
        }
    }

    private function waitKey(): string
    {
        return 'ai:waits:'.$this->uniqueId();
    }

    public function failed(?Throwable $e): void
    {
        $link = Link::query()->find($this->linkId);
        if ($link !== null) {
            app(AiService::class)->markFailed($link, $this->version);
        }
    }
}
