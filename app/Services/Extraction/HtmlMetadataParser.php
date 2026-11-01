<?php

namespace App\Services\Extraction;

use App\Enums\SourceType;
use App\Services\Url\InvalidUrlException;
use App\Services\Url\NormalizedUrl;
use App\Services\Url\UrlNormalizer;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Throwable;

/**
 * Extracts metadata from HTML: Open Graph, Twitter Cards, JSON-LD, standard meta tags, <title>,
 * canonical link, icons and the document language. The document is treated as untrusted data:
 * no scripts are executed, no external entities are loaded, no referenced resources are fetched.
 */
class HtmlMetadataParser
{
    public function __construct(private readonly UrlNormalizer $normalizer, private readonly int $excerptChars = 6000) {}

    public function parse(string $html, NormalizedUrl $baseUrl, ?string $charset = null): ExtractedMetadata
    {
        $result = new ExtractedMetadata('html');
        $html = $this->toUtf8($html, $charset);
        // The document is UTF-8 now: neutralise in-document charset declarations so libxml doesn't re-decode.
        $html = (string) preg_replace('/(<meta[^>]+charset=["\']?)[\w\-]+/i', '$1utf-8', $html);

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        } catch (Throwable) {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            return $result;
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);

        $meta = [];
        foreach ($xpath->query('//meta') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $key = strtolower(trim($node->getAttribute('property') ?: $node->getAttribute('name') ?: $node->getAttribute('itemprop')));
            $content = $node->getAttribute('content');
            if ($key !== '' && $content !== '' && ! isset($meta[$key])) {
                $meta[$key] = $content;
            }
        }

        $jsonLd = $this->parseJsonLd($xpath);

        $titleNode = $this->first($xpath, '//title');
        $docTitle = $titleNode?->textContent;

        $result->title = TextUtil::clean($meta['og:title'] ?? $meta['twitter:title'] ?? $jsonLd['headline'] ?? $jsonLd['name'] ?? $docTitle, 300);
        $result->description = TextUtil::clean($meta['og:description'] ?? $meta['twitter:description'] ?? $meta['description'] ?? $jsonLd['description'] ?? null, 1000);
        $result->siteName = TextUtil::clean($meta['og:site_name'] ?? $meta['application-name'] ?? $jsonLd['publisher'] ?? null, 255);
        $result->author = TextUtil::clean($meta['author'] ?? $meta['article:author'] ?? $jsonLd['author'] ?? $meta['twitter:creator'] ?? null, 255);
        if ($result->author !== null && str_starts_with($result->author, 'http')) {
            $result->author = null; // article:author is often a profile URL, not a name
        }

        $image = $meta['og:image:secure_url'] ?? $meta['og:image'] ?? $meta['og:image:url'] ?? $meta['twitter:image'] ?? $meta['twitter:image:src'] ?? $jsonLd['image'] ?? null;
        $result->imageUrl = $this->absoluteUrl($image, $baseUrl);

        $canonical = null;
        foreach ($xpath->query('//link[@rel]') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $rels = array_map('strtolower', preg_split('/\s+/', trim($node->getAttribute('rel'))) ?: []);
            $href = $node->getAttribute('href');
            if (in_array('canonical', $rels, true) && $canonical === null) {
                $canonical = $href;
            }
            if ((in_array('icon', $rels, true) || in_array('apple-touch-icon', $rels, true)) && $result->faviconUrl === null) {
                $result->faviconUrl = $this->absoluteUrl($href, $baseUrl);
            }
        }
        $result->canonicalUrl = $this->absoluteUrl($meta['og:url'] ?? $canonical, $baseUrl);
        $result->faviconUrl ??= $baseUrl->origin().'/favicon.ico';

        $htmlNode = $this->first($xpath, '//html');
        $lang = $htmlNode instanceof DOMElement ? $htmlNode->getAttribute('lang') : '';
        $result->language = TextUtil::normalizeLanguage($lang !== '' ? $lang : ($meta['og:locale'] ?? $meta['content-language'] ?? null));

        $published = $meta['article:published_time'] ?? $meta['og:published_time'] ?? $meta['date'] ?? $jsonLd['datePublished'] ?? null;
        $result->publishedAt = $this->parseDate($published);

        $ogType = strtolower($meta['og:type'] ?? '');
        $ldType = strtolower((string) ($jsonLd['@type'] ?? ''));
        $result->sourceType = match (true) {
            str_starts_with($ogType, 'video') || $ldType === 'videoobject' => SourceType::VIDEO,
            $ogType === 'article' || in_array($ldType, ['article', 'newsarticle', 'blogposting', 'techarticle', 'scholarlyarticle', 'report'], true) => SourceType::ARTICLE,
            str_starts_with($ogType, 'music') || $ldType === 'audioobject' || $ldType === 'podcastepisode' => SourceType::AUDIO,
            default => SourceType::WEBSITE,
        };

