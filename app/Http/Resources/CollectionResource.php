<?php

namespace App\Http\Resources;

use App\Models\Collection;
use App\Models\SavedLink;
use App\Services\Sharing\LinkViewContext;
use App\Support\FrontendUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Collection */
class CollectionResource extends JsonResource
{
    public bool $asOwner = true;

    /** @var iterable<SavedLink>|null */
    public ?iterable $items = null;

    public function asPublic(): self
    {
        $this->asOwner = false;

        return $this;
    }

    /** @param iterable<SavedLink> $items */
    public function withItems(iterable $items): self
    {
        $this->items = $items;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = [
            'public_id' => $this->public_id,
            'frontend_url' => FrontendUrl::collection($this->public_id),
            'name' => $this->name,
            'description' => $this->description,
            'cover_image_url' => $this->cover_image_url,
            'items_count' => isset($this->resource->items_count) ? (int) $this->resource->items_count : null,
            'owner' => PublicUserResource::summary($this->user),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];

        if ($this->asOwner) {
            $data['visibility'] = $this->visibility->value;
            $data['sort_order'] = $this->sort_order;
        }

        if ($this->items !== null) {
            $role = $this->asOwner ? LinkViewContext::OWNER : LinkViewContext::PUBLIC;
            $data['items'] = collect($this->items)->map(fn ($link) => SavedLinkResource::forRole($link, $role)->resolve($request))->values()->all();
        }

        return $data;
    }
}
