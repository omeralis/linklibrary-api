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

/** Vimeo via the official oEmbed endpoint. */
class VimeoExtractor implements Extractor
{
    public function __construct(private readonly SafeHttpClient $client) {}

    public function name(): string
    {
        return 'vimeo';
    }

    public function supports(NormalizedUrl $url): bool
    {
        return in_array($url->host, ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'], true)
            && preg_match('#/(video/)?\d+#', $url->path) === 1;
    }

    public function extract(NormalizedUrl $url): ExtractedMetadata
    {
        $response = $this->client->get('https://vimeo.com/api/oembed.json?url='.rawurlencode($url->normalized), ['Accept' => 'application/json'], 256 * 1024);
        if (in_array($response->status, [401, 403], true)) {
            throw new ExtractionException('ACCESS_RESTRICTED', false, $response->status);
        }
        if (! $response->isSuccessful()) {
            throw new ExtractionException('HTTP_'.$response->status, $response->status >= 500 || $response->status === 429, $response->status);
        }
        $data = json_decode($response->body, true);
        if (! is_array($data)) {
            throw new ExtractionException('INVALID_RESPONSE', true);
        }

        $published = null;
        try {
            $published = isset($data['upload_date']) ? CarbonImmutable::parse((string) $data['upload_date']) : null;
        } catch (Throwable) {
        }

        $result = new ExtractedMetadata(
            'vimeo',
            title: TextUtil::clean($data['title'] ?? null, 300),
            description: TextUtil::clean($data['description'] ?? null, 1000),
            imageUrl: is_string($data['thumbnail_url'] ?? null) ? $data['thumbnail_url'] : null,
            faviconUrl: 'https://vimeo.com/favicon.ico',
            siteName: 'Vimeo',
            author: TextUtil::clean($data['author_name'] ?? null, 255),
            publishedAt: $published,
            contentType: 'text/html',
            sourceType: SourceType::VIDEO,
            extra: array_filter(['duration_seconds' => isset($data['duration']) ? (int) $data['duration'] : null, 'vimeo_id' => $data['video_id'] ?? null]),
            httpStatus: $response->status,
        );
        $result->language = TextUtil::detectLanguage(($result->title ?? '').' '.($result->description ?? ''));

        return $result;
    }
}
