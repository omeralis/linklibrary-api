<?php

namespace App\Http\Resources;

use App\Models\LinkInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never includes the token or its hash.
 *
 * @mixin LinkInvitation
 */
class InvitationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'email' => $this->email,
            'status' => $this->effectiveStatus()->value,
            'invited_by' => $this->whenLoaded('inviter', fn () => ['username' => $this->inviter->username, 'name' => $this->inviter->name]),
            'via_reshare' => $this->inviter_grant_id !== null,
            'expires_at' => $this->expires_at->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
