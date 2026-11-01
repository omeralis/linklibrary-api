<?php

namespace App\Http\Resources;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Category */
class CategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $ar = app()->getLocale() === 'ar';

        return [
            'slug' => $this->slug,
            'name' => $ar ? $this->name_ar : $this->name_en,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'description' => $ar ? $this->description_ar : $this->description_en,
            'description_ar' => $this->description_ar,
            'description_en' => $this->description_en,
            'parent_slug' => $this->whenLoaded('parent', fn () => $this->parent?->slug),
            'sort_order' => $this->sort_order,
            'is_active' => $this->when($request->user()?->isAdmin() === true, $this->is_active),
            'links_count' => $this->when(isset($this->resource->links_count), fn () => (int) $this->resource->links_count),
        ];
    }

    /** @return array<string, string>|null */
    public static function brief(?Category $category): ?array
    {
        if ($category === null) {
            return null;
        }

        return [
            'slug' => $category->slug,
            'name' => $category->localizedName(),
            'name_ar' => $category->name_ar,
            'name_en' => $category->name_en,
        ];
    }
}
