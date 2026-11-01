<?php

namespace App\Services\Ai;

final class AiResult
{
    /**
     * @param  list<string>  $tags
     * @param  list<string>  $suspiciousReasons
     */
    public function __construct(
        public readonly ?string $suggestedTitle,
        public readonly ?string $shortSummary,
        public readonly ?string $detailedSummary,
        public readonly ?string $categorySlug,
        public readonly array $tags,
        public readonly ?string $language,
        public readonly ?string $contentType,
        public readonly bool $suspicious,
        public readonly array $suspiciousReasons,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
    ) {}
}
