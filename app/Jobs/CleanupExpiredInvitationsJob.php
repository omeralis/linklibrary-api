<?php

namespace App\Jobs;

use App\Enums\InvitationStatus;
use App\Models\LinkInvitation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CleanupExpiredInvitationsJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        LinkInvitation::query()
            ->where('status', InvitationStatus::PENDING->value)
            ->where('expires_at', '<=', now())
            ->update(['status' => InvitationStatus::EXPIRED->value, 'updated_at' => now()]);

        // Token hashes of finished invitations are no longer needed after the retention window.
        LinkInvitation::query()
            ->whereIn('status', [InvitationStatus::EXPIRED->value, InvitationStatus::REVOKED->value])
            ->where('updated_at', '<', now()->subDays((int) config('linklibrary.retention.expired_invitations_days', 30)))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('link_access_grants')->whereColumn('link_access_grants.invitation_id', 'link_invitations.id'))
            ->delete();
    }
}
