<?php

namespace App\Services\Url;

use InvalidArgumentException;

class InvalidUrlException extends InvalidArgumentException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Invalid URL: {$reason}");
    }
}
