<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/** Private deployment gate, installed outside the public release. Never prints config values or exception messages. */
function llDeploymentReadiness(array $config, callable $databaseProbe): array
{
    $origin = $config['app_url'] ?? '';
    $key = $config['key'] ?? '';
    $decoded = is_string($key) && str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : false;
    $host = parse_url($origin, PHP_URL_HOST);
    $port = parse_url($origin, PHP_URL_PORT);
    $stateful = $host ? $host.($port ? ':'.$port : '') : '';
    $checks = [
        'environment' => ($config['environment'] ?? '') === 'production' && ($config['debug'] ?? true) === false,
        'key' => is_string($decoded) && strlen($decoded) === 32,
        'database_config' => ($config['connection'] ?? '') === 'pgsql'
            && ($config['database'] ?? '') === 'linklibrary_prod' && ($config['username'] ?? '') === 'linklibrary_app',
        'cookies' => ($config['secure'] ?? false) === true && ($config['http_only'] ?? false) === true
            && ($config['same_site'] ?? '') === 'lax' && ($config['session_driver'] ?? '') === 'database',
        'origins' => parse_url($origin, PHP_URL_SCHEME) === 'https' && $host
            && ($config['frontend_url'] ?? '') === $origin && ($config['cors'] ?? []) === [$origin]
            && ($config['stateful'] ?? []) === [$stateful],
        'runtime_stores' => ($config['cache'] ?? '') === 'database' && ($config['queue'] ?? '') === 'database'
            && ($config['retry_after'] ?? 0) > 120,
        // Strict policy, or the approved temporary policy (unverified sharing ONLY while scanning is explicitly "Coming Soon").
        'security_policy' => is_bool($config['allow_unverified'] ?? null) && is_bool($config['scanning_coming_soon'] ?? null)
            && ($config['allow_unverified'] === false || $config['scanning_coming_soon'] === true),
        'database_runtime' => false,
    ];
    try {
        $row = $databaseProbe();
        $checks['database_runtime'] = ($row->db ?? '') === 'linklibrary_prod' && ($row->usr ?? '') === 'linklibrary_app';
    } catch (Throwable) {
        // Diagnostic identifiers only: connection exceptions can contain credentials/SQL.
    }

    return ['ready' => ! in_array(false, $checks, true), 'checks' => $checks];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $api = realpath($argv[1] ?? '');
        if ($api === false || ! preg_match('#^/srv/linklibrary/releases/[0-9a-z-]+/api$#D', $api)) {
            exit(2);
        }
        require $api.'/vendor/autoload.php';
        $app = require $api.'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $result = llDeploymentReadiness([
            'environment' => app()->environment(), 'debug' => config('app.debug'), 'key' => config('app.key'),
            'connection' => config('database.default'), 'database' => config('database.connections.pgsql.database'),
            'username' => config('database.connections.pgsql.username'), 'secure' => config('session.secure'),
            'http_only' => config('session.http_only'), 'same_site' => config('session.same_site'),
            'session_driver' => config('session.driver'), 'app_url' => config('app.url'),
            'frontend_url' => config('linklibrary.frontend.url'), 'cors' => config('cors.allowed_origins'),
            'stateful' => config('sanctum.stateful'), 'cache' => config('cache.default'),
            'queue' => config('queue.default'), 'retry_after' => config('queue.connections.database.retry_after'),
            'allow_unverified' => config('linklibrary.security.allow_unverified_sharing', false),
            'scanning_coming_soon' => config('linklibrary.security.scanning_coming_soon', false),
        ], fn () => DB::selectOne('select current_database() db,current_user usr'));
        echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
        exit($result['ready'] ? 0 : 1);
    } catch (Throwable) {
        echo '{"ready":false,"checks":{"bootstrap":false}}'.PHP_EOL;
        exit(1);
    }
}
