<?php

use App\Jobs\CleanupExpiredInvitationsJob;
use App\Jobs\PruneProcessingRecordsJob;
use App\Jobs\RecalculatePopularityJob;
use App\Jobs\RefreshStaleMetadataJob;
use App\Jobs\RescanDueLinksJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

/*
| Scheduler — run `php artisan schedule:work` (dev) or a cron entry
| `* * * * * php /path/artisan schedule:run` (production) plus queue workers.
*/

Schedule::job(new RescanDueLinksJob, 'security')->hourly()->withoutOverlapping()->onOneServer();
Schedule::job(new RefreshStaleMetadataJob, 'extraction')->dailyAt('03:15')->withoutOverlapping()->onOneServer();
Schedule::job(new CleanupExpiredInvitationsJob)->hourlyAt(20)->onOneServer();
Schedule::job(new PruneProcessingRecordsJob)->dailyAt('04:30')->onOneServer();
Schedule::job(new RecalculatePopularityJob)->everyThirtyMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('sanctum:prune-expired --hours=24')->daily()->onOneServer();
Schedule::command('auth:clear-resets')->everyFifteenMinutes()->onOneServer();

// Heartbeat read by `php artisan linklibrary:doctor` to confirm the scheduler is running.
Schedule::call(fn () => Cache::put('scheduler:heartbeat', now()->toIso8601String(), 3600))->everyMinute()->name('scheduler-heartbeat');
