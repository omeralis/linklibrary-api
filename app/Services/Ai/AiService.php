<?php

namespace App\Services\Ai;

use App\Enums\AiStatus;
use App\Enums\ExtractionStatus;
use App\Models\Category;
use App\Models\Link;
use App\Models\LinkAiAnalysis;
use App\Models\SavedLink;
use App\Services\Links\TagService;
use App\Services\Search\SearchIndexer;
use App\Services\Security\SecurityScanService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AI enrichment of canonical links.
 *
 *  - Disabled cleanly when no provider is configured (status DISABLED) — link management is unaffected.
 *  - Input is built from extracted public content only (AiInput); private data is never sent.
 *  - Output is validated (AiOutputValidator); invalid output is recorded as a failure, never stored.
 *  - Budget: daily request cap; identical inputs are not re-analysed.
 *  - Stale jobs (ai_version mismatch) never overwrite newer results.
 *  - Suspicion signals may only escalate the security status (never mark safe).
 */
class AiService
{
    public function __construct(
        private readonly AiProvider $provider,
        private readonly AiOutputValidator $validator,
        private readonly TagService $tags,
        private readonly SearchIndexer $indexer,
        private readonly SecurityScanService $security,
        private readonly AiQuotaGuard $quota,
        private readonly int $maxInputChars,
    ) {}

    public function isEnabled(): bool
    {
        return $this->provider->isConfigured();
    }

    /**
     * @return array{status: AiStatus, retryable: bool, error_code: ?string, retry_at?: Carbon}
     */
    public function analyze(Link $link, int $version): array
    {
        $link->refresh();
        if ($link->ai_version !== $version) {
            return ['status' => $link->ai_status, 'retryable' => false, 'error_code' => 'STALE_JOB'];
        }

        if (! $this->provider->isConfigured()) {
            $this->setStatus($link, $version, AiStatus::DISABLED);

            return ['status' => AiStatus::DISABLED, 'retryable' => false, 'error_code' => null];
        }

        if ($link->security_status->isUnsafe()
            || ! in_array($link->extraction_status, [ExtractionStatus::COMPLETED, ExtractionStatus::PARTIAL], true)) {
            $this->setStatus($link, $version, AiStatus::SKIPPED);

            return ['status' => AiStatus::SKIPPED, 'retryable' => false, 'error_code' => 'NOT_ELIGIBLE'];
        }

        $input = $this->buildInput($link);
        if (! $input->hasContent()) {
            $this->setStatus($link, $version, AiStatus::SKIPPED);

            return ['status' => AiStatus::SKIPPED, 'retryable' => false, 'error_code' => 'NO_CONTENT'];
        }

        $hash = $input->hash();
        $current = $link->currentAiAnalysis;
        if ($current !== null && $current->status === 'COMPLETED' && $current->input_hash === $hash) {
            $this->setStatus($link, $version, AiStatus::COMPLETED);

            return ['status' => AiStatus::COMPLETED, 'retryable' => false, 'error_code' => null];
        }

        // Free-tier protection: when the local budget (rpm / daily cap) or a provider 429 says "not now", the
        // link stays PENDING and the job is re-scheduled for the moment the quota resets. Nothing is skipped
        // permanently and no other (paid) provider is tried.
        if (($retryAt = $this->quota->reserve()) !== null) {
            return $this->waitForQuota($retryAt);
        }

        Link::query()->whereKey($link->id)->where('ai_version', $version)->update(['ai_status' => AiStatus::PROCESSING->value]);

        $analysis = LinkAiAnalysis::query()->create([
            'link_id' => $link->id,
            'ai_version' => $version,
            'status' => 'PROCESSING',
            'provider' => $this->provider->name(),
            'model' => $this->provider->model(),
            'input_hash' => $hash,
        ]);

        try {
            [$ran, $raw] = $this->quota->exclusive(fn () => $this->provider->analyze($input));
            if (! $ran) {
                // Another AI request is in flight (concurrency limit 1): try again shortly, without using an attempt.
                $analysis->delete();
                $this->setStatus($link, $version, AiStatus::PENDING);

                return $this->waitForQuota($raw, 'AI_BUSY');
            }
            $result = $this->validator->validate($raw['data'], $input->allowedCategories, $raw['input_tokens'], $raw['output_tokens']);
        } catch (AiQuotaException $e) {
            $retryAt = $this->quota->markExhausted($e);
            $analysis->delete();
            $this->setStatus($link, $version, AiStatus::PENDING);

            return $this->waitForQuota($retryAt);
        } catch (AiException $e) {
            $analysis->update(['status' => 'FAILED', 'error_code' => $e->errorCode, 'completed_at' => now()]);
            if (! $e->retryable) {
                $this->setStatus($link, $version, AiStatus::FAILED);
            }

            return ['status' => AiStatus::FAILED, 'retryable' => $e->retryable, 'error_code' => $e->errorCode];
        }

        $categoryId = $result->categorySlug !== null
            ? Category::query()->where('slug', $result->categorySlug)->where('is_active', true)->value('id')
            : null;

        $applied = DB::transaction(function () use ($analysis, $result, $categoryId, $link, $version): bool {
            $analysis->update([
                'status' => 'COMPLETED',
                'suggested_title' => $result->suggestedTitle,
                'short_summary' => $result->shortSummary,
                'detailed_summary' => $result->detailedSummary,
                'suggested_category_slug' => $result->categorySlug,
                'category_id' => $categoryId,
                'suggested_tags' => $result->tags,
                'language' => $result->language,
                'content_type' => $result->contentType,
                'suspicious' => $result->suspicious,
                'suspicious_reasons' => $result->suspiciousReasons,
                'input_tokens' => $result->inputTokens,
                'output_tokens' => $result->outputTokens,
                'completed_at' => now(),
            ]);

            $updated = Link::query()->whereKey($link->id)->where('ai_version', $version)->update([
                'ai_status' => AiStatus::COMPLETED->value,
                'current_ai_analysis_id' => $analysis->id,
                'updated_at' => now(),
            ]);

            return $updated === 1;
        });

        if (! $applied) {
            return ['status' => AiStatus::COMPLETED, 'retryable' => false, 'error_code' => 'STALE_JOB'];
        }

        $this->applyToSavedLinks($link, $analysis);

        if ($result->suspicious) {
            $this->security->raiseSuspicion($link, $result->suspiciousReasons);
        }

        return ['status' => AiStatus::COMPLETED, 'retryable' => false, 'error_code' => null];
    }

