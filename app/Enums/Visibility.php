<?php

namespace App\Enums;

enum Visibility: string
{
    case PRIVATE = 'PRIVATE';
    case PUBLIC = 'PUBLIC';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
