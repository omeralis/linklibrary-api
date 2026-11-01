<?php

namespace App\Support;

/**
 * Unicode-aware normalisation used for search indexing/querying and tag slugs. PostgreSQL's Arabic
 * Snowball stemmer does not unify orthographic variants, so we fold them first:
 * diacritics and tatweel removed; alef variants → ا; ى → ي; ة → ه; ؤ → و; ئ → ي; Arabic-Indic digits → ASCII.
 */
final class ArabicNormalizer
{
    public static function normalize(string $text): string
    {
        $text = (string) preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}]/u', '', $text);
        $text = strtr($text, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        return mb_strtolower($text);
    }
}
