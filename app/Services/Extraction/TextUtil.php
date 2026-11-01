<?php

namespace App\Services\Extraction;

final class TextUtil
{
    /** Collapse whitespace, strip control characters, trim and bound length. Returns null for empty. */
    public static function clean(?string $value, int $max = 1000): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $value);
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        if ($value === '') {
            return null;
        }

        return mb_strlen($value) > $max ? rtrim(mb_substr($value, 0, $max - 1)).'…' : $value;
    }

    /** Detect Arabic vs English from text when the source does not declare a language. */
    public static function detectLanguage(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }
        $arabic = preg_match_all('/\p{Arabic}/u', $text);
        $latin = preg_match_all('/\p{Latin}/u', $text);
        $letters = $arabic + $latin;
        if ($letters < 20) {
            return null;
        }
        if ($arabic / $letters >= 0.4) {
            return 'ar';
        }
        if ($latin / $letters >= 0.8) {
            $words = preg_split('/\W+/u', mb_strtolower($text)) ?: [];
            $stop = array_flip(['the', 'and', 'of', 'to', 'in', 'is', 'for', 'with', 'on', 'that', 'this', 'are', 'by', 'from', 'you', 'it']);
            $hits = 0;
            foreach ($words as $w) {
                if (isset($stop[$w])) {
                    $hits++;
                }
            }
            if (count($words) > 0 && $hits / count($words) >= 0.05) {
                return 'en';
            }
        }

        return null;
    }

    /** Normalize a BCP-47-ish language tag to a short code ("en-US" → "en"). */
    public static function normalizeLanguage(?string $tag): ?string
    {
        if ($tag === null) {
            return null;
        }
        $tag = strtolower(trim(str_replace('_', '-', $tag)));
        if (preg_match('/^([a-z]{2,3})(-|$)/', $tag, $m) !== 1) {
            return null;
        }

        return $m[1];
    }
}
