<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $collection_id
 * @property int $saved_link_id
 * @property int $user_id
 * @property int $position
 */
class CollectionItem extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return BelongsTo<Collection, $this> */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    /** @return BelongsTo<SavedLink, $this> */
    public function savedLink(): BelongsTo
    {
        return $this->belongsTo(SavedLink::class);
    }
}
