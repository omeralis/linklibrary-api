<?php

namespace App\Console\Commands;

use App\Services\Ai\AiProvider;
use App\Services\Http\FetchException;
use App\Services\Http\SafeHttpClient;
use App\Services\Security\ReputationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-only runtime readiness report for local development and deployments:
 * PHP, database, queue worker, scheduler, mail, outbound HTTPS, threat-intel and AI providers,
 * storage link and frontend/CORS settings. Never modifies data.
 */
class DoctorCommand extends Command
{
    protected $signature = 'linklibrary:doctor {--network : Also perform one real HTTPS request through the SSRF-safe client}';

    protected $description = 'Read-only readiness report (database, queue, scheduler, mail, providers, frontend settings)';

    private int $warnings = 0;

    private int $failures = 0;

    public function handle(): int
    {
        $this->section('Runtime');
        $this->ok('PHP '.PHP_VERSION.' / Laravel '.app()->version().' / env='.app()->environment());
        $this->check(config('app.key') !== null && config('app.key') !== '', 'APP_KEY set', 'APP_KEY missing (php artisan key:generate)');
        $this->check(! (app()->isProduction() && config('app.debug')), 'APP_DEBUG appropriate', 'APP_DEBUG=true in production', fail: true);

        $this->section('Database');
        try {
            $row = DB::selectOne('select current_database() as db, version() as v');
            $this->ok("connected to {$row->db} (".strtok((string) $row->v, ',').')');
            $pending = $this->pendingMigrations();
            $this->check($pending === 0, 'no pending migrations', "{$pending} pending migration(s): php artisan migrate");
        } catch (Throwable $e) {
            $this->bad('database unreachable: '.class_basename($e));
        }

        $this->section('Queue (required: security scans, extraction, AI, e-mail)');
        $connection = (string) config('queue.default');
        $this->ok("QUEUE_CONNECTION={$connection}");
        if ($connection === 'sync') {
            $this->warn_('sync queue: POST /links will block on remote fetching; use database/redis + a worker');
        } elseif ($connection === 'database') {
            try {
                $stale = DB::table('jobs')->where('available_at', '<', now()->subMinutes(2)->getTimestamp())->whereNull('reserved_at')->count();
                $failed = DB::table('failed_jobs')->count();
                $this->check($stale === 0, 'no jobs waiting longer than 2 minutes', "{$stale} job(s) waiting > 2 min — is `php artisan queue:work --queue=security,extraction,ai,default` running?");
                $this->check($failed === 0, 'no failed jobs', "{$failed} failed job(s): php artisan queue:failed");
            } catch (Throwable) {
                $this->bad('jobs tables missing');
            }
        }

        $this->section('Scheduler');
        $beat = Cache::get('scheduler:heartbeat');
        $this->check(
            is_string($beat) && now()->diffInMinutes(Carbon::parse($beat), true) <= 5,
            'scheduler heartbeat '.($beat ?? ''),
            'no scheduler heartbeat in the last 5 minutes — run `php artisan schedule:work` (dev) or cron `schedule:run` (prod)',
        );

        $this->section('Mail');
        $mailer = (string) config('mail.default');
        $this->ok("MAIL_MAILER={$mailer}, from=".config('mail.from.address'));
        if (in_array($mailer, ['log', 'array'], true)) {
            $this->warn_("mail is not delivered ({$mailer}); verification/reset/invitation links are only written to the log. Use Mailpit/SMTP to test e-mail flows.");
        }

        $this->section('Outbound HTTPS (metadata extraction)');
        $ca = ini_get('curl.cainfo') ?: ini_get('openssl.cafile');
        $this->check(
            $ca !== false && $ca !== '' || PHP_OS_FAMILY !== 'Windows',
            'CA bundle configured'.($ca ? " ({$ca})" : ''),
            'curl.cainfo / openssl.cafile not set in php.ini — HTTPS extraction will fail on Windows (download cacert.pem and set curl.cainfo)',
        );
        if ($this->option('network')) {
            try {
                $response = app(SafeHttpClient::class)->get('https://example.com/', [], 256 * 1024);
                $this->check($response->status < 500, "HTTPS fetch OK (status {$response->status})", "HTTPS fetch returned {$response->status}");
            } catch (FetchException $e) {
                $this->bad('HTTPS fetch failed: '.$e->errorCode);
            }
        }

        $this->section('Threat intelligence');
        $reputation = app(ReputationService::class);
        $this->ok('SECURITY_PROVIDERS='.implode(',', (array) config('linklibrary.security.providers')));
        $this->check(
            $reputation->hasAuthoritativeProvider(),
            'authoritative provider configured (links can become CLEAR)',
            'no authoritative provider: every link stays UNKNOWN → cannot be published or shared. Configure GOOGLE_SAFE_BROWSING_API_KEY / GOOGLE_WEB_RISK_API_KEY, or locally use `php artisan linklibrary:dev-mark-clear`.',
        );

        $this->section('AI (optional)');
        $ai = app(AiProvider::class);
        $ai->isConfigured()
            ? $this->ok("AI provider {$ai->name()} ({$ai->model()})")
            : $this->line('  - AI disabled (AI_PROVIDER=null) — app fully functional without AI');

        $this->section('Frontend / cookies');
        $frontend = (string) config('linklibrary.frontend.url');
        $this->ok("FRONTEND_URL={$frontend}");
        $origins = (array) config('cors.allowed_origins');
        $this->check(in_array($frontend, $origins, true), 'frontend origin allowed by CORS', 'FRONTEND_URL is not in CORS_ALLOWED_ORIGINS ('.implode(',', $origins).')', fail: true);
        $frontendHost = (string) parse_url($frontend, PHP_URL_HOST).(parse_url($frontend, PHP_URL_PORT) ? ':'.parse_url($frontend, PHP_URL_PORT) : '');
        $this->check(in_array($frontendHost, (array) config('sanctum.stateful'), true), "{$frontendHost} is a Sanctum stateful domain", "{$frontendHost} missing from SANCTUM_STATEFUL_DOMAINS (cookie login will not work)", fail: true);
        $apiHost = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $sameHost = $apiHost === parse_url($frontend, PHP_URL_HOST);
        $this->check($sameHost || config('session.domain') !== null, 'session cookie domain compatible', "API host {$apiHost} differs from frontend host; set SESSION_DOMAIN to the shared parent domain");
        $this->check(! app()->isProduction() || config('session.secure') === true, 'secure cookies', 'SESSION_SECURE_COOKIE must be true in production', fail: true);
        $this->check(is_link(public_path('storage')) || is_dir(public_path('storage')), 'public/storage link present', 'public/storage missing — avatars/covers will 404 (php artisan storage:link)');

        $this->newLine();
        $summary = "Doctor finished: {$this->failures} failure(s), {$this->warnings} warning(s).";
        $this->failures > 0 ? $this->error($summary) : $this->info($summary);

        return $this->failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function pendingMigrations(): int
    {
        Artisan::call('migrate:status', ['--pending' => true, '--no-ansi' => true]);

        return substr_count(Artisan::output(), 'Pending');
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line("<options=bold>{$title}</>");
    }

    private function check(bool $condition, string $okMessage, string $problem, bool $fail = false): void
    {
        if ($condition) {
            $this->ok($okMessage);
        } elseif ($fail) {
            $this->bad($problem);
        } else {
            $this->warn_($problem);
        }
    }

    private function ok(string $m): void
    {
        $this->line("  <fg=green>✓</> {$m}");
    }

    private function warn_(string $m): void
    {
        $this->warnings++;
        $this->line("  <fg=yellow>!</> {$m}");
    }

    private function bad(string $m): void
    {
        $this->failures++;
        $this->line("  <fg=red>✗</> {$m}");
    }
}
