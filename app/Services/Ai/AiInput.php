<?php

namespace App\Services\Ai;

/**
 * Data sent to an AI provider. Built exclusively from public, extracted source content — never from
 * private notes, invitations, user identities or credentials.
 */
final class AiInput
{
    /** @param list<string> $allowedCategories */
    public function __construct(
        public readonly string $host,
        public readonly ?string $title,
        public readonly ?string $description,
        public readonly ?string $siteName,
        public readonly ?string $excerpt,
        public readonly ?string $declaredLanguage,
        public readonly ?string $sourceType,
        public readonly array $allowedCategories,
    ) {}

    public function hash(): string
    {
        return hash('sha256', (string) json_encode(get_object_vars($this)));
    }

    public function hasContent(): bool
    {
        return ($this->title ?? $this->description ?? $this->excerpt) !== null;
    }
}
