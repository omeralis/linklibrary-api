<?php

namespace App\Services\Extraction\Extractors;

use App\Enums\SourceType;
use App\Services\Extraction\ExtractedMetadata;
use App\Services\Extraction\ExtractionException;
use App\Services\Extraction\Extractor;
use App\Services\Extraction\HtmlMetadataParser;
use App\Services\Extraction\PdfMetadataParser;
use App\Services\Extraction\RobotsPolicy;
use App\Services\Http\FetchResponse;
use App\Services\Http\SafeHttpClient;
use App\Services\Url\NormalizedUrl;

/**
 * Fetches the resource through the SSRF-safe client (respecting robots.txt) and parses HTML or PDF.
 */
class GenericExtractor implements Extractor
{
    public ?FetchResponse $lastResponse = null;

    public function __construct(
        private readonly SafeHttpClient $client,
        private readonly RobotsPolicy $robots,
        private readonly HtmlMetadataParser $html,
        private readonly PdfMetadataParser $pdf,
        private readonly bool $respectRobots,
        private readonly int $maxPdfBytes,
    ) {}

    public function name(): string
    {
        return 'generic';
    }

    public function supports(NormalizedUrl $url): bool
    {
        return true;
    }

    public function extract(NormalizedUrl $url): ExtractedMetadata
    {
        if ($this->respectRobots && ! $this->robots->isAllowed($url)) {
            throw new ExtractionException('ROBOTS_DISALLOWED');
        }

        $looksPdf = str_ends_with(strtolower($url->path), '.pdf');
        $response = $this->client->get($url, [], $looksPdf ? $this->maxPdfBytes : null);
        $this->lastResponse = $response;

        if (in_array($response->status, [401, 403, 451], true)) {
            // Access-restricted (login, paywall, legal): never attempt to bypass.
            throw new ExtractionException('ACCESS_RESTRICTED', false, $response->status);
        }
        if ($response->status === 429 || $response->status >= 500) {
            throw new ExtractionException('HTTP_'.$response->status, true, $response->status);
        }
        if (! $response->isSuccessful()) {
            throw new ExtractionException('HTTP_'.$response->status, false, $response->status);
        }

        // Redirected to a page our robots policy forbids?
        if ($this->respectRobots && $response->redirectChain !== [] && ! $this->robots->isAllowed($response->finalUrl)) {
            throw new ExtractionException('ROBOTS_DISALLOWED');
        }

        $type = $response->mediaType();
        $body = $response->body;

        if ($type === 'application/pdf' || str_starts_with($body, '%PDF-')) {
            $result = $this->pdf->parse($body);
        } elseif ($type === null || in_array($type, ['text/html', 'application/xhtml+xml'], true) || (str_starts_with($type, 'text/') && stripos(substr($body, 0, 1024), '<html') !== false)) {
            $result = $this->html->parse($body, $response->finalUrl, $response->charset());
            $result->contentType = $type ?? 'text/html';
        } else {
            $result = new ExtractedMetadata('generic', contentType: $type);
            $result->sourceType = match (true) {
                str_starts_with($type, 'image/') => SourceType::IMAGE,
                str_starts_with($type, 'video/') => SourceType::VIDEO,
                str_starts_with($type, 'audio/') => SourceType::AUDIO,
                in_array($type, ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.oasis.opendocument.text'], true) => SourceType::DOCUMENT,
                default => SourceType::OTHER,
            };
        }

        $result->httpStatus = $response->status;
        if ($response->finalUrl->normalized !== $url->normalized) {
            $result->extra['final_url'] = $response->finalUrl->normalized;
        }

        return $result;
    }
}
