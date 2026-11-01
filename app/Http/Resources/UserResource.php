<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The authenticated user's own account (includes private fields).
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'avatar_url' => $this->avatarUrl(),
            'bio' => $this->bio,
            'locale' => $this->locale,
            'theme' => $this->theme,
            'profile_public' => $this->profile_public,
            'is_admin' => $this->isAdmin(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    private function avatarUrl(): ?string
    {
        return PublicUserResource::avatarFor($this->resource);
    }
}
