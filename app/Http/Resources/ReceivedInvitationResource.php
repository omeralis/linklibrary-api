<?php

namespace App\Http\Resources;

use App\Models\LinkInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pending invitation addressed to the current user: no link details until accepted.
 *
 * @mixin LinkInvitation
 */
class ReceivedInvitationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'invited_by' => ['name' => $this->inviter->name, 'username' => $this->inviter->username],
            'expires_at' => $this->expires_at->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
