<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Removes obsolete operational records while keeping everything referenced by live data.
 */
class PruneProcessingRecordsJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $r = config('linklibrary.retention');

        DB::table('link_extraction_jobs')->where('created_at', '<', now()->subDays((int) $r['extraction_jobs_days']))->delete();

        DB::table('link_security_scans')
            ->where('created_at', '<', now()->subDays((int) $r['security_scans_days']))
            ->whereNotIn('id', DB::table('links')->whereNotNull('last_security_scan_id')->select('last_security_scan_id'))
            ->delete();

        DB::table('link_views')->where('viewed_on', '<', now()->subDays((int) $r['link_views_days'])->toDateString())->delete();

        DB::table('link_ai_analyses')
            ->where('created_at', '<', now()->subDays(30))
            ->whereIn('status', ['FAILED', 'SKIPPED', 'PROCESSING'])
            ->whereNotIn('id', DB::table('links')->whereNotNull('current_ai_analysis_id')->select('current_ai_analysis_id'))
            ->whereNotIn('id', DB::table('saved_links')->whereNotNull('ai_applied_analysis_id')->select('ai_applied_analysis_id'))
            ->delete();

        // Canonical links nobody references any more (not even soft-deleted saved links).
        DB::table('links')
            ->where('updated_at', '<', now()->subDays(30))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('saved_links')->whereColumn('saved_links.link_id', 'links.id'))
            ->delete();
    }
}