        $result->textExcerpt = $this->textExcerpt($xpath);
        $result->language ??= TextUtil::detectLanguage(($result->title ?? '').' '.($result->description ?? '').' '.($result->textExcerpt ?? ''));

        $extra = array_filter([
            'og_type' => $ogType ?: null,
            'json_ld_type' => $ldType ?: null,
            'keywords' => TextUtil::clean($meta['keywords'] ?? null, 300),
            'twitter_site' => TextUtil::clean($meta['twitter:site'] ?? null, 100),
        ]);
        $result->extra = $extra;

        return $result;
    }

    private function toUtf8(string $html, ?string $charset): string
    {
        if ($charset === null && preg_match('/<meta[^>]+charset=["\']?([\w\-]+)/i', substr($html, 0, 4096), $m) === 1) {
            $charset = strtolower($m[1]);
        }
        if ($charset !== null && ! in_array($charset, ['utf-8', 'utf8'], true)) {
            try {
                $supported = array_map('strtolower', mb_list_encodings());
                $converted = in_array($charset, $supported, true)
                    ? @mb_convert_encoding($html, 'UTF-8', $charset)
                    : @iconv($charset, 'UTF-8//IGNORE', $html);
                if (is_string($converted) && $converted !== '') {
                    return $converted;
                }
            } catch (Throwable) {
                // unknown charset: fall through to UTF-8 sanitisation
            }
        }

        return mb_check_encoding($html, 'UTF-8') ? $html : mb_convert_encoding($html, 'UTF-8', 'UTF-8');
    }

    /**
     * Flattens the first relevant JSON-LD object into scalar fields.
     *
     * @return array<string, string>
     */
    private function parseJsonLd(DOMXPath $xpath): array
    {
        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $node) {
            $raw = trim($node->textContent);
            if ($raw === '' || strlen($raw) > 200_000) {
                continue;
            }
            $data = json_decode($raw, true, 32);
            if (! is_array($data)) {
                continue;
            }
            $candidates = isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : (array_is_list($data) ? $data : [$data]);
            foreach ($candidates as $item) {
                if (! is_array($item) || ! isset($item['@type'])) {
                    continue;
                }
                $type = is_array($item['@type']) ? (string) ($item['@type'][0] ?? '') : (string) $item['@type'];
                if (in_array(strtolower($type), ['website', 'breadcrumblist', 'organization', 'searchaction', 'imageobject'], true)) {
                    continue;
                }

                return array_filter([
                    '@type' => $type,
                    'headline' => $this->scalar($item['headline'] ?? null),
                    'name' => $this->scalar($item['name'] ?? null),
                    'description' => $this->scalar($item['description'] ?? null),
                    'image' => $this->scalar($item['image'] ?? null, 'url'),
                    'author' => $this->scalar($item['author'] ?? null, 'name'),
                    'publisher' => $this->scalar($item['publisher'] ?? null, 'name'),
                    'datePublished' => $this->scalar($item['datePublished'] ?? $item['uploadDate'] ?? null),
                ], fn ($v) => $v !== null && $v !== '');
            }
        }

        return [];
    }

    private function scalar(mixed $value, string $key = 'name'): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            if (isset($value[$key]) && is_string($value[$key])) {
                return $value[$key];
            }
            if (array_is_list($value) && $value !== []) {
                return $this->scalar($value[0], $key);
            }
        }

        return null;
    }

    private function absoluteUrl(?string $value, NormalizedUrl $base): ?string
    {
        $value = $value !== null ? trim($value) : null;
        if ($value === null || $value === '' || strlen($value) > 2048 || str_starts_with(strtolower($value), 'data:')) {
            return null;
        }
        try {
            return $this->normalizer->resolve($base, $value)->normalized;
        } catch (InvalidUrlException) {
            return null;
        }
    }

    private function parseDate(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        try {
            $date = CarbonImmutable::parse(trim($value));

            return $date->year >= 1970 && $date->lessThanOrEqualTo(now()->addDay()) ? $date : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function first(DOMXPath $xpath, string $expression): ?\DOMNode
    {
        $list = $xpath->query($expression);

        return $list === false ? null : $list->item(0);
    }

    private function textExcerpt(DOMXPath $xpath): ?string
    {
        foreach ($xpath->query('//script|//style|//noscript|//nav|//footer|//header|//form|//svg|//iframe|//template') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }
        $root = $this->first($xpath, '//article') ?? $this->first($xpath, '//main') ?? $this->first($xpath, '//body');
        if ($root === null) {
            return null;
        }

        return TextUtil::clean($root->textContent, $this->excerptChars);
    }
}
