<?php

namespace Tests\Unit\Extraction;

use App\Enums\SourceType;
use App\Services\Extraction\PdfMetadataParser;
use App\Services\Extraction\RobotsPolicy;
use PHPUnit\Framework\TestCase;

class PdfAndRobotsTest extends TestCase
{
    public function test_reads_pdf_info_dictionary(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj << /Title (Annual Report \\(2024\\)) /Author (Research Team) /Subject (Findings) /CreationDate (D:20240115093000Z) >> endobj\n/Type /Pages /Count 12\n%%EOF";
        $m = (new PdfMetadataParser)->parse($pdf);

        $this->assertSame('Annual Report (2024)', $m->title);
        $this->assertSame('Research Team', $m->author);
        $this->assertSame('Findings', $m->description);
        $this->assertSame('2024-01-15', $m->publishedAt?->toDateString());
        $this->assertSame(SourceType::PDF, $m->sourceType);
        $this->assertSame(12, $m->extra['page_count_hint']);
    }

    public function test_reads_utf16_hex_titles(): void
    {
        $hex = strtoupper(bin2hex("\xFE\xFF".mb_convert_encoding('تقرير', 'UTF-16BE', 'UTF-8')));
        $m = (new PdfMetadataParser)->parse("%PDF-1.7\n<< /Title <{$hex}> >>");
        $this->assertSame('تقرير', $m->title);
    }

    public function test_pdf_without_metadata_has_no_invented_title(): void
    {
        $m = (new PdfMetadataParser)->parse("%PDF-1.5\nstream compressed bytes endstream");
        $this->assertNull($m->title);
    }

    public function test_robots_rules(): void
    {
        $robots = "User-agent: *\nDisallow: /private\nAllow: /private/public\n\nUser-agent: LinkLibraryBot\nDisallow: /no-bots\n";

        $rules = RobotsPolicy::parse($robots, 'LinkLibraryBot');
        $this->assertFalse(RobotsPolicy::evaluate($rules, '/no-bots/page'));
        $this->assertTrue(RobotsPolicy::evaluate($rules, '/private'), 'specific group replaces the wildcard group');

        $generic = RobotsPolicy::parse($robots, 'OtherBot');
        $this->assertFalse(RobotsPolicy::evaluate($generic, '/private/x'));
        $this->assertTrue(RobotsPolicy::evaluate($generic, '/private/public/x'), 'longest match wins');
        $this->assertTrue(RobotsPolicy::evaluate($generic, '/open'));

        $this->assertFalse(RobotsPolicy::evaluate(RobotsPolicy::parse("User-agent: *\nDisallow: /*.pdf$", 'x'), '/docs/a.pdf'));
        $this->assertTrue(RobotsPolicy::evaluate(RobotsPolicy::parse("User-agent: *\nDisallow:", 'x'), '/anything'));
    }
}
