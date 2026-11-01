<?php

namespace App\Services\Ai;

use App\Enums\SourceType;
use App\Services\Extraction\TextUtil;

/**
 * Independent validation of provider output. Anything that does not match the contract is dropped or
 * rejected; the model's output is never trusted to respect the schema on its own.
 */
class AiOutputValidator
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $allowedCategories
     */
    public function validate(array $data, array $allowedCategories, ?int $inputTokens = null, ?int $outputTokens = null): AiResult
    {
        if (! array_key_exists('suspicious', $data) || ! is_bool($data['suspicious'])) {
            throw new AiException('INVALID_OUTPUT');
        }

        $category = is_string($data['category'] ?? null) ? strtolower(trim($data['category'])) : null;
        if ($category !== null && ! in_array($category, $allowedCategories, true)) {
            $category = null;
        }

        $language = is_string($data['language'] ?? null) && in_array($data['language'], ['ar', 'en'], true) ? $data['language'] : null;
        $contentType = is_string($data['content_type'] ?? null) && in_array($data['content_type'], SourceType::values(), true) ? $data['content_type'] : null;

        $tags = [];
        foreach (array_slice((array) ($data['tags'] ?? []), 0, 8) as $tag) {
            if (! is_string($tag)) {
                continue;
            }
            $tag = TextUtil::clean(strip_tags($tag), 40);
            if ($tag === null || preg_match('#(https?:|www\.|[<>{}\[\]`]|^\#)#i', $tag) === 1) {
                continue;
            }
            $tags[] = $tag;
        }

        $reasons = [];
        foreach (array_slice((array) ($data['suspicious_reasons'] ?? []), 0, 5) as $reason) {
            if (is_string($reason) && ($r = TextUtil::clean(strip_tags($reason), 200)) !== null) {
                $reasons[] = $r;
            }
        }

        return new AiResult(
            suggestedTitle: $this->text($data['suggested_title'] ?? null, 200),
            shortSummary: $this->text($data['short_summary'] ?? null, 300),
            detailedSummary: $this->text($data['detailed_summary'] ?? null, 1500),
            categorySlug: $category,
            tags: array_values(array_unique($tags)),
            language: $language,
            contentType: $contentType,
            suspicious: $data['suspicious'],
            suspiciousReasons: $reasons,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
        );
    }

    private function text(mixed $value, int $max): ?string
    {
        return is_string($value) ? TextUtil::clean(strip_tags($value), $max) : null;
    }
}
