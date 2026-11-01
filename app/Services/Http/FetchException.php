<?php

namespace App\Services\Http;

use RuntimeException;

/**
 * Safe, machine-readable fetch failure. The message never contains query strings or bodies.
 */
class FetchException extends RuntimeException
{
    public const BLOCKED_SCHEME = 'BLOCKED_SCHEME';

    public const BLOCKED_PORT = 'BLOCKED_PORT';

    public const BLOCKED_HOST = 'BLOCKED_HOST';

    public const BLOCKED_IP = 'BLOCKED_IP';

    public const DNS_FAILURE = 'DNS_FAILURE';

    public const TOO_MANY_REDIRECTS = 'TOO_MANY_REDIRECTS';

    public const INVALID_REDIRECT = 'INVALID_REDIRECT';

    public const UNSAFE_REDIRECT = 'UNSAFE_REDIRECT';

    public const RESPONSE_TOO_LARGE = 'RESPONSE_TOO_LARGE';

    public const TIMEOUT = 'TIMEOUT';

    public const CONNECTION_FAILED = 'CONNECTION_FAILED';

    public const IP_MISMATCH = 'IP_MISMATCH';

    public const INVALID_URL = 'INVALID_URL';

    public const ROBOTS_DISALLOWED = 'ROBOTS_DISALLOWED';

    public function __construct(public readonly string $errorCode, string $detail = '', public readonly bool $retryable = false)
    {
        parent::__construct($errorCode.($detail !== '' ? ': '.$detail : ''));
    }

    public static function retryable(string $code, string $detail = ''): self
    {
        return new self($code, $detail, true);
    }
}
