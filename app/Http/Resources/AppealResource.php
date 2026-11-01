<?php

namespace App\Http\Resources;

use App\Models\LinkSecurityAppeal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LinkSecurityAppeal */
class AppealResource extends JsonResource
{
    public bool $forAdmin = false;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->public_id,
            'status' => $this->status->value,
            'security_status_at_submission' => $this->security_status_at_submission->value,
            'reason' => $this->reason,
            'resolution_verdict' => $this->resolution_verdict?->value,
            'reviewer_notes' => $this->reviewer_notes,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];

        if ($this->forAdmin) {
            $data['link'] = [
                'id' => $this->link->id,
                'url' => $this->link->normalized_url,
                'hostname' => $this->link->hostname,
                'security_status' => $this->link->security_status->value,
            ];
            $data['submitted_by'] = ['username' => $this->user->username];
        }

        return $data;
    }
}
