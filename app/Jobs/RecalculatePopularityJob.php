<?php

namespace App\Jobs;

use App\Services\Discovery\DiscoveryCache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Abuse-resistant popularity: unique viewers per day (one count per viewer fingerprint per day) over a
 * window, plus 3 points per *other* active account that saved the same canonical URL. Repeated views
 * by the same visitor, or by the owner, do not inflate the score.
 */
class RecalculatePopularityJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public function handle(DiscoveryCache $cache): void
    {
        $since = now()->subDays((int) config('linklibrary.discovery.popularity_window_days', 30))->toDateString();

        DB::update(<<<'SQL'
            UPDATE saved_links s SET popularity_score = LEAST(2000000000,
                COALESCE((SELECT COUNT(*) FROM link_views v WHERE v.saved_link_id = s.id AND v.viewed_on >= ?), 0)
              + 3 * COALESCE((
                    SELECT COUNT(DISTINCT o.user_id) FROM saved_links o
                    JOIN users u ON u.id = o.user_id AND u.status = 'ACTIVE'
                    WHERE o.link_id = s.link_id AND o.user_id <> s.user_id AND o.deleted_at IS NULL
                ), 0))
            WHERE s.visibility = 'PUBLIC' AND s.deleted_at IS NULL
        SQL, [$since]);

        $cache->invalidate();
    }
}
