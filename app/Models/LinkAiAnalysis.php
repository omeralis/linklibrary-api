<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * AI analysis of a canonical link, generated only from public, extracted source content.
 *
 * @property int $id
 * @property int $link_id
 * @property int $ai_version
 * @property string $status
 * @property string $provider
 * @property string|null $model
 * @property string|null $input_hash
 * @property string|null $suggested_title
 * @property string|null $short_summary
 * @property string|null $detailed_summary
 * @property string|null $suggested_category_slug
 * @property int|null $category_id
 * @property list<string>|null $suggested_tags
 * @property string|null $language
 * @property string|null $content_type
 * @property bool $suspicious
 * @property list<string>|null $suspicious_reasons
 * @property string|null $error_code
 * @property Carbon|null $completed_at
 */
class LinkAiAnalysis extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'suggested_tags' => 'array',
            'suspicious_reasons' => 'array',
            'suspicious' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
