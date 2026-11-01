<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\FrontendUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Public profile data only: never e-mail, role, status or internal ids.
 *
 * @mixin User
 */
class PublicUserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'username' => $this->username,
            'name' => $this->name,
            'avatar_url' => self::avatarFor($this->resource),
            'bio' => $this->bio,
            'profile_url' => FrontendUrl::profile($this->username),
            'joined_at' => $this->created_at->toDateString(),
        ];
    }

    public static function avatarFor(User $user): ?string
    {
        if ($user->avatar_path !== null) {
            return Storage::disk((string) config('linklibrary.uploads.disk'))->url($user->avatar_path);
        }

        return $user->avatar_url;
    }

    /**
     * Minimal identity shown next to content, or null when the profile is private.
     *
     * @return array<string, mixed>|null
     */
    public static function summary(?User $user): ?array
    {
        if ($user === null || ! $user->profile_public || ! $user->isActive()) {
            return null;
        }

        return [
            'username' => $user->username,
            'name' => $user->name,
            'avatar_url' => self::avatarFor($user),
            'profile_url' => FrontendUrl::profile($user->username),
        ];
    }
}
