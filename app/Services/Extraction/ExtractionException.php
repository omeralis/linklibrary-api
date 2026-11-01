<?php

namespace App\Services\Extraction;

use RuntimeException;

class ExtractionException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly bool $retryable = false, public readonly ?int $httpStatus = null)
    {
        parent::__construct($errorCode);
    }
}
