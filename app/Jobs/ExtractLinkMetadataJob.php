<?php

namespace App\Jobs;

use App\Enums\ExtractionStatus;
use App\Enums\ScanTrigger;
use App\Models\Link;
use App\Models\SavedLink;
use App\Notifications\LinkProcessingFailedNotification;
use App\Services\Extraction\ExtractionService;
use App\Services\Search\SearchIndexer;
use App\Services\Security\SecurityScanService;
use App\Services\Url\InvalidUrlException;
use App\Services\Url\UrlNormalizer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Idempotent: keyed by (link, extraction_version); a job for an outdated version is a no-op.
 */
class ExtractLinkMetadataJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout = 90;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $linkId, public readonly int $version)
    {
        $this->tries = (int) config('linklibrary.extraction.max_attempts', 4);
        $this->onQueue('extraction');
    }

    public function uniqueId(): string
    {
        return $this->linkId.':'.$this->version;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return (array) config('linklibrary.extraction.backoff', [60, 300, 900]);
    }

    public function handle(ExtractionService $extraction, SecurityScanService $security, SearchIndexer $indexer, UrlNormalizer $normalizer): void
    {
        $link = Link::query()->find($this->linkId);
        if ($link === null || $link->extraction_version !== $this->version) {
            return;
        }

        $result = $extraction->run($link, $this->version, $this->attempts());

        if ($result['retryable']) {
            if ($this->attempts() < $this->tries) {
                $backoff = $this->backoff();
                $this->release($backoff[min($this->attempts() - 1, count($backoff) - 1)] ?? 60);

                return;
            }
            $extraction->markFailed($link, $this->version, $result['error_code']);
            $indexer->reindexForLink($link->id);
            self::notifyRecentSavers($link, $result['error_code']);

            return;
        }

        if ($result['final_url'] !== null) {
            try {
                $final = $normalizer->normalize($result['final_url']);
                if ($final->host !== parse_url($link->normalized_url, PHP_URL_HOST)) {
                    $security->checkRelatedUrl($link, $final, ScanTrigger::REDIRECT);
                }
            } catch (InvalidUrlException) {
                // ignore: the redirect target was already validated by the fetcher
            }
        }

        $indexer->reindexForLink($link->id);

        if (in_array($result['status'], [ExtractionStatus::FAILED, ExtractionStatus::BLOCKED], true) && $result['error_code'] !== 'SECURITY_POLICY') {
            self::notifyRecentSavers($link, $result['error_code']);
        }

        if (in_array($result['status'], [ExtractionStatus::COMPLETED, ExtractionStatus::PARTIAL], true)) {
            $fresh = $link->fresh();
            if ($fresh !== null) {
                AnalyzeLinkWithAiJob::dispatch($fresh->id, $fresh->ai_version);
            }
        }
    }

    /** Tell users who saved this URL recently that automatic extraction failed (manual entry possible). */
    public static function notifyRecentSavers(Link $link, ?string $errorCode): void
    {
        SavedLink::query()->where('link_id', $link->id)->where('created_at', '>', now()->subDay())->with('user')->get()
            ->each(fn (SavedLink $s) => $s->user->notify(new LinkProcessingFailedNotification($s->public_id, 'extraction', $errorCode)));
    }

    public function failed(?Throwable $e): void
    {
        $link = Link::query()->find($this->linkId);
        if ($link !== null) {
            app(ExtractionService::class)->markFailed($link, $this->version, 'JOB_FAILED');
        }
    }
}
