<?php

namespace App\Http\Resources;

use App\Models\LinkAccessGrant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LinkAccessGrant */
class GrantResource extends JsonResource
{
    /** @var array<int, string> internal id → public id of parent grants */
    public static array $publicIds = [];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'grantee' => ['username' => $this->grantee->username, 'name' => $this->grantee->name],
            'grantor' => ['username' => $this->grantor->username, 'name' => $this->grantor->name],
            'parent_grant_id' => $this->parent_grant_id !== null ? (self::$publicIds[$this->parent_grant_id] ?? $this->parent?->public_id) : null,
            'depth' => $this->depth,
            'status' => $this->status->value,
            'effective' => (bool) ($this->resource->effective ?? $this->isActive()),
            'revoke_reason' => $this->revoke_reason,
            'created_at' => $this->created_at->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
        ];
    }
}
