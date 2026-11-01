<?php

namespace App\Services\Links;

use App\Enums\TagSource;
use App\Models\SavedLink;
use App\Models\Tag;
use App\Support\ArabicNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Tag policy: tags form a shared, normalised vocabulary (one row per slug), but every association is
 * per saved link, so a user's tagging is private to that user. Public tag listings only expose tags
 * attached to publicly eligible links.
 */
class TagService
{
    public const MAX_TAGS_PER_LINK = 20;

    public static function slug(string $name): string
    {
        $name = ArabicNormalizer::normalize(trim($name));
        $name = (string) preg_replace('/[^\p{L}\p{N}\s\-_]+/u', '', $name);
        $name = (string) preg_replace('/[\s_]+/u', '-', trim($name));
        $name = (string) preg_replace('/-+/', '-', $name);

        return trim(mb_substr($name, 0, 60), '-');
    }

    public static function displayName(string $name): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $name)), 0, 50);
    }

    /**
     * @param  list<string>  $names
     * @return list<int> tag ids
     */
    public function resolve(array $names): array
    {
        $ids = [];
        foreach ($names as $name) {
            $slug = self::slug($name);
            if ($slug === '') {
                continue;
            }
            $tag = Tag::query()->firstOrCreate(['slug' => $slug], ['name' => self::displayName($name)]);
            $ids[$tag->id] = $tag->id;
        }

        return array_values($ids);
    }

    /**
     * Replace the user's own tags on a saved link (AI tags are kept unless also given by the user).
     *
     * @param  list<string>  $names
     */
    public function syncUserTags(SavedLink $savedLink, array $names): void
    {
        $ids = array_slice($this->resolve($names), 0, self::MAX_TAGS_PER_LINK);

        DB::transaction(function () use ($savedLink, $ids) {
            DB::table('saved_link_tags')
                ->where('saved_link_id', $savedLink->id)
                ->where('source', TagSource::USER->value)
                ->whereNotIn('tag_id', $ids ?: [0])
                ->delete();

            foreach ($ids as $id) {
                DB::table('saved_link_tags')->upsert(
                    [['saved_link_id' => $savedLink->id, 'tag_id' => $id, 'source' => TagSource::USER->value, 'created_at' => now()]],
                    ['saved_link_id', 'tag_id'],
                    ['source'],
                );
            }
        });
    }

    /** @param list<string> $names */
    public function addUserTags(SavedLink $savedLink, array $names): void
    {
        $existing = DB::table('saved_link_tags')->where('saved_link_id', $savedLink->id)->count();
        $ids = array_slice($this->resolve($names), 0, max(0, self::MAX_TAGS_PER_LINK - $existing));
        foreach ($ids as $id) {
            DB::table('saved_link_tags')->upsert(
                [['saved_link_id' => $savedLink->id, 'tag_id' => $id, 'source' => TagSource::USER->value, 'created_at' => now()]],
                ['saved_link_id', 'tag_id'],
                ['source'],
            );
        }
    }

    /** @param list<string> $slugs */
    public function removeTags(SavedLink $savedLink, array $slugs): void
    {
        $normalized = array_map(fn ($s) => self::slug($s), $slugs);
        $tagIds = Tag::query()->whereIn('slug', $normalized)->pluck('id');
        DB::table('saved_link_tags')->where('saved_link_id', $savedLink->id)->whereIn('tag_id', $tagIds)->delete();
    }

    /**
     * Replace AI-sourced tags with a new suggestion set. User-sourced tags are never touched, and a tag
     * the user already has keeps its USER source.
     *
     * @param  list<string>  $names
     */
    public function replaceAiTags(SavedLink $savedLink, array $names): void
    {
        $ids = array_slice($this->resolve($names), 0, 8);
        DB::transaction(function () use ($savedLink, $ids) {
            DB::table('saved_link_tags')->where('saved_link_id', $savedLink->id)->where('source', TagSource::AI->value)->delete();
            foreach ($ids as $id) {
                DB::table('saved_link_tags')->insertOrIgnore([
                    'saved_link_id' => $savedLink->id,
                    'tag_id' => $id,
                    'source' => TagSource::AI->value,
                    'created_at' => now(),
                ]);
            }
        });
    }
}
