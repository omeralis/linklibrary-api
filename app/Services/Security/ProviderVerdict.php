<?php

namespace App\Services\Security;

use App\Enums\SecurityStatus;

final class ProviderVerdict
{
    /**
     * @param  SecurityStatus|null  $verdict  null = provider has no opinion (e.g. heuristics found nothing)
     * @param  list<string>  $threats
     */
    public function __construct(
        public readonly string $provider,
        public readonly ?SecurityStatus $verdict,
        public readonly bool $authoritative,
        public readonly array $threats = [],
        public readonly ?string $errorCode = null,
    ) {}

    public static function error(string $provider, string $code): self
    {
        return new self($provider, SecurityStatus::UNKNOWN, true, [], $code);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'verdict' => $this->verdict?->value,
            'authoritative' => $this->authoritative,
            'threats' => $this->threats,
            'error_code' => $this->errorCode,
        ];
    }
}
