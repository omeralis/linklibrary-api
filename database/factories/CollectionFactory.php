<?php

namespace Database\Factories;

use App\Enums\Visibility;
use App\Models\Collection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Collection> */
class CollectionFactory extends Factory
{
    protected $model = Collection::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'name' => fake()->words(3, true), 'visibility' => Visibility::PRIVATE, 'sort_order' => 0];
    }

    public function public(): static
    {
        return $this->state(fn () => ['visibility' => Visibility::PUBLIC]);
    }
}
