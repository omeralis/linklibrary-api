<?php

namespace Tests\Unit\Url;

use App\Services\Url\InvalidUrlException;
use App\Services\Url\UrlNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UrlNormalizerTest extends TestCase
{
    private UrlNormalizer $n;

    protected function setUp(): void
    {
        $this->n = new UrlNormalizer(2048, true);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function validUrls(): array
    {
        return [
            'lowercases scheme and host' => ['HTTPS://Example.COM/Path', 'https://example.com/Path'],
            'adds root path' => ['https://example.com', 'https://example.com/'],
            'removes default https port' => ['https://example.com:443/a', 'https://example.com/a'],
            'removes default http port' => ['http://example.com:80/a', 'http://example.com/a'],
            'keeps non default port' => ['https://example.com:8443/a', 'https://example.com:8443/a'],
            'resolves dot segments' => ['https://example.com/a/./b/../c', 'https://example.com/a/c'],
            'preserves query order and tracking params' => ['https://example.com/p?b=2&a=1&utm_source=x', 'https://example.com/p?b=2&a=1&utm_source=x'],
            'drops plain fragment' => ['https://example.com/page#section-2', 'https://example.com/page'],
            'keeps hash-route fragment' => ['https://example.com/#/app/route', 'https://example.com/#/app/route'],
            'uppercases percent hex and decodes unreserved' => ['https://example.com/%7euser/%2f%41', 'https://example.com/~user/%2FA'],
            'encodes spaces and non ascii path' => ['https://example.com/a b/مرحبا', 'https://example.com/a%20b/%D9%85%D8%B1%D8%AD%D8%A8%D8%A7'],
            'idn to punycode' => ['https://مثال.إختبار/', 'https://xn--mgbh0fb.xn--kgbechtv/'],
            'trailing dot removed' => ['https://example.com./x', 'https://example.com/x'],
            'ipv4 literal' => ['http://93.184.216.34/x', 'http://93.184.216.34/x'],
            'ipv6 literal compressed' => ['http://[2606:2800:0220:0001:0248:1893:25c8:1946]/', 'http://[2606:2800:220:1:248:1893:25c8:1946]/'],
            'stray percent escaped' => ['https://example.com/100%', 'https://example.com/100%25'],
        ];
    }

    #[DataProvider('validUrls')]
    public function test_normalizes(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->n->normalize($input)->normalized);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function invalidUrls(): array
    {
        return [
            'javascript scheme' => ['javascript:alert(1)', 'unsupported_scheme'],
            'file scheme' => ['file:///etc/passwd', 'unsupported_scheme'],
            'ftp scheme' => ['ftp://example.com/', 'unsupported_scheme'],
            'data scheme' => ['data:text/html,hi', 'unsupported_scheme'],
            'no scheme' => ['example.com/path', 'missing_scheme'],
            'credentials' => ['https://user:pass@example.com/', 'credentials_not_allowed'],
            'credentials no password' => ['https://user@example.com/', 'credentials_not_allowed'],
            'control char' => ["https://example.com/\x00a", 'control_characters'],
            'bidi override' => ["https://example.com/\u{202E}gpj.exe", 'control_characters'],
            'backslash' => ['https://example.com\\@evil.com/', 'backslash'],
            'decimal ip' => ['http://2130706433/', 'ambiguous_ip_host'],
            'octal ip' => ['http://0177.0.0.1/', 'ambiguous_ip_host'],
            'hex ip' => ['http://0x7f.0.0.1/', 'ambiguous_ip_host'],
            'short ip' => ['http://127.1/', 'ambiguous_ip_host'],
            'bad port' => ['https://example.com:99999/', 'invalid_port'],
            'port zero' => ['https://example.com:0/', 'invalid_port'],
            'single label' => ['http://intranet/', 'host_not_fqdn'],
            'empty' => ['   ', 'empty'],
            'opaque http' => ['http:example.com', 'malformed'],
            'zone id' => ['http://[fe80::1%25eth0]/', 'invalid_host'],
            'too long' => ['https://example.com/'.str_repeat('a', 2100), 'too_long'],
            'percent in host' => ['https://exa%6Dple.com/', 'invalid_host'],
        ];
    }

    #[DataProvider('invalidUrls')]
    public function test_rejects(string $input, string $reason): void
    {
        try {
            $this->n->normalize($input);
            $this->fail("Expected rejection ({$reason}) for {$input}");
        } catch (InvalidUrlException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function test_hash_is_deterministic_for_equivalent_urls(): void
    {
        $a = $this->n->normalize('HTTPS://Example.com:443/a/../b?x=1#frag');
        $b = $this->n->normalize('https://example.com/b?x=1');
        $this->assertSame($a->hash(), $b->hash());
        $this->assertSame(64, strlen($a->hash()));
    }

    public function test_original_is_preserved(): void
    {
        $u = $this->n->normalize('  https://Example.com/a#frag  ');
        $this->assertSame('https://Example.com/a#frag', $u->original);
    }

    public function test_resolves_relative_redirects(): void
    {
        $base = $this->n->normalize('https://example.com/a/b?q=1');
        $this->assertSame('https://example.com/a/c', $this->n->resolve($base, 'c')->normalized);
        $this->assertSame('https://example.com/root', $this->n->resolve($base, '/root')->normalized);
        $this->assertSame('https://other.org/x', $this->n->resolve($base, '//other.org/x')->normalized);
        $this->assertSame('https://example.com/a/b?z=2', $this->n->resolve($base, '?z=2')->normalized);
        $this->assertSame('http://plain.example/', $this->n->resolve($base, 'http://plain.example')->normalized);
    }

    public function test_safe_for_logs_strips_query(): void
    {
        $u = $this->n->normalize('https://example.com/path?token=secret');
        $this->assertSame('https://example.com/path', $u->safeForLogs());
    }
}
