<?php

namespace App\Models;

use App\Enums\InvitationStatus;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $saved_link_id
 * @property int $inviter_user_id
 * @property int|null $inviter_grant_id
 * @property string $email
 * @property string $token_hash
 * @property InvitationStatus $status
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property int|null $accepted_by_user_id
 * @property Carbon|null $revoked_at
 * @property int|null $revoked_by_user_id
 * @property Carbon $created_at
 * @property-read SavedLink $savedLink
 * @property-read User $inviter
 */
class LinkInvitation extends Model
{
    use HasPublicId;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => InvitationStatus::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isPending(): bool
    {
        return $this->status === InvitationStatus::PENDING && $this->expires_at->isFuture();
    }

    /** Status as observed now (pending invitations past expiry report EXPIRED). */
    public function effectiveStatus(): InvitationStatus
    {
        if ($this->status === InvitationStatus::PENDING && ! $this->expires_at->isFuture()) {
            return InvitationStatus::EXPIRED;
        }

        return $this->status;
    }

    /** @return BelongsTo<SavedLink, $this> */
    public function savedLink(): BelongsTo
    {
        return $this->belongsTo(SavedLink::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_user_id');
    }

    /** @return BelongsTo<LinkAccessGrant, $this> */
    public function inviterGrant(): BelongsTo
    {
        return $this->belongsTo(LinkAccessGrant::class, 'inviter_grant_id');
    }
}
