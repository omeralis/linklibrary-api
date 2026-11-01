<?php

namespace App\Services\Http;

/**
 * Performs exactly one HTTP request to an already-validated, pinned IP address. Implementations must
 * not follow redirects, must not use proxies implicitly and must enforce the size limit.
 */
interface HttpTransport
{
    public function send(PinnedRequest $request): TransportResponse;
}
