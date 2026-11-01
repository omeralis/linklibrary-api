<?php

namespace Tests\Unit\Search;

use App\Services\Links\TagService;
use App\Services\Search\SearchQuery;
use App\Support\ArabicNormalizer;
use PHPUnit\Framework\TestCase;

class SearchQueryTest extends TestCase
{
    public function test_strips_operators_and_bounds_terms(): void
    {
        $q = SearchQuery::parse("laravel & ! 'drop' | (x) <-> ".implode(' ', range(1, 30)));
        $this->assertNotNull($q);
        $this->assertLessThanOrEqual(12, count($q->terms));
        $this->assertStringNotContainsString("'", $q->prefixTsquery());
        $this->assertStringNotContainsString('|', $q->prefixTsquery());
        $this->assertStringStartsWith('laravel:*', $q->prefixTsquery());
    }

    public function test_returns_null_for_symbol_only_queries(): void
    {
        $this->assertNull(SearchQuery::parse('!!! &&& ***'));
    }

    public function test_arabic_normalization(): void
    {
        $this->assertSame('الاسلام', ArabicNormalizer::normalize('الإسلام'));
        $this->assertSame('مدرسه', ArabicNormalizer::normalize('مَدْرَسَة'));
        $this->assertSame('علي', ArabicNormalizer::normalize('على'));
        $this->assertSame('123', ArabicNormalizer::normalize('١٢٣'));
        $this->assertSame(SearchQuery::parse('أحمد')?->terms, SearchQuery::parse('احمد')?->terms);
    }

    public function test_tag_slugs(): void
    {
        $this->assertSame('machine-learning', TagService::slug('  Machine   Learning! '));
        $this->assertSame('ذكاء-اصطناعي', TagService::slug('ذكاء اصطناعي'));
        $this->assertSame(TagService::slug('البرمجة'), TagService::slug('البَرمجة'));
        $this->assertSame('', TagService::slug('!!!'));
    }
}
