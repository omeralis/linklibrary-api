<?php

namespace App\Services\Http;

use App\Services\Url\NormalizedUrl;

final class FetchResponse
{
    /**
     * @param  array<string, list<string>>  $headers  lowercase header names
     * @param  list<string>  $redirectChain  normalized URLs visited before the final one
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly NormalizedUrl $finalUrl,
        public readonly array $redirectChain = [],
        public readonly ?string $connectedIp = null,
        public readonly bool $truncated = false,
    ) {}

    public function header(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? [];

        return $values[0] ?? null;
    }

    /** Media type without parameters, lowercase (e.g. "text/html"). */
    public function mediaType(): ?string
    {
        $ct = $this->header('content-type');
        if ($ct === null) {
            return null;
        }

        return strtolower(trim(explode(';', $ct)[0]));
    }

    public function charset(): ?string
    {
        $ct = $this->header('content-type');
        if ($ct !== null && preg_match('/charset=["\']?([\w\-]+)/i', $ct, $m) === 1) {
            return strtolower($m[1]);
        }

        return null;
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
