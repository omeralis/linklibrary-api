<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Shared tag vocabulary entry. Associations are per saved link (private to the link owner).
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property-read Pivot $pivot  (only when loaded through a belongsToMany)
 */
class Tag extends Model
{
    /** @var list<string> */
    protected $fillable = ['name', 'slug'];

    /** @return BelongsToMany<SavedLink, $this> */
    public function savedLinks(): BelongsToMany
    {
        return $this->belongsToMany(SavedLink::class, 'saved_link_tags')->withPivot(['source', 'created_at']);
    }
}