    public function markFailed(Link $link, int $version): void
    {
        $this->setStatus($link, $version, AiStatus::FAILED);
    }

    public function buildInput(Link $link): AiInput
    {
        return new AiInput(
            host: $link->hostname,
            title: $link->title,
            description: $link->description,
            siteName: $link->site_name,
            excerpt: $link->text_excerpt !== null ? mb_substr($link->text_excerpt, 0, $this->maxInputChars) : null,
            declaredLanguage: $link->language,
            sourceType: $link->source_type->value,
            allowedCategories: Category::query()->where('is_active', true)->orderBy('slug')->pluck('slug')->all(),
        );
    }

    /**
     * Apply the analysis to every saved instance: AI category and AI tags. User overrides (category_id,
     * USER tags, custom fields) are never modified.
     */
    private function applyToSavedLinks(Link $link, LinkAiAnalysis $analysis): void
    {
        SavedLink::withTrashed()->where('link_id', $link->id)->chunkById(100, function ($savedLinks) use ($analysis) {
            foreach ($savedLinks as $savedLink) {
                if ($savedLink->ai_applied_analysis_id === $analysis->id) {
                    continue;
                }
                $savedLink->forceFill([
                    'ai_category_id' => $analysis->category_id,
                    'ai_applied_analysis_id' => $analysis->id,
                ])->save();
                $this->tags->replaceAiTags($savedLink, $analysis->suggested_tags ?? []);
                $this->indexer->reindex($savedLink->fresh() ?? $savedLink);
            }
        });
    }

    private function setStatus(Link $link, int $version, AiStatus $status): void
    {
        Link::query()->whereKey($link->id)->where('ai_version', $version)->update(['ai_status' => $status->value, 'updated_at' => now()]);
    }

    /** @return array{status: AiStatus, retryable: bool, error_code: string, retry_at: Carbon} */
    private function waitForQuota(Carbon $retryAt, string $code = AiQuotaException::CODE): array
    {
        return ['status' => AiStatus::PENDING, 'retryable' => true, 'error_code' => $code, 'retry_at' => $retryAt];
    }
}
