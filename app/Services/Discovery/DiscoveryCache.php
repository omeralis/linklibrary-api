<?php

namespace App\Services\Discovery;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Cache for anonymous public discovery responses ONLY. Keys are namespaced by a global version that is
 * bumped whenever visibility, security status, deletion or account status changes, so stale public
 * entries disappear immediately. Never use this for authenticated or private data.
 */
class DiscoveryCache
{
    private const VERSION_KEY = 'discovery:version';

    public function remember(string $key, Closure $callback): mixed
    {
        $ttl = (int) config('linklibrary.discovery.cache_ttl', 60);
        if ($ttl <= 0) {
            return $callback();
        }

        return Cache::remember('discovery:v'.$this->version().':'.$key, $ttl, $callback);
    }

    public function invalidate(): void
    {
        if (! Cache::has(self::VERSION_KEY)) {
            Cache::forever(self::VERSION_KEY, 1);
        }
        Cache::increment(self::VERSION_KEY);
    }

    private function version(): int
    {
        return (int) Cache::rememberForever(self::VERSION_KEY, fn () => 1);
    }
}
