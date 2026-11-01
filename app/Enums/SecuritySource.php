<?php

namespace App\Enums;

enum SecuritySource: string
{
    case PROVIDER = 'PROVIDER';
    case MANUAL_REVIEW = 'MANUAL_REVIEW';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
