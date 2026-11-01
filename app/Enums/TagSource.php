<?php

namespace App\Enums;

enum TagSource: string
{
    case USER = 'USER';
    case AI = 'AI';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
