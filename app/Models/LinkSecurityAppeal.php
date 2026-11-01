<?php

namespace App\Models;

use App\Enums\AppealStatus;
use App\Enums\SecurityStatus;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $link_id
 * @property int|null $saved_link_id
 * @property int $user_id
 * @property SecurityStatus $security_status_at_submission
 * @property string $reason
 * @property AppealStatus $status
 * @property int|null $reviewer_user_id
 * @property string|null $reviewer_notes
 * @property SecurityStatus|null $resolution_verdict
 * @property Carbon|null $resolved_at
 * @property Carbon $created_at
 * @property-read Link $link
 * @property-read User $user
 */
class LinkSecurityAppeal extends Model
{
    use HasPublicId;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => AppealStatus::class,
            'security_status_at_submission' => SecurityStatus::class,
            'resolution_verdict' => SecurityStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Link, $this> */
    public function link(): BelongsTo
    {
        return $this->belongsTo(Link::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<SavedLink, $this> */
    public function savedLink(): BelongsTo
    {
        return $this->belongsTo(SavedLink::class)->withTrashed();
    }
}
