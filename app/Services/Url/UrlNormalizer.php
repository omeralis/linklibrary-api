<?php

namespace App\Services\Url;

/**
 * Strict HTTP(S) URL validation and canonicalisation.
 *
 * Policy (documented in docs/SECURITY.md):
 *  - Only http/https. Credentials (userinfo), control/format characters and backslashes are rejected.
 *  - Hostnames are converted to ASCII (UTS #46), lowercased, trailing dot removed.
 *  - Ambiguous numeric hosts (decimal/octal/hex IPv4 forms, numeric TLDs) are rejected.
 *  - Default ports are removed; ports must be 1..65535.
 *  - Path dot-segments are resolved; percent-encoding is canonicalised (uppercase hex, unreserved decoded).
 *  - Query strings are preserved verbatim in order (tracking parameters are NOT stripped because they
 *    can change the resource).
 *  - Fragments are dropped from the normalized form unless they look like client-side routes ("#!" or "#/").
 *  - The normalized URL's SHA-256 is the deduplication key.
 */
class UrlNormalizer
{
    private const UNRESERVED = '/[A-Za-z0-9\-._~]/';

    public function __construct(
        private readonly int $maxLength = 2048,
        private readonly bool $keepRouteFragments = true,
    ) {}

    public function normalize(string $input): NormalizedUrl
    {
        $url = trim($input, " \t\n\r\0\x0B");

        if ($url === '') {
            throw new InvalidUrlException('empty');
        }
        if (strlen($url) > $this->maxLength) {
            throw new InvalidUrlException('too_long');
        }
        if (! mb_check_encoding($url, 'UTF-8')) {
            throw new InvalidUrlException('invalid_encoding');
        }
        // Control characters (C0, DEL, C1) and invisible format characters (bidi overrides, zero-width).
        if (preg_match('/[\p{Cc}\p{Cf}]/u', $url) === 1) {
            throw new InvalidUrlException('control_characters');
        }
        if (str_contains($url, '\\')) {
            throw new InvalidUrlException('backslash');
        }

        if (preg_match('/^([A-Za-z][A-Za-z0-9+.\-]*):(.*)$/s', $url, $m) !== 1) {
            throw new InvalidUrlException('missing_scheme');
        }
        $scheme = strtolower($m[1]);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidUrlException('unsupported_scheme');
        }
        $rest = $m[2];
        if (! str_starts_with($rest, '//')) {
            throw new InvalidUrlException('malformed');
        }
        $rest = substr($rest, 2);

        // RFC 3986 appendix B split of the remainder.
        preg_match('/^([^\/?#]*)([^?#]*)(?:\?([^#]*))?(?:#(.*))?$/s', $rest, $parts);
        $authority = $parts[1] ?? '';
        $path = $parts[2] ?? '';
        $query = array_key_exists(3, $parts) && $parts[3] !== '' ? $parts[3] : null;
        $fragment = array_key_exists(4, $parts) && $parts[4] !== '' ? $parts[4] : null;

        if ($authority === '') {
            throw new InvalidUrlException('missing_host');
        }
        if (str_contains($authority, '@')) {
            throw new InvalidUrlException('credentials_not_allowed');
        }

        [$host, $port, $isIp] = $this->parseAuthority($authority, $scheme);

        $path = $path === '' ? '/' : $this->removeDotSegments($this->normalizeEncoding($path, '/:@!$&\'()*+,;='));
        if ($query !== null) {
            $query = $this->normalizeEncoding($query, '/?:@!$&\'()*+,;=');
        }
        $keptFragment = null;
        if ($fragment !== null && $this->keepRouteFragments && (str_starts_with($fragment, '!') || str_starts_with($fragment, '/'))) {
            $keptFragment = $this->normalizeEncoding($fragment, '/?:@!$&\'()*+,;=');
        }

        $default = $scheme === 'https' ? 443 : 80;
        $hostPart = str_contains($host, ':') ? "[{$host}]" : $host;
        $normalized = $scheme.'://'.$hostPart.($port === $default ? '' : ':'.$port).$path
            .($query !== null ? '?'.$query : '')
            .($keptFragment !== null ? '#'.$keptFragment : '');

        if (strlen($normalized) > $this->maxLength) {
            throw new InvalidUrlException('too_long');
        }

