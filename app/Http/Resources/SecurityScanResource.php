<?php

namespace App\Http\Resources;

use App\Models\LinkSecurityScan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LinkSecurityScan */
class SecurityScanResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'trigger' => $this->trigger->value,
            'status' => $this->status->value,
            'verdict' => $this->verdict?->value,
            'providers' => collect($this->provider_results ?? [])->map(fn ($r) => [
                'provider' => $r['provider'] ?? null,
                'verdict' => $r['verdict'] ?? null,
                'threats' => $r['threats'] ?? [],
                'error_code' => $r['error_code'] ?? null,
            ])->values()->all(),
            'error_code' => $this->error_code,
            'created_at' => $this->created_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
