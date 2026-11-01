<?php

namespace Tests\Unit\Extraction;

use App\Enums\SourceType;
use App\Services\Extraction\ExtractedMetadata;
use App\Services\Extraction\HtmlMetadataParser;
use App\Services\Url\UrlNormalizer;
use PHPUnit\Framework\TestCase;

class HtmlMetadataParserTest extends TestCase
{
    private function parse(string $html, string $url = 'https://news.example.com/a/story'): ExtractedMetadata
    {
        $n = new UrlNormalizer;

        return (new HtmlMetadataParser($n))->parse($html, $n->normalize($url));
    }

    public function test_open_graph_takes_precedence(): void
    {
        $m = $this->parse(<<<'HTML'
            <html lang="en-US"><head>
              <title>Doc title</title>
              <meta property="og:title" content="OG Title">
              <meta property="og:description" content="OG description">
              <meta property="og:image" content="/img/cover.jpg">
              <meta property="og:site_name" content="Example News">
              <meta property="og:type" content="article">
              <meta property="article:published_time" content="2025-03-01T10:00:00Z">
              <meta name="author" content="Jane Doe">
              <link rel="canonical" href="https://news.example.com/a/story-canonical">
              <link rel="icon" href="/favicon.png">
            </head><body><article><p>Body text for the article.</p></article></body></html>
            HTML);

        $this->assertSame('OG Title', $m->title);
        $this->assertSame('OG description', $m->description);
        $this->assertSame('https://news.example.com/img/cover.jpg', $m->imageUrl);
        $this->assertSame('https://news.example.com/favicon.png', $m->faviconUrl);
        $this->assertSame('https://news.example.com/a/story-canonical', $m->canonicalUrl);
        $this->assertSame('Example News', $m->siteName);
        $this->assertSame('Jane Doe', $m->author);
        $this->assertSame('en', $m->language);
        $this->assertSame(SourceType::ARTICLE, $m->sourceType);
        $this->assertSame('2025-03-01', $m->publishedAt?->toDateString());
        $this->assertTrue($m->isComplete());
    }

    public function test_twitter_and_json_ld_fallbacks(): void
    {
        $m = $this->parse(<<<'HTML'
            <html><head>
              <meta name="twitter:title" content="Tw title">
              <script type="application/ld+json">{"@context":"https://schema.org","@type":"VideoObject","name":"LD name","description":"LD desc","thumbnailUrl":"x","uploadDate":"2024-01-02","author":{"@type":"Person","name":"LD Author"}}</script>
            </head><body></body></html>
            HTML);

        $this->assertSame('Tw title', $m->title);
        $this->assertSame('LD desc', $m->description);
        $this->assertSame('LD Author', $m->author);
        $this->assertSame(SourceType::VIDEO, $m->sourceType);
    }

    public function test_arabic_content_and_language_detection(): void
    {
        $m = $this->parse('<html><head><title>دليل البرمجة بلغة بي إتش بي</title><meta name="description" content="شرح مفصل لأساسيات البرمجة للمبتدئين مع أمثلة عملية"></head><body><p>هذا نص عربي طويل يشرح المفاهيم الأساسية.</p></body></html>');

        $this->assertSame('دليل البرمجة بلغة بي إتش بي', $m->title);
        $this->assertSame('ar', $m->language);
    }

    public function test_does_not_invent_metadata(): void
    {
        $m = $this->parse('<html><body><div></div></body></html>');
        $this->assertNull($m->title);
        $this->assertNull($m->description);
        $this->assertNull($m->imageUrl);
        $this->assertFalse($m->hasAny());
    }

    public function test_ignores_scripts_and_unsafe_urls(): void
    {
        $m = $this->parse('<html><head><title>T</title><meta property="og:image" content="javascript:alert(1)"></head><body><script>var secret="x"</script><p>Visible</p></body></html>');
        $this->assertNull($m->imageUrl);
        $this->assertStringNotContainsString('secret', (string) $m->textExcerpt);
        $this->assertStringContainsString('Visible', (string) $m->textExcerpt);
    }

    public function test_handles_non_utf8_charset(): void
    {
        $html = (string) iconv('UTF-8', 'WINDOWS-1256', '<html><head><meta charset="windows-1256"><title>مرحبا بالعالم</title></head></html>');
        $m = (new HtmlMetadataParser(new UrlNormalizer))->parse($html, (new UrlNormalizer)->normalize('https://example.com/'), 'windows-1256');
        $this->assertSame('مرحبا بالعالم', $m->title);
    }
}
