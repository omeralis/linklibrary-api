<?php

namespace App\Services\Search;

use App\Models\SavedLink;
use App\Support\ArabicNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Maintains the two tsvector documents of a saved link:
 *  - search_public: content visible to anyone allowed to see the link (title, description, tags,
 *    category, AI summary, site, author, host);
 *  - search_private: owner-only content (notes). Only ever matched in the owner's own searches.
 *
 * Every text is indexed with three configurations: 'simple' (exact tokens, any language, prefix
 * search), 'english' (stemming) and 'arabic' (Snowball stemming over orthographically folded text).
 */
class SearchIndexer
{
    /** @param iterable<int> $savedLinkIds */
    public function reindexMany(iterable $savedLinkIds): void
    {
        foreach ($savedLinkIds as $id) {
            $savedLink = SavedLink::withTrashed()->with(['link.currentAiAnalysis', 'category', 'aiCategory', 'tags'])->find($id);
            if ($savedLink !== null) {
                $this->reindex($savedLink);
            }
        }
    }

    public function reindexForLink(int $linkId): void
    {
        $this->reindexMany(SavedLink::withTrashed()->where('link_id', $linkId)->pluck('id')->all());
    }

    public function reindex(SavedLink $savedLink): void
    {
        $savedLink->loadMissing(['link.currentAiAnalysis', 'category', 'aiCategory', 'tags']);
        $link = $savedLink->link;
        $category = $savedLink->displayCategory();

        $a = $this->prep($savedLink->displayTitle());
        $b = $this->prep(implode(' ', array_filter([
            $savedLink->displayDescription(),
            $savedLink->tags->pluck('name')->implode(' '),
            $category?->name_en,
            $category?->name_ar,
        ])));
        $c = $this->prep(implode(' ', array_filter([
            $link->currentAiAnalysis?->short_summary,
            $link->site_name,
            $link->author,
            $link->hostname,
        ])));
        $private = $this->prep((string) $savedLink->notes);

        DB::update(<<<'SQL'
            UPDATE saved_links SET
              search_public =
                  setweight(to_tsvector('simple', ?), 'A') || setweight(to_tsvector('english', ?), 'A') || setweight(to_tsvector('arabic', ?), 'A')
               || setweight(to_tsvector('simple', ?), 'B') || setweight(to_tsvector('english', ?), 'B') || setweight(to_tsvector('arabic', ?), 'B')
               || setweight(to_tsvector('simple', ?), 'C') || setweight(to_tsvector('english', ?), 'C') || setweight(to_tsvector('arabic', ?), 'C'),
              search_private = to_tsvector('simple', ?) || to_tsvector('english', ?) || to_tsvector('arabic', ?)
            WHERE id = ?
        SQL, [$a, $a, $a, $b, $b, $b, $c, $c, $c, $private, $private, $private, $savedLink->id]);
    }

    private function prep(?string $text): string
    {
        return mb_substr(ArabicNormalizer::normalize((string) $text), 0, 20000);
    }
}
