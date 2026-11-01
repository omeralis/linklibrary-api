<?php

namespace Database\Factories;

use App\Enums\Visibility;
use App\Models\Link;
use App\Models\SavedLink;
use App\Models\User;
use App\Services\Search\SearchIndexer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SavedLink> */
class SavedLinkFactory extends Factory
{
    protected $model = SavedLink::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'link_id' => Link::factory(),
            'visibility' => Visibility::PRIVATE,
            'allow_resharing' => false,
            'is_favorite' => false,
        ];
    }

    public function public(): static
    {
        return $this->state(fn () => ['visibility' => Visibility::PUBLIC, 'published_at' => now()]);
    }

    public function resharable(): static
    {
        return $this->state(fn () => ['allow_resharing' => true]);
    }

    public function configure(): static
    {
        return $this->afterCreating(function (SavedLink $savedLink) {
            app(SearchIndexer::class)->reindex($savedLink);
        });
    }
}
