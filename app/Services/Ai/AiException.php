<?php

namespace App\Services\Ai;

use RuntimeException;

class AiException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly bool $retryable = false)
    {
        parent::__construct($errorCode);
    }
}
