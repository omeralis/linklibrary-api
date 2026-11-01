<?php

namespace Tests\Feature\Sharing;

use App\Models\LinkAccessGrant;
use App\Models\SavedLink;
use App\Models\User;
use App\Notifications\LinkInvitationNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

trait SharingTestHelpers
{
    protected function invite(User $as, SavedLink $saved, string $email): TestResponse
    {
        Sanctum::actingAs($as);

        return $this->postJson("/api/v1/links/{$saved->public_id}/invitations", ['email' => $email]);
    }

    /** Capture the plaintext token from the most recent invitation e-mail to $email. */
    protected function lastTokenFor(string $email): string
    {
        $token = null;
        Notification::assertSentTo(
            User::query()->where('email', $email)->first() ?? new AnonymousNotifiable,
            LinkInvitationNotification::class,
            function (LinkInvitationNotification $n) use (&$token) {
                $token = $n->tokenForTesting();

                return true;
            },
        );

        return (string) $token;
    }

    protected function accept(User $as, string $token): TestResponse
    {
        Sanctum::actingAs($as);

        return $this->postJson("/api/v1/invitations/{$token}/accept");
    }

    /** Invite + accept in one step, asserting success. */
    protected function share(User $from, SavedLink $saved, User $to): void
    {
        $this->invite($from, $saved, $to->email)->assertCreated();
        $this->accept($to, $this->lastTokenFor($to->email))->assertOk();
    }

    protected function canView(User $user, SavedLink $saved): bool
    {
        Sanctum::actingAs($user);
        $status = $this->getJson("/api/v1/links/{$saved->public_id}")->status();
        $this->assertContains($status, [200, 404]);

        return $status === 200;
    }

    protected function grantId(SavedLink $saved, User $grantor, User $grantee): string
    {
        return LinkAccessGrant::query()
            ->where('saved_link_id', $saved->id)
            ->where('grantor_user_id', $grantor->id)
            ->where('grantee_user_id', $grantee->id)
            ->latest('id')->value('public_id');
    }
}
