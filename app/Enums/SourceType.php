<?php

namespace App\Enums;

enum SourceType: string
{
    case WEBSITE = 'WEBSITE';
    case ARTICLE = 'ARTICLE';
    case VIDEO = 'VIDEO';
    case PLAYLIST = 'PLAYLIST';
    case PDF = 'PDF';
    case REPOSITORY = 'REPOSITORY';
    case IMAGE = 'IMAGE';
    case AUDIO = 'AUDIO';
    case DOCUMENT = 'DOCUMENT';
    case OTHER = 'OTHER';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
