<?php

namespace App\Enums;

enum GrantStatus: string
{
    case ACTIVE = 'ACTIVE';
    case REVOKED = 'REVOKED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
