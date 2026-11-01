<?php

namespace App\Services\Http;

final class TransportResponse
{
    /** @param array<string, list<string>> $headers */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly ?string $primaryIp,
    ) {}
}
