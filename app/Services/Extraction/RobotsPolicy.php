<?php

namespace App\Services\Extraction;

use App\Services\Http\FetchException;
use App\Services\Http\SafeHttpClient;
use App\Services\Url\NormalizedUrl;
use Illuminate\Support\Facades\Cache;

/**
 * robots.txt evaluation (RFC 9309): group for our product token or "*", longest-match Allow/Disallow.
 * 4xx robots.txt means "allowed"; network/5xx errors mean "disallowed for now" (retried later).
 */
class RobotsPolicy
{
    public function __construct(
        private readonly SafeHttpClient $client,
        private readonly string $agent,
        private readonly int $cacheTtl = 86400,
    ) {}

    /**
     * @throws ExtractionException ROBOTS_UNAVAILABLE (retryable) when robots.txt cannot be obtained
     *                             because of a network or server error — RFC 9309 treats that as
     *                             "disallow for now", so the extraction is retried later.
     */
    public function isAllowed(NormalizedUrl $url): bool
    {
        $key = 'robots:'.hash('sha256', $url->origin());
        $rules = Cache::get($key);
        if (! is_array($rules)) {
            $rules = $this->fetchRules($url);
            Cache::put($key, $rules, $this->cacheTtl);
        }

        return self::evaluate($rules, $url->pathAndQuery());
    }

    /**
     * @return list<array{0: bool, 1: string}>
     */
    private function fetchRules(NormalizedUrl $url): array
    {
        try {
            $response = $this->client->get($url->origin().'/robots.txt', ['Accept' => 'text/plain'], 512 * 1024);
        } catch (FetchException $e) {
            if ($e->retryable) {
                throw new ExtractionException('ROBOTS_UNAVAILABLE', true);
            }

            return []; // e.g. robots.txt redirects somewhere we refuse to follow: no rules
        }

        if ($response->status >= 500 || $response->status === 429) {
            throw new ExtractionException('ROBOTS_UNAVAILABLE', true, $response->status);
        }
        if ($response->status >= 400) {
            return []; // "unavailable" (4xx): crawling allowed
        }

        return self::parse($response->body, $this->agent);
    }

    /**
     * @return list<array{0: bool, 1: string}>
     */
    public static function parse(string $content, string $agent): array
    {
        $agent = strtolower($agent);
        $groups = [];
        $currentAgents = [];
        $currentRules = [];
        $lastWasAgent = false;

        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                if (! $lastWasAgent && $currentAgents !== []) {
                    $groups[] = [$currentAgents, $currentRules];
                    $currentAgents = [];
                    $currentRules = [];
                }
                $currentAgents[] = strtolower($value);
                $lastWasAgent = true;

                continue;
            }
            $lastWasAgent = false;
            if ($field === 'allow' || $field === 'disallow') {
                if ($value === '' && $field === 'disallow') {
                    continue; // empty disallow = allow everything
                }
                $currentRules[] = [$field === 'allow', $value];
            }
        }
        if ($currentAgents !== []) {
            $groups[] = [$currentAgents, $currentRules];
        }

        $specific = [];
        $wildcard = [];
        foreach ($groups as [$agents, $rules]) {
            foreach ($agents as $a) {
                if ($a === '*') {
                    $wildcard = array_merge($wildcard, $rules);
                } elseif ($a !== '' && str_contains($agent, $a)) {
                    $specific = array_merge($specific, $rules);
                }
            }
        }

        return $specific !== [] ? $specific : $wildcard;
    }

    /** @param list<array{0: bool, 1: string}> $rules */
    public static function evaluate(array $rules, string $path): bool
    {
        $best = null;
        $bestLen = -1;
        foreach ($rules as [$allow, $pattern]) {
            if (self::matches($pattern, $path)) {
                $len = strlen($pattern);
                if ($len > $bestLen || ($len === $bestLen && $allow)) {
                    $best = $allow;
                    $bestLen = $len;
                }
            }
        }

        return $best ?? true;
    }

    private static function matches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        $pattern = $anchored ? substr($pattern, 0, -1) : $pattern;
        $regex = '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).($anchored ? '$' : '').'/';

        return preg_match($regex, $path) === 1;
    }
}
