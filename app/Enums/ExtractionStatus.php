<?php

namespace App\Enums;

enum ExtractionStatus: string
{
    case PENDING = 'PENDING';
    case PROCESSING = 'PROCESSING';
    case COMPLETED = 'COMPLETED';
    case PARTIAL = 'PARTIAL';
    case FAILED = 'FAILED';
    case BLOCKED = 'BLOCKED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
