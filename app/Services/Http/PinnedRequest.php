<?php

namespace App\Services\Http;

use App\Services\Url\NormalizedUrl;

final class PinnedRequest
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly NormalizedUrl $url,
        public readonly string $ip,
        public readonly array $headers,
        public readonly int $maxBytes,
        public readonly float $connectTimeout,
        public readonly float $timeout,
        public readonly string $method = 'GET',
    ) {}

    /** URL actually requested on the wire (fragment never sent). */
    public function requestUrl(): string
    {
        return $this->url->scheme.'://'.$this->url->authority().$this->url->pathAndQuery();
    }
}
