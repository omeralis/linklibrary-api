<?php

namespace App\Models;

use App\Enums\GrantStatus;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $saved_link_id
 * @property int $grantor_user_id
 * @property int $grantee_user_id
 * @property int|null $parent_grant_id
 * @property int|null $invitation_id
 * @property int $depth
 * @property GrantStatus $status
 * @property Carbon|null $revoked_at
 * @property int|null $revoked_by_user_id
 * @property string|null $revoke_reason
 * @property Carbon $created_at
 * @property-read User $grantor
 * @property-read User $grantee
 * @property-read SavedLink $savedLink
 */
class LinkAccessGrant extends Model
{
    use HasPublicId;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => GrantStatus::class,
            'revoked_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === GrantStatus::ACTIVE;
    }

    /** @return BelongsTo<SavedLink, $this> */
    public function savedLink(): BelongsTo
    {
        return $this->belongsTo(SavedLink::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'grantor_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function grantee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'grantee_user_id');
    }

    /** @return BelongsTo<LinkAccessGrant, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(LinkAccessGrant::class, 'parent_grant_id');
    }

    /** @return HasMany<LinkAccessGrant, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(LinkAccessGrant::class, 'parent_grant_id');
    }
}
