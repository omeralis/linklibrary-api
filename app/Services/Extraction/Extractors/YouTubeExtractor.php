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

/**
 * YouTube videos, shorts and playlists via the official oEmbed endpoint (no key required), optionally
 * enriched by the YouTube Data API v3 when YOUTUBE_API_KEY is configured.
 */
class YouTubeExtractor implements Extractor
{
    private const HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtu.be'];

    public function __construct(private readonly SafeHttpClient $client, private readonly ?string $apiKey = null) {}

    public function name(): string
    {
        return 'youtube';
    }

    public function supports(NormalizedUrl $url): bool
    {
        return in_array($url->host, self::HOSTS, true) && $this->identify($url) !== null;
    }

    /** @return array{type: string, id: string}|null */
    public function identify(NormalizedUrl $url): ?array
    {
        parse_str((string) $url->query, $q);
        if ($url->host === 'youtu.be') {
            $id = trim($url->path, '/');

            return preg_match('/^[\w\-]{6,20}$/', $id) === 1 ? ['type' => 'video', 'id' => $id] : null;
        }
        if ($url->path === '/playlist' && isset($q['list']) && is_string($q['list'])) {
            return ['type' => 'playlist', 'id' => $q['list']];
        }
        if ($url->path === '/watch' && isset($q['v']) && is_string($q['v'])) {
            return ['type' => 'video', 'id' => $q['v']];
        }
        if (preg_match('#^/(shorts|live|embed)/([\w\-]{6,20})#', $url->path, $m) === 1) {
            return ['type' => 'video', 'id' => $m[2]];
        }

        return null;
    }

    public function extract(NormalizedUrl $url): ExtractedMetadata
    {
        $ident = $this->identify($url);
        $isPlaylist = ($ident['type'] ?? null) === 'playlist';

        $response = $this->client->get(
            'https://www.youtube.com/oembed?format=json&url='.rawurlencode($url->normalized),
            ['Accept' => 'application/json'],
            256 * 1024,
        );
        if (in_array($response->status, [401, 403], true)) {
            throw new ExtractionException('ACCESS_RESTRICTED', false, $response->status);
        }
        if ($response->status === 404 || $response->status === 400) {
            throw new ExtractionException('NOT_FOUND', false, $response->status);
        }
        if (! $response->isSuccessful()) {
            throw new ExtractionException('HTTP_'.$response->status, $response->status >= 500 || $response->status === 429, $response->status);
        }
        $data = json_decode($response->body, true);
        if (! is_array($data)) {
            throw new ExtractionException('INVALID_RESPONSE', true);
        }

        $result = new ExtractedMetadata(
            'youtube',
            title: TextUtil::clean($data['title'] ?? null, 300),
            imageUrl: is_string($data['thumbnail_url'] ?? null) ? $data['thumbnail_url'] : null,
            faviconUrl: 'https://www.youtube.com/favicon.ico',
            siteName: 'YouTube',
            author: TextUtil::clean($data['author_name'] ?? null, 255),
            contentType: 'text/html',
            sourceType: $isPlaylist ? SourceType::PLAYLIST : SourceType::VIDEO,
            extra: array_filter([
                'youtube_type' => $ident['type'] ?? null,
                'youtube_id' => $ident['id'] ?? null,
                'author_url' => is_string($data['author_url'] ?? null) ? $data['author_url'] : null,
            ]),
            httpStatus: $response->status,
        );

        if ($this->apiKey !== null && $this->apiKey !== '' && $ident !== null) {
            $this->enrich($result, $ident);
        }

        $result->language ??= TextUtil::detectLanguage(($result->title ?? '').' '.($result->description ?? ''));

        return $result;
    }

    /** @param array{type: string, id: string} $ident */
    private function enrich(ExtractedMetadata $result, array $ident): void
    {
        $endpoint = $ident['type'] === 'playlist' ? 'playlists' : 'videos';
        try {
            $response = $this->client->get(
                'https://www.googleapis.com/youtube/v3/'.$endpoint.'?part=snippet&id='.rawurlencode($ident['id']).'&key='.rawurlencode((string) $this->apiKey),
                ['Accept' => 'application/json'],
                512 * 1024,
            );
            $data = json_decode($response->body, true);
            $snippet = $data['items'][0]['snippet'] ?? null;
            if (! is_array($snippet)) {
                return;
            }
            $result->description = TextUtil::clean($snippet['description'] ?? null, 1000) ?? $result->description;
            if (isset($snippet['publishedAt'])) {
                $result->publishedAt = CarbonImmutable::parse((string) $snippet['publishedAt']);
            }
            $result->language = TextUtil::normalizeLanguage($snippet['defaultAudioLanguage'] ?? $snippet['defaultLanguage'] ?? null) ?? $result->language;
        } catch (Throwable) {
            // Optional enrichment; oEmbed data remains valid.
        }
    }
}
