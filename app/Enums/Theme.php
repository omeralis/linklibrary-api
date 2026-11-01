<?php

namespace App\Enums;

enum Theme: string
{
    case system = 'system';
    case light = 'light';
    case dark = 'dark';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
