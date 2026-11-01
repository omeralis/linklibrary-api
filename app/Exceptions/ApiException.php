<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * Domain error rendered as the standard error envelope:
 * { success: false, message, errors, code }.
 *
 * These are expected client errors (4xx), so they are never written to the error log.
 *
 * $messageKey is a translation key under lang/{locale}/api.php so messages are locale-aware.
 */
class ApiException extends RuntimeException implements ShouldntReport
{
    /**
     * @param  array<string, mixed>  $errors
     * @param  array<string, string|int>  $replace
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        public readonly string $messageKey,
        public readonly array $errors = [],
        public readonly array $replace = [],
    ) {
        parent::__construct($messageKey);
    }

    public static function notFound(): self
    {
        return new self('NOT_FOUND', 404, 'api.errors.not_found');
    }

    public static function forbidden(string $key = 'api.errors.forbidden'): self
    {
        return new self('FORBIDDEN', 403, $key);
    }

    /** @param array<string, mixed> $errors */
    public static function conflict(string $code, string $key, array $errors = []): self
    {
        return new self($code, 409, $key, $errors);
    }

    /** @param array<string, mixed> $errors */
    public static function unprocessable(string $code, string $key, array $errors = []): self
    {
        return new self($code, 422, $key, $errors);
    }
}
