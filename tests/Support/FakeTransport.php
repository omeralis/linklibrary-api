<?php

namespace Tests\Support;

use App\Services\Http\FetchException;
use App\Services\Http\HttpTransport;
use App\Services\Http\PinnedRequest;
use App\Services\Http\TransportResponse;
use Closure;

/**
 * In-memory transport: no network access ever happens in the test suite. Unknown URLs fail with a
 * retryable connection error.
 */
class FakeTransport implements HttpTransport
{
    /** @var array<string, TransportResponse|FetchException|Closure> */
    public array $routes = [];

    /** @var list<array{url: string, ip: string}> */
    public array $requests = [];

    public function on(string $url, int $status = 200, string $body = '', array $headers = []): self
    {
        $normalizedHeaders = [];
        foreach ($headers as $k => $v) {
            $normalizedHeaders[strtolower($k)] = (array) $v;
        }
        $this->routes[$url] = new TransportResponse($status, $normalizedHeaders, $body, null);

        return $this;
    }

    public function html(string $url, string $html): self
    {
        return $this->on($url, 200, $html, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function redirect(string $from, string $to, int $status = 302): self
    {
        return $this->on($from, $status, '', ['Location' => $to]);
    }

    public function fail(string $url, FetchException $e): self
    {
        $this->routes[$url] = $e;

        return $this;
    }

    public function send(PinnedRequest $request): TransportResponse
    {
        $url = $request->requestUrl();
        $this->requests[] = ['url' => $url, 'ip' => $request->ip];

        $route = $this->routes[$url] ?? null;
        if ($route instanceof Closure) {
            $route = $route($request);
        }
        if ($route instanceof FetchException) {
            throw $route;
        }
        if ($route === null) {
            if (str_ends_with($request->url->path, '/robots.txt')) {
                return new TransportResponse(404, [], '', $request->ip);
            }

            throw FetchException::retryable(FetchException::CONNECTION_FAILED, 'no fake route for '.$url);
        }
        if (strlen($route->body) > $request->maxBytes) {
            throw new FetchException(FetchException::RESPONSE_TOO_LARGE);
        }

        return new TransportResponse($route->status, $route->headers, $route->body, $request->ip);
    }

    /** @return list<string> */
    public function requestedUrls(): array
    {
        return array_column($this->requests, 'url');
    }
}
