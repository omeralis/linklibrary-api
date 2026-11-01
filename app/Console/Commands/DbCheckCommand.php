<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only database diagnostics: connectivity, server version, target database and (optionally)
 * the presence of every table the application expects. Never modifies anything.
 */
class DbCheckCommand extends Command
{
    protected $signature = 'linklibrary:db-check {--tables : Also verify that all expected tables exist}';

    protected $description = 'Verify the PostgreSQL connection (read-only) and optionally the expected schema';

    /** @var list<string> */
    public const EXPECTED_TABLES = [
        'audit_logs', 'cache', 'cache_locks', 'categories', 'collection_items', 'collections', 'failed_jobs',
        'job_batches', 'jobs', 'link_access_grants', 'link_ai_analyses', 'link_extraction_jobs', 'link_invitations',
        'link_security_appeals', 'link_security_scans', 'link_views', 'links', 'migrations', 'notifications',
        'password_reset_tokens', 'personal_access_tokens', 'saved_link_tags', 'saved_links', 'sessions', 'tags', 'users',
    ];

    public function handle(): int
    {
        $connection = (string) config('database.default');
        $cfg = (array) config("database.connections.{$connection}");
        $this->line('Environment : '.app()->environment());
        $this->line("Connection  : {$connection} ({$cfg['driver']})");
        $this->line('Target      : '.($cfg['host'] ?? '?').':'.($cfg['port'] ?? '?').'/'.($cfg['database'] ?? '?').' as '.($cfg['username'] ?? '?'));

        try {
            $row = DB::selectOne('select current_database() as db, current_user as usr, version() as ver');
        } catch (Throwable $e) {
            $this->error('Connection FAILED: '.preg_replace('/password=[^;\s]+/i', 'password=***', $e->getMessage()));

            return self::FAILURE;
        }

        $this->info("Connected   : database={$row->db} user={$row->usr}");
        $this->line('Server      : '.strtok((string) $row->ver, ','));

        foreach (['arabic', 'english', 'simple'] as $ts) {
            $ok = DB::selectOne('select count(*) as c from pg_ts_config where cfgname = ?', [$ts])->c > 0;
            $this->line("FTS config  : {$ts} ".($ok ? 'OK' : 'MISSING'));
            if (! $ok) {
                return self::FAILURE;
            }
        }

        if (! $this->option('tables')) {
            return self::SUCCESS;
        }

        $actual = collect(Schema::getTableListing(schema: 'public', schemaQualified: false))->map(fn ($t) => (string) $t)->all();
        $missing = array_values(array_diff(self::EXPECTED_TABLES, $actual));
        $this->line('Tables      : '.count($actual).' present, '.count(self::EXPECTED_TABLES).' expected');
        if ($missing !== []) {
            $this->error('Missing     : '.implode(', ', $missing));

            return self::FAILURE;
        }
        $this->info('Schema      : all expected tables exist');
        $pending = DB::table('migrations')->count();
        $this->line("Migrations  : {$pending} recorded");
        foreach (['users', 'categories', 'links', 'saved_links'] as $t) {
            $this->line(str_pad("Rows {$t}", 12).': '.DB::table($t)->count());
        }

        return self::SUCCESS;
    }
}
