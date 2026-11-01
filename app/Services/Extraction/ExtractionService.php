<?php

namespace App\Services\Extraction;

use App\Enums\ExtractionStatus;
use App\Models\Link;
use App\Models\LinkExtractionJob;
use App\Services\Http\FetchException;
use App\Services\Security\SecurityPolicy;
use App\Services\Url\InvalidUrlException;
use App\Services\Url\UrlNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Runs the extractor chain for a canonical link and persists the outcome.
 *
 * Guarantees
 *  - Fetching only happens when the security policy permits it (never for SUSPICIOUS/MALICIOUS).
 *  - Results are written only if the link's extraction_version still matches the job's version, so a
 *    stale job can never overwrite a newer extraction. User overrides live on saved_links and are
 *    never touched here.
 *  - Failures are recorded with safe error codes; nothing is invented when extraction fails.
 */
class ExtractionService
{
    /** @param list<Extractor> $extractors ordered: specific providers first, generic last */
    public function __construct(
        private readonly array $extractors,
        private readonly UrlNormalizer $normalizer,
        private readonly SecurityPolicy $policy,
    ) {}

    /**
     * @return array{status: ExtractionStatus, retryable: bool, error_code: ?string, final_url: ?string}
     */
    public function run(Link $link, int $version, int $attempt = 1): array
    {
        $link->refresh();
        if ($link->extraction_version !== $version) {
            return ['status' => $link->extraction_status, 'retryable' => false, 'error_code' => 'STALE_JOB', 'final_url' => null];
        }

        if (! $this->policy->mayFetch($link)) {
            $this->finish($link, $version, ExtractionStatus::BLOCKED, null, 'SECURITY_POLICY');
            $this->record($link, $version, $attempt, 'BLOCKED', null, null, 'SECURITY_POLICY', []);

            return ['status' => ExtractionStatus::BLOCKED, 'retryable' => false, 'error_code' => 'SECURITY_POLICY', 'final_url' => null];
        }

        try {
            $url = $this->normalizer->normalize($link->normalized_url);
        } catch (InvalidUrlException) {
            $this->finish($link, $version, ExtractionStatus::FAILED, null, 'INVALID_URL');

            return ['status' => ExtractionStatus::FAILED, 'retryable' => false, 'error_code' => 'INVALID_URL', 'final_url' => null];
        }

        Link::query()->whereKey($link->id)->where('extraction_version', $version)
            ->update(['extraction_status' => ExtractionStatus::PROCESSING->value, 'updated_at' => now()]);

        $started = microtime(true);
        $result = null;
        $errorCode = null;
        $retryable = false;
        $httpStatus = null;
        $usedExtractor = null;
        $finalUrl = null;

        foreach ($this->extractors as $extractor) {
            if (! $extractor->supports($url)) {
                continue;
            }
            $usedExtractor = $extractor->name();
            try {
                $candidate = $extractor->extract($url);
                $httpStatus = $candidate->httpStatus;
                $finalUrl = isset($candidate->extra['final_url']) ? (string) $candidate->extra['final_url'] : null;
                if ($result === null) {
                    $result = $candidate;
                } else {
                    $result->mergeMissing($candidate);
                }
                if ($result->isComplete()) {
                    break;
                }
            } catch (ExtractionException $e) {
                $errorCode = $e->errorCode;
                $retryable = $e->retryable;
                $httpStatus = $e->httpStatus ?? $httpStatus;
                if ($e->errorCode === 'ROBOTS_DISALLOWED' || $e->errorCode === 'ACCESS_RESTRICTED') {
                    break;
                }
            } catch (FetchException $e) {
                $errorCode = $e->errorCode;
                $retryable = $e->retryable;
                if (! $e->retryable) {
                    break; // e.g. blocked IP/port: no other extractor should try
                }
            }
        }

        $diagnostics = [
            'host' => $url->host,
            'path' => mb_substr($url->path, 0, 200),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'content_type' => $result?->contentType,
        ];

        if ($result !== null && $result->hasAny()) {
            $status = $result->isComplete() ? ExtractionStatus::COMPLETED : ExtractionStatus::PARTIAL;
            $this->finish($link, $version, $status, $result, null);
            $this->record($link, $version, $attempt, $status->value, $usedExtractor, $httpStatus, null, $diagnostics);

            return ['status' => $status, 'retryable' => false, 'error_code' => null, 'final_url' => $finalUrl];
        }

        if ($result !== null) {
            // Reachable but no usable metadata (e.g. images, binaries): keep what we know (content type).
            $this->finish($link, $version, ExtractionStatus::PARTIAL, $result, $errorCode ?? 'NO_METADATA');
            $this->record($link, $version, $attempt, 'PARTIAL', $usedExtractor, $httpStatus, $errorCode ?? 'NO_METADATA', $diagnostics);

            return ['status' => ExtractionStatus::PARTIAL, 'retryable' => false, 'error_code' => $errorCode ?? 'NO_METADATA', 'final_url' => $finalUrl];
        }

        $blocked = in_array($errorCode, ['ROBOTS_DISALLOWED', 'ACCESS_RESTRICTED', FetchException::BLOCKED_IP, FetchException::BLOCKED_HOST, FetchException::BLOCKED_PORT, FetchException::UNSAFE_REDIRECT], true);
        $status = $blocked ? ExtractionStatus::BLOCKED : ExtractionStatus::FAILED;
        $this->record($link, $version, $attempt, $status->value, $usedExtractor, $httpStatus, $errorCode, $diagnostics);

        if ($retryable) {
            // Leave status as PENDING so the retry can pick it up; the job decides whether to give up.
            Link::query()->whereKey($link->id)->where('extraction_version', $version)
                ->update(['extraction_status' => ExtractionStatus::PENDING->value, 'extraction_error_code' => $errorCode, 'updated_at' => now()]);

            return ['status' => ExtractionStatus::PENDING, 'retryable' => true, 'error_code' => $errorCode, 'final_url' => null];
        }

        $this->finish($link, $version, $status, null, $errorCode ?? 'UNKNOWN_ERROR');

        return ['status' => $status, 'retryable' => false, 'error_code' => $errorCode, 'final_url' => null];
    }

