<?php

namespace App\Services\Extraction;

use App\Enums\SourceType;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Best-effort, dependency-free PDF metadata reader: Info dictionary (uncompressed) and XMP packet.
 * Never executes or renders PDF content.
 */
class PdfMetadataParser
{
    public function parse(string $bytes): ExtractedMetadata
    {
        $result = new ExtractedMetadata('pdf', contentType: 'application/pdf', sourceType: SourceType::PDF);

        // XMP (usually uncompressed)
        if (preg_match('/<x:xmpmeta.*?<\/x:xmpmeta>/s', $bytes, $m) === 1) {
            $xmp = $m[0];
            $result->title = $this->xmpValue($xmp, 'dc:title');
            $result->description = $this->xmpValue($xmp, 'dc:description');
            $result->author = $this->xmpValue($xmp, 'dc:creator');
            $date = $this->xmpRaw($xmp, 'xmp:CreateDate');
            $result->publishedAt = $this->parseIsoDate($date);
            $lang = $this->xmpRaw($xmp, 'dc:language');
            $result->language = TextUtil::normalizeLanguage($lang);
        }

        // Info dictionary
        $result->title ??= TextUtil::clean($this->infoValue($bytes, 'Title'), 300);
        $result->description ??= TextUtil::clean($this->infoValue($bytes, 'Subject'), 1000);
        $result->author ??= TextUtil::clean($this->infoValue($bytes, 'Author'), 255);
        $result->publishedAt ??= $this->parsePdfDate($this->infoValue($bytes, 'CreationDate'));

        if (preg_match('/\/Count\s+(\d+)/', $bytes, $pm) === 1) {
            $result->extra['page_count_hint'] = (int) $pm[1];
        }
        if (preg_match('/^%PDF-(\d\.\d)/', $bytes, $vm) === 1) {
            $result->extra['pdf_version'] = $vm[1];
        }

        return $result;
    }

    private function xmpValue(string $xmp, string $tag): ?string
    {
        if (preg_match('/<'.preg_quote($tag, '/').'[^>]*>(.*?)<\/'.preg_quote($tag, '/').'>/s', $xmp, $m) !== 1) {
            return null;
        }
        if (preg_match('/<rdf:li[^>]*>(.*?)<\/rdf:li>/s', $m[1], $li) === 1) {
            return TextUtil::clean(strip_tags($li[1]), 500);
        }

        return TextUtil::clean(strip_tags($m[1]), 500);
    }

    private function xmpRaw(string $xmp, string $tag): ?string
    {
        if (preg_match('/<'.preg_quote($tag, '/').'[^>]*>(.*?)<\/'.preg_quote($tag, '/').'>/s', $xmp, $m) === 1) {
            return trim(strip_tags($m[1]));
        }
        if (preg_match('/'.preg_quote($tag, '/').'="([^"]+)"/', $xmp, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function infoValue(string $bytes, string $key): ?string
    {
        if (preg_match('/\/'.$key.'\s*\((.*?)(?<!\\\\)\)/s', $bytes, $m) === 1) {
            return $this->decodeLiteral($m[1]);
        }
        if (preg_match('/\/'.$key.'\s*<([0-9A-Fa-f\s]+)>/', $bytes, $m) === 1) {
            $hex = preg_replace('/\s+/', '', $m[1]) ?? '';
            $raw = hex2bin(strlen($hex) % 2 === 0 ? $hex : $hex.'0');

            return $raw === false ? null : $this->decodeText($raw);
        }

        return null;
    }

    private function decodeLiteral(string $value): string
    {
        $value = preg_replace_callback('/\\\\([nrtbf()\\\\]|[0-7]{1,3})/', function ($m) {
            return match ($m[1]) {
                'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f",
                '(' => '(', ')' => ')', '\\' => '\\',
                default => chr(octdec($m[1]) & 0xFF),
            };
        }, $value) ?? $value;

        return $this->decodeText($value);
    }

    private function decodeText(string $raw): string
    {
        if (str_starts_with($raw, "\xFE\xFF")) {
            return (string) mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
        }
        if (mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }

        return (string) mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
    }

    private function parsePdfDate(?string $value): ?CarbonImmutable
    {
        if ($value === null || preg_match('/D:(\d{4})(\d{2})?(\d{2})?(\d{2})?(\d{2})?(\d{2})?/', $value, $m) !== 1) {
            return null;
        }
        try {
            $date = CarbonImmutable::create((int) $m[1], (int) ($m[2] ?? 1) ?: 1, (int) ($m[3] ?? 1) ?: 1, (int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0), 'UTC');

            return $date !== null && $date->year >= 1970 && $date->isPast() ? $date : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function parseIsoDate(?string $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $date = CarbonImmutable::parse($value);

            return $date->year >= 1970 && $date->isPast() ? $date : null;
        } catch (Throwable) {
            return null;
        }
    }
}
