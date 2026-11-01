<?php

namespace App\Services\Extraction;

use App\Enums\SourceType;
use Carbon\CarbonImmutable;

/**
 * Metadata actually found at the source. Fields are null when not found — never guessed.
 */
final class ExtractedMetadata
{
    /** @param array<string, mixed> $extra */
    public function __construct(
        public readonly string $extractor,
        public ?string $title = null,
        public ?string $description = null,
        public ?string $imageUrl = null,
        public ?string $faviconUrl = null,
        public ?string $canonicalUrl = null,
        public ?string $siteName = null,
        public ?string $language = null,
        public ?string $author = null,
        public ?CarbonImmutable $publishedAt = null,
        public ?string $contentType = null,
        public ?SourceType $sourceType = null,
        public ?string $textExcerpt = null,
        public array $extra = [],
        public ?int $httpStatus = null,
    ) {}

    public function hasAny(): bool
    {
        return $this->title !== null || $this->description !== null || $this->imageUrl !== null;
    }

    public function isComplete(): bool
    {
        return $this->title !== null && ($this->description !== null || $this->imageUrl !== null);
    }

    /** Fill blanks from another (lower-priority) result. */
    public function mergeMissing(ExtractedMetadata $other): void
    {
        foreach (['title', 'description', 'imageUrl', 'faviconUrl', 'canonicalUrl', 'siteName', 'language', 'author', 'publishedAt', 'contentType', 'sourceType', 'textExcerpt'] as $field) {
            if ($this->{$field} === null && $other->{$field} !== null) {
                $this->{$field} = $other->{$field};
            }
        }
        $this->extra = $this->extra + $other->extra;
    }
}
