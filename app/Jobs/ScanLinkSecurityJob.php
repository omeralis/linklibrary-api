<?php

namespace App\Jobs;

use App\Models\LinkSecurityScan;
use App\Services\Security\SecurityScanService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ScanLinkSecurityJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $scanId)
    {
        $this->onQueue('security');
    }

    public function handle(SecurityScanService $service): void
    {
        $scan = LinkSecurityScan::query()->find($this->scanId);
        if ($scan === null) {
            return;
        }
        $service->runScan($scan);
    }

    public function failed(?Throwable $e): void
    {
        $scan = LinkSecurityScan::query()->find($this->scanId);
        if ($scan !== null) {
            app(SecurityScanService::class)->markScanFailed($scan, 'JOB_FAILED');
        }
    }
}
