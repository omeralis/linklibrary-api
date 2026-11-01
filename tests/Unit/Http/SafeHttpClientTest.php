<?php

namespace Tests\Unit\Http;

use App\Services\Http\FetchException;
use App\Services\Http\IpAddressPolicy;
use App\Services\Http\SafeHttpClient;
use App\Services\Url\UrlNormalizer;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeDnsResolver;
use Tests\Support\FakeTransport;

class SafeHttpClientTest extends TestCase
{
    private FakeTransport $transport;

    private FakeDnsResolver $dns;

    private SafeHttpClient $client;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport;
        $this->dns = new FakeDnsResolver;
        $this->client = new SafeHttpClient(
            new UrlNormalizer, $this->dns, new IpAddressPolicy, $this->transport,
            'test-agent', 2, 5, 3, 1024, [80, 443, 8080, 8443], ['blocked.example.org'],
        );
    }

    private function assertBlocked(string $url, string $code): void
    {
        try {
            $this->client->get($url);
            $this->fail("Expected {$code} for {$url}");
        } catch (FetchException $e) {
            $this->assertSame($code, $e->errorCode, $e->getMessage());
        }
    }

    public function test_blocks_localhost_and_internal_names(): void
    {
        $this->assertBlocked('http://localhost/admin', FetchException::INVALID_URL); // single label → not FQDN
        $this->assertBlocked('http://app.localhost/', FetchException::BLOCKED_HOST);
        $this->assertBlocked('http://metadata.google.internal/computeMetadata/v1/', FetchException::BLOCKED_HOST);
        $this->assertBlocked('http://printer.local/', FetchException::BLOCKED_HOST);
        $this->assertBlocked('http://blocked.example.org/', FetchException::BLOCKED_HOST);
        $this->assertSame([], $this->transport->requests);
    }

    public function test_blocks_private_ip_literals(): void
    {
        foreach (['http://127.0.0.1/', 'http://10.0.0.5/', 'http://169.254.169.254/latest/meta-data/', 'http://[::1]/', 'http://[fd00:ec2::254]/', 'http://192.168.0.1:8080/'] as $url) {
            $this->assertBlocked($url, FetchException::BLOCKED_IP);
        }
        $this->assertSame([], $this->transport->requests);
    }

    public function test_blocks_hostnames_resolving_to_private_addresses(): void
    {
        $this->dns->set('internal.example.com', ['10.0.0.7']);
        $this->assertBlocked('https://internal.example.com/', FetchException::BLOCKED_IP);

        // A mixed answer (public + private) is treated as hostile.
        $this->dns->set('mixed.example.com', ['93.184.216.34', '127.0.0.1']);
        $this->assertBlocked('https://mixed.example.com/', FetchException::BLOCKED_IP);
        $this->assertSame([], $this->transport->requests);
    }

    public function test_blocks_unsupported_ports(): void
    {
        $this->assertBlocked('http://example.com:22/', FetchException::BLOCKED_PORT);
        $this->assertBlocked('http://example.com:6379/', FetchException::BLOCKED_PORT);
    }

    public function test_dns_rebinding_cannot_redirect_the_connection(): void
    {
        // First answer public, later answers private: the connection is pinned to the validated answer.
        $this->dns->set('rebind.example.com', ['93.184.216.34'], ['127.0.0.1']);
        $this->transport->html('https://rebind.example.com/', '<title>ok</title>');

        $response = $this->client->get('https://rebind.example.com/');

        $this->assertSame(200, $response->status);
        $this->assertSame('93.184.216.34', $this->transport->requests[0]['ip']);
        $this->assertSame(1, $this->dns->lookups['rebind.example.com']);
    }

    public function test_redirect_to_private_ip_is_blocked(): void
    {
        $this->transport->redirect('https://example.com/go', 'http://169.254.169.254/latest/meta-data/');
        $this->assertBlocked('https://example.com/go', FetchException::UNSAFE_REDIRECT); // https → http downgrade

        $this->transport->redirect('https://example.com/go2', 'https://10.0.0.1/admin');
        $this->assertBlocked('https://example.com/go2', FetchException::BLOCKED_IP);

        $this->dns->set('evil-internal.example.net', ['192.168.1.10']);
        $this->transport->redirect('https://example.com/go3', 'https://evil-internal.example.net/');
        $this->assertBlocked('https://example.com/go3', FetchException::BLOCKED_IP);
    }

    public function test_redirect_to_bad_scheme_is_blocked(): void
    {
        $this->transport->redirect('https://example.com/file', 'file:///etc/passwd');
        $this->assertBlocked('https://example.com/file', FetchException::INVALID_REDIRECT);
        $this->transport->redirect('https://example.com/gopher', 'gopher://example.com:70/');
        $this->assertBlocked('https://example.com/gopher', FetchException::INVALID_REDIRECT);
    }

    public function test_redirect_limit(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->transport->redirect("https://example.com/r{$i}", 'https://example.com/r'.($i + 1));
        }
        $this->assertBlocked('https://example.com/r0', FetchException::TOO_MANY_REDIRECTS);
    }

    public function test_follows_safe_redirects_and_records_chain(): void
    {
        $this->transport->redirect('https://example.com/old', '/new', 301);
        $this->transport->html('https://example.com/new', '<title>new</title>');

        $response = $this->client->get('https://example.com/old');

        $this->assertSame('https://example.com/new', $response->finalUrl->normalized);
        $this->assertSame(['https://example.com/old'], $response->redirectChain);
    }

    public function test_oversized_response_is_rejected(): void
    {
        $this->transport->on('https://example.com/huge', 200, str_repeat('x', 2048));
        $this->assertBlocked('https://example.com/huge', FetchException::RESPONSE_TOO_LARGE);
    }

    public function test_timeouts_surface_as_retryable(): void
    {
        $this->transport->fail('https://example.com/slow', FetchException::retryable(FetchException::TIMEOUT));
        try {
            $this->client->get('https://example.com/slow');
            $this->fail('expected timeout');
        } catch (FetchException $e) {
            $this->assertSame(FetchException::TIMEOUT, $e->errorCode);
            $this->assertTrue($e->retryable);
        }
    }

    public function test_query_strings_never_appear_in_error_messages(): void
    {
        $this->dns->set('internal.example.com', ['10.0.0.7']);
        try {
            $this->client->get('https://internal.example.com/?token=SECRET123');
        } catch (FetchException $e) {
            $this->assertStringNotContainsString('SECRET123', $e->getMessage());
        }
    }
}
