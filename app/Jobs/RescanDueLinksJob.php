<?php

namespace App\Jobs;

use App\Enums\ScanTrigger;
use App\Enums\SecurityStatus;
use App\Models\Link;
use App\Services\Security\SecurityScanService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Periodic rescans: CLEAR verdicts approaching expiry, UNKNOWN verdicts daily, SUSPICIOUS weekly.
 * Only links that are still saved by someone are rescanned.
 */
class RescanDueLinksJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public function handle(SecurityScanService $security): void
    {
        $batch = (int) config('linklibrary.security.rescan_batch_size', 200);
        $before = now()->addHours((int) config('linklibrary.security.rescan_before_expiry_hours', 24));

        Link::query()
            ->whereHas('savedLinks', fn (Builder $q) => $q->whereNull('deleted_at'))
            ->where(function (Builder $q) use ($before) {
                $q->where(fn (Builder $c) => $c->where('security_status', SecurityStatus::CLEAR->value)->where('security_expires_at', '<', $before))
                    ->orWhere(fn (Builder $c) => $c->where('security_status', SecurityStatus::UNKNOWN->value)->where(fn (Builder $d) => $d->whereNull('security_checked_at')->orWhere('security_checked_at', '<', now()->subDay())))
                    ->orWhere(fn (Builder $c) => $c->where('security_status', SecurityStatus::SUSPICIOUS->value)->where('security_source', 'PROVIDER')->where('security_checked_at', '<', now()->subWeek()));
            })
            ->whereDoesntHave('securityScans', fn (Builder $q) => $q->whereIn('status', ['PENDING', 'PROCESSING'])->where('created_at', '>', now()->subHour()))
            ->orderBy('security_expires_at')
            ->limit($batch)
            ->get()
            ->each(fn (Link $link) => $security->requestScan($link, ScanTrigger::PERIODIC));
    }
}
