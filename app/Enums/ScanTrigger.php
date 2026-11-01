<?php

namespace App\Enums;

enum ScanTrigger: string
{
    case INITIAL = 'INITIAL';
    case RESCAN_REQUEST = 'RESCAN_REQUEST';
    case PERIODIC = 'PERIODIC';
    case REDIRECT = 'REDIRECT';
    case APPEAL = 'APPEAL';
    case MANUAL = 'MANUAL';
    case AI_SIGNAL = 'AI_SIGNAL';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
