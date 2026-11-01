<?php

namespace App\Models;

use App\Enums\Visibility;
use App\Models\Concerns\HasPublicId;
use Database\Factories\CollectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property string $name
 * @property string|null $description
 * @property Visibility $visibility
 * @property string|null $cover_image_url
 * @property int $sort_order
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 */
class Collection extends Model
{
    /** @use HasFactory<CollectionFactory> */
    use HasFactory, HasPublicId;

    /** @var list<string> */
    protected $fillable = ['name', 'description', 'visibility', 'cover_image_url', 'sort_order'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['visibility' => Visibility::class];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CollectionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CollectionItem::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Membership is organisational only; it never grants access to a saved link.
     *
     * @return BelongsToMany<SavedLink, $this>
     */
    public function savedLinks(): BelongsToMany
    {
        return $this->belongsToMany(SavedLink::class, 'collection_items')
            ->withPivot(['position', 'created_at'])
            ->orderBy('collection_items.position')
            ->orderBy('collection_items.id');
    }

    public function isPublic(): bool
    {
        return $this->visibility === Visibility::PUBLIC;
    }
}