        return new NormalizedUrl(
            original: $url,
            normalized: $normalized,
            scheme: $scheme,
            host: $host,
            port: $port,
            path: $path,
            query: $query,
            fragment: $fragment,
            hostIsIp: $isIp,
        );
    }

    /** Resolve a (possibly relative) reference, e.g. a redirect Location, against a base URL. */
    public function resolve(NormalizedUrl $base, string $reference): NormalizedUrl
    {
        $reference = trim($reference);
        if ($reference === '') {
            throw new InvalidUrlException('empty');
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9+.\-]*:/', $reference) === 1) {
            return $this->normalize($reference);
        }
        if (str_starts_with($reference, '//')) {
            return $this->normalize($base->scheme.':'.$reference);
        }
        $origin = $base->origin();
        if (str_starts_with($reference, '/')) {
            return $this->normalize($origin.$reference);
        }
        if (str_starts_with($reference, '?')) {
            return $this->normalize($origin.$base->path.$reference);
        }
        if (str_starts_with($reference, '#')) {
            return $this->normalize($origin.$base->pathAndQuery().$reference);
        }
        $dir = substr($base->path, 0, (int) strrpos($base->path, '/') + 1);

        return $this->normalize($origin.$dir.$reference);
    }

    /** @return array{0: string, 1: int, 2: bool} */
    private function parseAuthority(string $authority, string $scheme): array
    {
        $portString = null;
        if (str_starts_with($authority, '[')) {
            $end = strpos($authority, ']');
            if ($end === false) {
                throw new InvalidUrlException('invalid_host');
            }
            $host = substr($authority, 1, $end - 1);
            $after = substr($authority, $end + 1);
            if ($after !== '') {
                if ($after[0] !== ':') {
                    throw new InvalidUrlException('invalid_host');
                }
                $portString = substr($after, 1);
            }
            if (str_contains($host, '%') || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new InvalidUrlException('invalid_host');
            }
            $packed = inet_pton($host);
            $host = $packed === false ? $host : (string) inet_ntop($packed);

            return [strtolower($host), $this->parsePort($portString, $scheme), true];
        }

        if (substr_count($authority, ':') > 1) {
            throw new InvalidUrlException('invalid_host');
        }
        if (str_contains($authority, ':')) {
            [$host, $portString] = explode(':', $authority, 2);
        } else {
            $host = $authority;
        }

        if ($host === '' || str_contains($host, '%') || preg_match('/[\s<>"{}|^`\/?#\[\]]/u', $host) === 1) {
            throw new InvalidUrlException('invalid_host');
        }

        $host = rtrim($host, '.');
        if ($host === '') {
            throw new InvalidUrlException('invalid_host');
        }

        if (preg_match('/[^\x20-\x7E]/', $host) === 1) {
            $info = [];
            $ascii = idn_to_ascii(
                $host,
                IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ,
                INTL_IDNA_VARIANT_UTS46,
                $info,
            );
            if ($ascii === false || ($info['errors'] ?? 0) !== 0) {
                throw new InvalidUrlException('invalid_idn');
            }
            $host = $ascii;
        }
        $host = strtolower($host);

        if (strlen($host) > 253) {
            throw new InvalidUrlException('invalid_host');
        }

        $labels = explode('.', $host);
        $last = end($labels);

        // IPv4 literal: only the canonical dotted-quad form is accepted.
        if (preg_match('/^[0-9.]+$/', $host) === 1 || preg_match('/^(0x[0-9a-f]*|[0-9]+)$/i', (string) $last) === 1) {
            if (! $this->isCanonicalIpv4($host)) {
                throw new InvalidUrlException('ambiguous_ip_host');
            }

            return [$host, $this->parsePort($portString, $scheme), true];
        }

        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63 || preg_match('/^[a-z0-9_]([a-z0-9\-_]*[a-z0-9_])?$/', $label) !== 1) {
                throw new InvalidUrlException('invalid_host');
            }
        }
        if (count($labels) < 2) {
            throw new InvalidUrlException('host_not_fqdn');
        }

        return [$host, $this->parsePort($portString, $scheme), false];
    }

    private function parsePort(?string $port, string $scheme): int
    {
        $default = $scheme === 'https' ? 443 : 80;
        if ($port === null || $port === '') {
            return $default;
        }
        if (preg_match('/^[0-9]{1,5}$/', $port) !== 1) {
            throw new InvalidUrlException('invalid_port');
        }
        $value = (int) $port;
        if ($value < 1 || $value > 65535) {
            throw new InvalidUrlException('invalid_port');
        }

        return $value;
    }

    private function isCanonicalIpv4(string $host): bool
    {
        $parts = explode('.', $host);
        if (count($parts) !== 4) {
            return false;
        }
        foreach ($parts as $p) {
            if ($p === '' || preg_match('/^(0|[1-9][0-9]{0,2})$/', $p) !== 1 || (int) $p > 255) {
                return false;
            }
        }

        return true;
    }

    /**
     * Canonical percent-encoding: decode unreserved characters, uppercase hex digits, encode
     * characters that are not allowed (including non-ASCII bytes and spaces), and escape stray '%'.
     */
    private function normalizeEncoding(string $value, string $allowedSubDelims): string
    {
        $out = '';
        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $c = $value[$i];
            if ($c === '%') {
                $hex = substr($value, $i + 1, 2);
                if (strlen($hex) === 2 && ctype_xdigit($hex)) {
                    $decoded = chr((int) hexdec($hex));
                    $out .= preg_match(self::UNRESERVED, $decoded) === 1 ? $decoded : '%'.strtoupper($hex);
                    $i += 2;

                    continue;
                }
                $out .= '%25';

                continue;
            }
            if (preg_match(self::UNRESERVED, $c) === 1 || str_contains($allowedSubDelims, $c)) {
                $out .= $c;

                continue;
            }
            $out .= '%'.strtoupper(bin2hex($c));
        }

        return $out;
    }

    /** RFC 3986 section 5.2.4. */
    private function removeDotSegments(string $path): string
    {
        $input = $path;
        $output = [];
        $segments = explode('/', $input);
        $trailing = in_array(end($segments), ['.', '..'], true);
        foreach ($segments as $index => $segment) {
            if ($index === 0) {
                continue; // leading empty segment of an absolute path
            }
            if ($segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($output);

                continue;
            }
            $output[] = $segment;
        }
        $result = '/'.implode('/', $output);
        if ($trailing && ! str_ends_with($result, '/')) {
            $result .= '/';
        }

        return $result;
    }
}
