<?php

namespace App\Enums;

/**
 * Security verdict for a canonical URL.
 *
 * PENDING / UNKNOWN  -> private draft only
 * CLEAR              -> eligible for publication and sharing (while not expired)
 * SUSPICIOUS         -> no publication, no new sharing
 * MALICIOUS          -> no publication, no sharing, no platform-mediated opening
 */
enum SecurityStatus: string
{
    case PENDING = 'PENDING';
    case CLEAR = 'CLEAR';
    case SUSPICIOUS = 'SUSPICIOUS';
    case MALICIOUS = 'MALICIOUS';
    case UNKNOWN = 'UNKNOWN';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isUnsafe(): bool
    {
        return $this === self::SUSPICIOUS || $this === self::MALICIOUS;
    }

    /** Severity used when aggregating several verdicts (higher wins). */
    public function severity(): int
    {
        return match ($this) {
            self::MALICIOUS => 4,
            self::SUSPICIOUS => 3,
            self::UNKNOWN => 2,
            self::PENDING => 1,
            self::CLEAR => 0,
        };
    }
}
