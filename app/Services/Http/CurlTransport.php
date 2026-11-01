<?php

namespace App\Services\Http;

/**
 * cURL transport pinned to a validated IP via CURLOPT_RESOLVE, so the connection can never be
 * re-pointed by a second DNS answer (DNS rebinding). Proxies are disabled, redirects are not
 * followed, only HTTP/HTTPS are permitted, and the decoded body size is capped while streaming
 * (protects against decompression bombs).
 */
class CurlTransport implements HttpTransport
{
    public function __construct(private readonly ?string $proxy = null) {}

    public function send(PinnedRequest $request): TransportResponse
    {
        $ch = curl_init();
        if ($ch === false) {
            throw FetchException::retryable(FetchException::CONNECTION_FAILED, 'curl_init failed');
        }

        $url = $request->url;
        $ipForResolve = str_contains($request->ip, ':') ? '['.$request->ip.']' : $request->ip;
        $headers = [];
        $body = '';
        $tooLarge = false;

        $headerLines = [];
        foreach ($request->headers as $name => $value) {
            $headerLines[] = $name.': '.$value;
        }
        $headerLines[] = 'Connection: close';

        curl_setopt_array($ch, [
            CURLOPT_URL => $request->requestUrl(),
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_NOBODY => $request->method === 'HEAD',
            CURLOPT_RESOLVE => [$url->host.':'.$url->port.':'.$ipForResolve],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($request->connectTimeout * 1000),
            CURLOPT_TIMEOUT_MS => (int) ($request->timeout * 1000),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ACCEPT_ENCODING => 'gzip, deflate',
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_MAXFILESIZE => $request->maxBytes,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$headers): int {
                $trimmed = trim($line);
                if (str_starts_with($trimmed, 'HTTP/')) {
                    $headers = []; // new response block (e.g. 100 Continue)
                } elseif ($trimmed !== '' && str_contains($trimmed, ':')) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $headers[strtolower(trim($name))][] = trim($value);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$body, &$tooLarge, $request): int {
                if (strlen($body) + strlen($chunk) > $request->maxBytes) {
                    $tooLarge = true;

                    return 0; // aborts the transfer (CURLE_WRITE_ERROR)
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);

        if ($this->proxy !== null && $this->proxy !== '') {
            // Only an explicitly configured egress proxy (which must enforce its own SSRF policy).
            curl_setopt($ch, CURLOPT_PROXY, $this->proxy);
        } else {
            curl_setopt($ch, CURLOPT_PROXY, '');
            curl_setopt($ch, CURLOPT_NOPROXY, '*');
        }

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $primaryIp = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        unset($ch); // curl_close() is a deprecated no-op since PHP 8.5; the handle is freed here

        if ($tooLarge || $errno === CURLE_FILESIZE_EXCEEDED) {
            throw new FetchException(FetchException::RESPONSE_TOO_LARGE);
        }
        if ($ok === false || $errno !== 0) {
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw FetchException::retryable(FetchException::TIMEOUT);
            }

            throw FetchException::retryable(FetchException::CONNECTION_FAILED, 'curl error '.$errno);
        }

        if ($this->proxy === null || $this->proxy === '') {
            $expected = @inet_pton($request->ip);
            $actual = $primaryIp !== '' ? @inet_pton($primaryIp) : false;
            if ($expected === false || $actual === false || $expected !== $actual) {
                throw new FetchException(FetchException::IP_MISMATCH);
            }
        }

        return new TransportResponse($status, $headers, $body, $primaryIp !== '' ? $primaryIp : null);
    }
}
