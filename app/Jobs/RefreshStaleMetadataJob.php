<?php

namespace App\Jobs;

use App\Enums\ExtractionStatus;
use App\Models\Link;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;

class RefreshStaleMetadataJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public function handle(): void
    {
        $staleBefore = now()->subDays((int) config('linklibrary.extraction.stale_after_days', 30));

        Link::query()
            ->whereIn('extraction_status', [ExtractionStatus::COMPLETED->value, ExtractionStatus::PARTIAL->value, ExtractionStatus::FAILED->value])
            ->where('last_extracted_at', '<', $staleBefore)
            ->whereNotIn('security_status', ['SUSPICIOUS', 'MALICIOUS'])
            ->whereHas('savedLinks', fn (Builder $q) => $q->whereNull('deleted_at'))
            ->orderBy('last_extracted_at')
            ->limit(100)
            ->get()
            ->each(function (Link $link) {
                $version = $link->extraction_version + 1;
                $updated = Link::query()->whereKey($link->id)->where('extraction_version', $link->extraction_version)
                    ->update(['extraction_version' => $version, 'ai_version' => $link->ai_version + 1, 'updated_at' => now()]);
                if ($updated === 1) {
                    ExtractLinkMetadataJob::dispatch($link->id, $version);
                }
            });
    }
}
