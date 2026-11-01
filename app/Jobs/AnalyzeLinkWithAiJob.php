<?php

namespace App\Jobs;

use App\Models\Link;
use App\Services\Ai\AiService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class AnalyzeLinkWithAiJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout = 120;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $linkId, public readonly int $version)
    {
        $this->tries = (int) config('linklibrary.ai.max_attempts', 3);
        $this->onQueue('ai');
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

        if ($result['retryable']) {
            if ($this->attempts() < $this->tries) {
                $this->release([30, 120, 600][min($this->attempts() - 1, 2)]);

                return;
            }
            $ai->markFailed($link, $this->version);
        }
    }

    public function failed(?Throwable $e): void
    {
        $link = Link::query()->find($this->linkId);
        if ($link !== null) {
            app(AiService::class)->markFailed($link, $this->version);
        }
    }
}
