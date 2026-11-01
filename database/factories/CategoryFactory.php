<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Category> */
class CategoryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $slug = 'cat-'.Str::lower(Str::random(8));

        return ['slug' => $slug, 'name_ar' => 'تصنيف '.$slug, 'name_en' => 'Category '.$slug, 'sort_order' => 0, 'is_active' => true];
    }
}
