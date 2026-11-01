<?php

namespace App\Enums;

/** Aggregate processing state of a saved link, derived from security, extraction and AI states. */
enum ProcessingStatus: string
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
