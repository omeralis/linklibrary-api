<?php

namespace App\Models;

use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Enums\SecurityStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $link_id
 * @property ScanTrigger $trigger
 * @property ScanStatus $status
 * @property SecurityStatus|null $verdict
 * @property array<int, array<string, mixed>>|null $provider_results
 * @property int|null $requested_by_user_id
 * @property string|null $error_code
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 */
class LinkSecurityScan extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'trigger' => ScanTrigger::class,
            'status' => ScanStatus::class,
            'verdict' => SecurityStatus::class,
            'provider_results' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Link, $this> */
    public function link(): BelongsTo
    {
        return $this->belongsTo(Link::class);
    }
}
