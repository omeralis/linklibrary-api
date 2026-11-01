<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\GrantStatus;
use App\Enums\SecurityStatus;
use App\Enums\SourceType;
use App\Enums\Visibility;
use App\Models\Concerns\HasPublicId;
use Database\Factories\SavedLinkFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A user's private instance of a canonical link.
 *
 * Metadata precedence: user override > verified extracted metadata > AI suggestion > fallback.
 *
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property int $link_id
 * @property string|null $custom_title
 * @property string|null $custom_description
 * @property string|null $custom_image_url
 * @property string|null $custom_image_path
 * @property SourceType|null $custom_source_type
 * @property string|null $notes
 * @property Visibility $visibility
 * @property bool $allow_resharing
 * @property bool $is_favorite
 * @property int|null $category_id
 * @property int|null $ai_category_id
 * @property int|null $ai_applied_analysis_id
 * @property int $popularity_score
 * @property Carbon|null $published_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 * @property-read Link $link
 * @property-read Category|null $category
 * @property-read Category|null $aiCategory
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Tag> $tags
 */
class SavedLink extends Model
{
    /** @use HasFactory<SavedLinkFactory> */
    use HasFactory, HasPublicId, SoftDeletes;

    /** Only user-editable content fields; visibility/sharing/ownership are changed through actions. */
    /** @var list<string> */
    protected $fillable = [
        'custom_title',
        'custom_description',
        'custom_image_url',
        'custom_source_type',
        'notes',
        'category_id',
    ];

    /** @var list<string> */
    protected $hidden = ['search_public', 'search_private'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'visibility' => Visibility::class,
            'custom_source_type' => SourceType::class,
            'allow_resharing' => 'boolean',
            'is_favorite' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Link, $this> */
    public function link(): BelongsTo
    {
        return $this->belongsTo(Link::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function aiCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'ai_category_id');
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'saved_link_tags')->withPivot(['source', 'created_at']);
    }

    /** @return HasMany<LinkAccessGrant, $this> */
    public function grants(): HasMany
    {
        return $this->hasMany(LinkAccessGrant::class);
    }

    /** @return HasMany<LinkAccessGrant, $this> */
    public function activeGrants(): HasMany
    {
        return $this->grants()->where('status', GrantStatus::ACTIVE->value);
    }

    /** @return HasMany<LinkInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(LinkInvitation::class);
    }

    /** @return BelongsToMany<Collection, $this> */
    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class, 'collection_items');
    }

    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && $this->user_id === $user->id;
    }

    public function isPublic(): bool
    {
        return $this->visibility === Visibility::PUBLIC;
    }

    /** Public AND currently eligible under the security policy AND owned by an active account. */
    public function isPubliclyEligible(): bool
    {
        return $this->isPublic()
            && ! $this->trashed()
            && $this->link->isClear()
            && $this->user->isActive();
    }

    public function displayTitle(): string
    {
        /** @var LinkAiAnalysis|null $analysis */
        $analysis = $this->link->getRelationValue('currentAiAnalysis');

        return $this->custom_title
            ?? $this->link->title
            ?? $analysis->suggested_title
            ?? $this->link->hostname;
    }

    public function displayDescription(): ?string
    {
        /** @var LinkAiAnalysis|null $analysis */
        $analysis = $this->link->getRelationValue('currentAiAnalysis');

        return $this->custom_description
            ?? $this->link->description
            ?? $analysis?->short_summary;
    }

    public function displayImageUrl(): ?string
    {
        return $this->customImagePublicUrl() ?? $this->custom_image_url ?? $this->link->image_url;
    }

    public function customImagePublicUrl(): ?string
    {
        if ($this->custom_image_path === null) {
            return null;
        }

        return Storage::disk(config('linklibrary.uploads.disk'))->url($this->custom_image_path);
    }

    public function displaySourceType(): SourceType
    {
        return $this->custom_source_type ?? $this->link->source_type;
    }

    public function displayCategory(): ?Category
    {
        return $this->category ?? $this->aiCategory;
    }

    /**
     * Saved links that may be shown to anyone: PUBLIC, not deleted, currently CLEAR (not expired),
     * owned by an active account.
     *
     * @param  Builder<SavedLink>  $query
     * @return Builder<SavedLink>
     */
    public function scopePubliclyEligible(Builder $query): Builder
    {
        return $query
            ->where('saved_links.visibility', Visibility::PUBLIC->value)
            ->whereNull('saved_links.deleted_at')
            ->whereHas('link', fn (Builder $q) => $q
                ->where('security_status', SecurityStatus::CLEAR->value)
                ->where('security_expires_at', '>', now()))
            ->whereHas('user', fn (Builder $q) => $q->where('status', AccountStatus::ACTIVE->value));
    }

    /**
     * Eager loads needed by the API resources (prevents N+1).
     *
     * @param  Builder<SavedLink>  $query
     * @return Builder<SavedLink>
     */
    public function scopeWithPresentation(Builder $query): Builder
    {
        return $query->with(['link.currentAiAnalysis', 'user', 'category', 'aiCategory', 'tags']);
    }
}
