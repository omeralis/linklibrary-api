<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Operational record of one extraction attempt (safe diagnostics only).
 *
 * @property int $id
 * @property int $link_id
 * @property int $extraction_version
 * @property int $attempt
 * @property string $status
 * @property string|null $extractor
 * @property int|null $http_status
 * @property string|null $error_code
 * @property array<string, mixed>|null $diagnostics
 */
class LinkExtractionJob extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'diagnostics' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
