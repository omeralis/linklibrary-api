<?php

namespace App\Services\Extraction\Extractors;

use App\Enums\SourceType;
use App\Services\Extraction\ExtractedMetadata;
use App\Services\Extraction\ExtractionException;
use App\Services\Extraction\Extractor;
use App\Services\Extraction\TextUtil;
use App\Services\Http\SafeHttpClient;
use App\Services\Url\NormalizedUrl;
use Carbon\CarbonImmutable;
use Throwable;

/** GitHub repositories via the official REST API (anonymous or with GITHUB_API_TOKEN). */
class GitHubExtractor implements Extractor
{
    private const RESERVED = ['orgs', 'settings', 'marketplace', 'features', 'topics', 'collections', 'sponsors', 'explore', 'login', 'join', 'about', 'pricing', 'notifications', 'search', 'trending', 'apps', 'enterprise', 'security', 'site', 'events', 'customer-stories', 'readme', 'pulls', 'issues', 'codespaces', 'new'];

    public function __construct(private readonly SafeHttpClient $client, private readonly ?string $token = null) {}

    public function name(): string
    {
        return 'github';
    }

    public function supports(NormalizedUrl $url): bool
    {
        return in_array($url->host, ['github.com', 'www.github.com'], true) && $this->repo($url) !== null;
    }

    /** @return array{0: string, 1: string}|null */
    private function repo(NormalizedUrl $url): ?array
    {
        if (preg_match('#^/([A-Za-z0-9](?:[A-Za-z0-9\-]{0,38}))/([A-Za-z0-9._\-]{1,100})(?:/|$)#', $url->path, $m) !== 1) {
            return null;
        }
        if (in_array(strtolower($m[1]), self::RESERVED, true)) {
            return null;
        }
        $repo = preg_replace('/\.git$/', '', $m[2]) ?? $m[2];

        return [$m[1], $repo];
    }

    public function extract(NormalizedUrl $url): ExtractedMetadata
    {
        [$owner, $repo] = $this->repo($url) ?? throw new ExtractionException('UNSUPPORTED');

        $headers = ['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'];
        if ($this->token !== null && $this->token !== '') {
            $headers['Authorization'] = 'Bearer '.$this->token;
        }

        $response = $this->client->get('https://api.github.com/repos/'.rawurlencode($owner).'/'.rawurlencode($repo), $headers, 512 * 1024);
        if ($response->status === 404) {
            throw new ExtractionException('NOT_FOUND', false, 404); // private or missing repository
        }
        if ($response->status === 403 || $response->status === 429) {
            throw new ExtractionException('RATE_LIMITED', true, $response->status);
        }
        if (! $response->isSuccessful()) {
            throw new ExtractionException('HTTP_'.$response->status, $response->status >= 500, $response->status);
        }
        $data = json_decode($response->body, true);
        if (! is_array($data) || ! isset($data['full_name'])) {
            throw new ExtractionException('INVALID_RESPONSE', true);
        }

        $published = null;
        try {
            $published = isset($data['created_at']) ? CarbonImmutable::parse((string) $data['created_at']) : null;
        } catch (Throwable) {
        }

        $result = new ExtractedMetadata(
            'github',
            title: TextUtil::clean((string) $data['full_name'], 300),
            description: TextUtil::clean($data['description'] ?? null, 1000),
            imageUrl: 'https://opengraph.githubassets.com/1/'.rawurlencode($owner).'/'.rawurlencode($repo),
            faviconUrl: 'https://github.com/favicon.ico',
            canonicalUrl: is_string($data['html_url'] ?? null) ? $data['html_url'] : null,
            siteName: 'GitHub',
            author: TextUtil::clean($data['owner']['login'] ?? null, 255),
            publishedAt: $published,
            contentType: 'text/html',
            sourceType: SourceType::REPOSITORY,
            extra: array_filter([
                'stars' => $data['stargazers_count'] ?? null,
                'forks' => $data['forks_count'] ?? null,
                'primary_language' => $data['language'] ?? null,
                'topics' => is_array($data['topics'] ?? null) ? array_slice($data['topics'], 0, 20) : null,
                'license' => $data['license']['spdx_id'] ?? null,
                'archived' => $data['archived'] ?? null,
                'pushed_at' => $data['pushed_at'] ?? null,
            ], fn ($v) => $v !== null),
            httpStatus: $response->status,
        );
        $result->language = TextUtil::detectLanguage($result->description);

        return $result;
    }
}
