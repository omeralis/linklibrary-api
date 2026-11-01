<?php

namespace App\Services\Http;

use App\Services\Url\InvalidUrlException;
use App\Services\Url\NormalizedUrl;
use App\Services\Url\UrlNormalizer;

/**
 * The only component allowed to make outbound requests to user-supplied URLs.
 *
 * For every hop (initial URL and each redirect):
 *  1. scheme must be http/https, port must be allow-listed, hostname must not be internal;
 *  2. the hostname is resolved and EVERY returned address must be public;
 *  3. the connection is pinned to one of those validated addresses (no second DNS lookup);
 *  4. the connected address is verified after the request;
 *  5. redirects are followed manually (bounded) and re-validated; HTTPS→HTTP downgrades are refused.
 * Response size (after decompression) and time are bounded by the transport.
 */
class SafeHttpClient
{
    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    /** @var list<string> */
    private const INTERNAL_SUFFIXES = ['.localhost', '.local', '.internal', '.intranet', '.lan', '.home.arpa', '.corp', '.private'];

    /** @var list<string> */
    private const INTERNAL_HOSTS = ['localhost', 'metadata.google.internal', 'metadata', 'instance-data', 'kubernetes.default.svc'];

    /**
     * @param  list<int>  $allowedPorts
     * @param  list<string>  $blockedHosts
     */
    public function __construct(
        private readonly UrlNormalizer $normalizer,
        private readonly DnsResolver $resolver,
        private readonly IpAddressPolicy $ipPolicy,
        private readonly HttpTransport $transport,
        private readonly string $userAgent,
        private readonly float $connectTimeout,
        private readonly float $timeout,
        private readonly int $maxRedirects,
        private readonly int $defaultMaxBytes,
        private readonly array $allowedPorts,
        private readonly array $blockedHosts = [],
        private readonly bool $allowDowngrade = false,
    ) {}

    /**
     * @param  array<string, string>  $headers
     */
    public function get(NormalizedUrl|string $url, array $headers = [], ?int $maxBytes = null, string $method = 'GET'): FetchResponse
    {
        try {
            $current = $url instanceof NormalizedUrl ? $url : $this->normalizer->normalize($url);
        } catch (InvalidUrlException $e) {
            throw new FetchException(FetchException::INVALID_URL, $e->reason);
        }

        $headers = array_merge([
            'User-Agent' => $this->userAgent,
            'Accept' => 'text/html,application/xhtml+xml,application/json;q=0.9,application/pdf;q=0.8,*/*;q=0.5',
            'Accept-Language' => 'ar,en;q=0.8',
        ], $headers);

        $chain = [];
        for ($hop = 0; ; $hop++) {
            $ip = $this->validateDestination($current);

            $response = $this->transport->send(new PinnedRequest(
                url: $current,
                ip: $ip,
                headers: $headers,
                maxBytes: $maxBytes ?? $this->defaultMaxBytes,
                connectTimeout: $this->connectTimeout,
                timeout: $this->timeout,
                method: $method,
            ));

            $location = $response->headers['location'][0] ?? null;
            if (! in_array($response->status, self::REDIRECT_STATUSES, true) || $location === null) {
                return new FetchResponse(
                    status: $response->status,
                    headers: $response->headers,
                    body: $response->body,
                    finalUrl: $current,
                    redirectChain: $chain,
                    connectedIp: $response->primaryIp,
                );
            }

            if ($hop >= $this->maxRedirects) {
                throw new FetchException(FetchException::TOO_MANY_REDIRECTS);
            }

            try {
                $next = $this->normalizer->resolve($current, $location);
            } catch (InvalidUrlException $e) {
                throw new FetchException(FetchException::INVALID_REDIRECT, $e->reason);
            }

            if ($current->scheme === 'https' && $next->scheme === 'http' && ! $this->allowDowngrade) {
                throw new FetchException(FetchException::UNSAFE_REDIRECT, 'https to http downgrade');
            }

            $chain[] = $current->normalized;
            $current = $next;
        }
    }

    /**
     * Validate scheme/port/host and return the IP address the connection must be pinned to.
     */
    public function validateDestination(NormalizedUrl $url): string
    {
        if ($url->scheme !== 'http' && $url->scheme !== 'https') {
            throw new FetchException(FetchException::BLOCKED_SCHEME);
        }

        $defaultPort = $url->scheme === 'https' ? 443 : 80;
        if ($url->port !== $defaultPort && ! in_array($url->port, $this->allowedPorts, true)) {
            throw new FetchException(FetchException::BLOCKED_PORT);
        }

        if ($this->isInternalHostname($url->host)) {
            throw new FetchException(FetchException::BLOCKED_HOST);
        }

        $ips = $url->hostIsIp ? [$url->host] : $this->resolver->resolve($url->host);
        if ($ips === []) {
            throw FetchException::retryable(FetchException::DNS_FAILURE);
        }

        foreach ($ips as $ip) {
            if (! $this->ipPolicy->isPublic($ip)) {
                // Any non-public answer blocks the host: a mixed answer set is a rebinding indicator.
                throw new FetchException(FetchException::BLOCKED_IP);
            }
        }

        foreach ($ips as $ip) {
            if (! str_contains($ip, ':')) {
                return $ip; // prefer IPv4 for predictability
            }
        }

        return $ips[0];
    }

    public function isInternalHostname(string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));
        if (in_array($host, self::INTERNAL_HOSTS, true)) {
            return true;
        }
        foreach (self::INTERNAL_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }
        foreach ($this->blockedHosts as $blocked) {
            $blocked = strtolower(trim($blocked));
            if ($blocked === '') {
                continue;
            }
            if ($host === ltrim($blocked, '.') || (str_starts_with($blocked, '.') && str_ends_with($host, $blocked))) {
                return true;
            }
        }

        return false;
    }
}