    /** Called by the job once retries are exhausted. */
    public function markFailed(Link $link, int $version, ?string $errorCode): void
    {
        $this->finish($link, $version, ExtractionStatus::FAILED, null, $errorCode ?? 'RETRIES_EXHAUSTED');
    }

    private function finish(Link $link, int $version, ExtractionStatus $status, ?ExtractedMetadata $m, ?string $errorCode): void
    {
        $values = [
            'extraction_status' => $status->value,
            'extraction_error_code' => $errorCode,
            'last_extracted_at' => now(),
            'updated_at' => now(),
        ];

        if ($m !== null) {
            $values += [
                'title' => $m->title,
                'description' => $m->description,
                'image_url' => $m->imageUrl,
                'favicon_url' => $m->faviconUrl,
                'canonical_url' => $m->canonicalUrl,
                'site_name' => $m->siteName,
                'language' => $m->language,
                'author' => $m->author,
                'published_at' => $m->publishedAt,
                'content_type' => $m->contentType !== null ? mb_substr($m->contentType, 0, 120) : null,
                'text_excerpt' => $m->textExcerpt,
                'metadata' => json_encode(['extractor' => $m->extractor] + $m->extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
            if ($m->sourceType !== null) {
                $values['source_type'] = $m->sourceType->value;
            }
        }

        DB::table('links')->where('id', $link->id)->where('extraction_version', $version)->update($values);
    }

    /** @param array<string, mixed> $diagnostics */
    private function record(Link $link, int $version, int $attempt, string $status, ?string $extractor, ?int $httpStatus, ?string $errorCode, array $diagnostics): void
    {
        LinkExtractionJob::query()->create([
            'link_id' => $link->id,
            'extraction_version' => $version,
            'attempt' => $attempt,
            'status' => $status,
            'extractor' => $extractor,
            'http_status' => $httpStatus,
            'error_code' => $errorCode,
            'diagnostics' => $diagnostics,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }
}
