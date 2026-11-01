<?php

namespace App\Enums;

enum Locale: string
{
    case ar = 'ar';
    case en = 'en';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
