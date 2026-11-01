<?php

namespace App\Services\Url;

final class NormalizedUrl
{
    public function __construct(
        public readonly string $original,
        public readonly string $normalized,
        public readonly string $scheme,
        /** ASCII (punycode) hostname, lowercase, no trailing dot. IPv6 literals without brackets. */
        public readonly string $host,
        public readonly int $port,
        public readonly string $path,
        public readonly ?string $query,
        public readonly ?string $fragment,
        public readonly bool $hostIsIp,
    ) {}

    public function hash(): string
    {
        return hash('sha256', $this->normalized);
    }

    /** Scheme + authority, e.g. https://example.com:8443 */
    public function origin(): string
    {
        return $this->scheme.'://'.$this->authority();
    }

    public function authority(): string
    {
        $host = str_contains($this->host, ':') ? '['.$this->host.']' : $this->host;
        $default = $this->scheme === 'https' ? 443 : 80;

        return $this->port === $default ? $host : $host.':'.$this->port;
    }

    /** Path and query for logging-safe diagnostics: query string removed. */
    public function safeForLogs(): string
    {
        return $this->scheme.'://'.$this->authority().$this->path;
    }

    public function pathAndQuery(): string
    {
        return $this->path.($this->query !== null ? '?'.$this->query : '');
    }
}
