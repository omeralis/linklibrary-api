<?php

namespace Tests\Unit\Http;

use App\Services\Http\CurlTransport;
use App\Services\Http\FetchException;
use App\Services\Http\PinnedRequest;
use App\Services\Url\UrlNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the real cURL transport against a local fixture server. The policy layer is bypassed on
 * purpose (the IP is pinned directly) to verify the transport's own limits.
 */
class CurlTransportTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;

    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        // Ask the OS for a free port.
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::$port = (int) substr(strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
        $router = __DIR__.'/../../Support/fixture-server/router.php';
        self::$server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:'.self::$port, $router],
            [['pipe', 'r'], ['file', $null = (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'), 'w'], ['file', $null, 'w']],
            $pipes,
        );
        for ($i = 0; $i < 50; $i++) {
            $fp = @fsockopen('127.0.0.1', self::$port);
            if ($fp !== false) {
                fclose($fp);
                break;
            }
            usleep(100_000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    private function request(string $path, int $maxBytes = 1024 * 1024, float $timeout = 5): PinnedRequest
    {
        $url = (new UrlNormalizer)->normalize('http://fixture.example:'.self::$port.$path);

        return new PinnedRequest($url, '127.0.0.1', ['User-Agent' => 'test'], $maxBytes, 2, $timeout);
    }

    public function test_fetches_through_pinned_ip(): void
    {
        $response = (new CurlTransport)->send($this->request('/ok'));
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('Fixture', $response->body);
        $this->assertSame('127.0.0.1', $response->primaryIp);
    }

    public function test_enforces_response_size_limit(): void
    {
        $this->expectExceptionObject(new FetchException(FetchException::RESPONSE_TOO_LARGE));
        (new CurlTransport)->send($this->request('/big', 1024 * 1024));
    }

    public function test_enforces_decompressed_size_limit(): void
    {
        try {
            (new CurlTransport)->send($this->request('/gzip-bomb', 1024 * 1024));
            $this->fail('Decompression bomb was not stopped');
        } catch (FetchException $e) {
            $this->assertSame(FetchException::RESPONSE_TOO_LARGE, $e->errorCode);
        }
    }

    public function test_enforces_timeout(): void
    {
        $started = microtime(true);
        try {
            (new CurlTransport)->send($this->request('/slow', 1024, 1));
            $this->fail('Timeout was not enforced');
        } catch (FetchException $e) {
            $this->assertSame(FetchException::TIMEOUT, $e->errorCode);
            $this->assertTrue($e->retryable);
        }
        $this->assertLessThan(2.5, microtime(true) - $started);
    }

    public function test_does_not_follow_redirects(): void
    {
        $response = (new CurlTransport)->send($this->request('/redirect'));
        $this->assertSame(302, $response->status);
        $this->assertSame('http://169.254.169.254/latest/meta-data/', $response->headers['location'][0]);
    }
}
