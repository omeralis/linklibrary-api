<?php

namespace Tests\Feature\Sharing;

use App\Enums\SecurityStatus;
use App\Models\AuditLog;
use App\Models\Link;
use App\Models\LinkInvitation;
use App\Models\SavedLink;
use App\Models\User;
use App\Notifications\InvitationAcceptedNotification;
use App\Notifications\LinkInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase, SharingTestHelpers;

    private User $owner;

    private SavedLink $saved;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->owner = User::factory()->create();
        $this->saved = $this->savedLinkFor($this->owner, ['notes' => 'OWNER-ONLY-NOTE']);
    }

    public function test_owner_invites_and_verified_recipient_accepts_read_only(): void
    {
        $bob = User::factory()->create(['email' => 'bob@example.com']);

        $response = $this->invite($this->owner, $this->saved, 'Bob@Example.com')->assertCreated();
        $response->assertJsonPath('data.email', 'bob@example.com')->assertJsonPath('data.status', 'PENDING');
        $this->assertArrayNotHasKey('token', $response->json('data'));
        $this->assertArrayNotHasKey('token_hash', $response->json('data'));

        $token = $this->lastTokenFor('bob@example.com');
        $this->assertSame(64, strlen($token));
        $this->assertDatabaseMissing('link_invitations', ['token_hash' => $token]);
        $this->assertDatabaseHas('link_invitations', ['token_hash' => hash('sha256', $token)]);

        // The database notification never contains the token.
        $this->assertStringNotContainsString($token, json_encode((new LinkInvitationNotification($token, 'x', 'Owner', now()))->toArray($bob)));

        $this->accept($bob, $token)->assertOk()
            ->assertJsonPath('data.access_role', 'viewer')
            ->assertJsonPath('data.permissions.can_edit', false)
            ->assertJsonMissingPath('data.notes')
            ->assertJsonMissingPath('data.visibility');
        Notification::assertSentTo($this->owner, InvitationAcceptedNotification::class);

        // Viewer is read-only.
        Sanctum::actingAs($bob);
        $this->getJson("/api/v1/links/{$this->saved->public_id}")->assertOk()->assertJsonPath('data.access_role', 'viewer');
        $this->patchJson("/api/v1/links/{$this->saved->public_id}", ['custom_title' => 'x'])->assertStatus(404);
        $this->deleteJson("/api/v1/links/{$this->saved->public_id}")->assertStatus(404);
        $this->patchJson("/api/v1/links/{$this->saved->public_id}/visibility", ['visibility' => 'PUBLIC'])->assertStatus(404);
        $this->patchJson("/api/v1/links/{$this->saved->public_id}/sharing-settings", ['allow_resharing' => true])->assertStatus(404);
        $this->getJson('/api/v1/shared-with-me')->assertJsonPath('meta.pagination.total', 1)->assertJsonMissingPath('data.0.notes');

        // Accepting twice is idempotent.
        $this->accept($bob, $token)->assertOk();
        $this->assertSame(1, $this->saved->grants()->count());
    }

    public function test_new_user_registers_verifies_then_accepts(): void
    {
        $this->invite($this->owner, $this->saved, 'newcomer@example.com')->assertCreated();
        Notification::assertSentOnDemand(LinkInvitationNotification::class, fn ($n, $c, AnonymousNotifiable $to) => $to->routes['mail'] === 'newcomer@example.com');
        $token = $this->lastTokenFor('newcomer@example.com');

        $this->postJson('/api/v1/auth/register', ['name' => 'New', 'username' => 'newcomer', 'email' => 'newcomer@example.com', 'password' => 'secret-pass-123', 'password_confirmation' => 'secret-pass-123', 'device_name' => 't'])->assertCreated();
        $newcomer = User::query()->where('email', 'newcomer@example.com')->firstOrFail();

        $this->accept($newcomer, $token)->assertStatus(403)->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');

        $newcomer->markEmailAsVerified();
        $this->accept($newcomer->fresh(), $token)->assertOk();
        $this->assertTrue($this->canView($newcomer, $this->saved));
    }

    public function test_token_requires_matching_verified_email(): void
    {
        $this->invite($this->owner, $this->saved, 'intended@example.com')->assertCreated();
        $token = $this->lastTokenFor('intended@example.com');
        $thief = User::factory()->create(['email' => 'thief@example.com']);

        $this->accept($thief, $token)->assertStatus(404)->assertJsonPath('code', 'INVITATION_NOT_FOUND');
        $this->assertFalse($this->canView($thief, $this->saved));

        // Unauthenticated acceptance is impossible.
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/invitations/{$token}/accept")->assertStatus(401);
    }

    public function test_expired_and_revoked_invitations(): void
    {
        $carol = User::factory()->create(['email' => 'carol@example.com']);
        $this->invite($this->owner, $this->saved, 'carol@example.com')->assertCreated();
        $token = $this->lastTokenFor('carol@example.com');

        $this->travel(8)->days();
        $this->accept($carol, $token)->assertStatus(410)->assertJsonPath('code', 'INVITATION_EXPIRED');
        $this->travelBack();

        $dave = User::factory()->create(['email' => 'dave@example.com']);
        $invitation = $this->invite($this->owner, $this->saved, 'dave@example.com')->json('data.id');
        $token = $this->lastTokenFor('dave@example.com');
        Sanctum::actingAs($this->owner);
        $this->deleteJson("/api/v1/links/{$this->saved->public_id}/invitations/{$invitation}")->assertOk();
        $this->accept($dave, $token)->assertStatus(409)->assertJsonPath('code', 'INVITATION_NOT_PENDING');
    }

    public function test_reinviting_replaces_the_previous_token(): void
    {
        $erin = User::factory()->create(['email' => 'erin@example.com']);
        $this->invite($this->owner, $this->saved, 'erin@example.com')->assertCreated();
        $first = $this->lastTokenFor('erin@example.com');
        Notification::fake();
        $this->invite($this->owner, $this->saved, 'erin@example.com')->assertCreated();
        $second = $this->lastTokenFor('erin@example.com');

        $this->accept($erin, $first)->assertStatus(409);
        $this->accept($erin, $second)->assertOk();
    }

    public function test_in_app_acceptance_by_invitation_id(): void
    {
        $frank = User::factory()->create(['email' => 'frank@example.com']);
        $this->invite($this->owner, $this->saved, 'frank@example.com')->assertCreated();

        Sanctum::actingAs($frank);
        $id = $this->getJson('/api/v1/me/invitations')->assertOk()->assertJsonPath('meta.pagination.total', 1)->json('data.0.id');
        $this->postJson("/api/v1/me/invitations/{$id}/accept")->assertOk();

        $stranger = User::factory()->create();
        Sanctum::actingAs($stranger);
        $this->postJson("/api/v1/me/invitations/{$id}/accept")->assertStatus(404);
    }

    public function test_sharing_requires_clear_security_status(): void
    {
        foreach ([SecurityStatus::PENDING, SecurityStatus::UNKNOWN, SecurityStatus::SUSPICIOUS, SecurityStatus::MALICIOUS] as $status) {
            $saved = $this->savedLinkFor($this->owner, link: Link::factory()->security($status)->create());
            $this->invite($this->owner, $saved, 'x@example.com')->assertStatus(422)->assertJsonPath('code', 'LINK_NOT_SHAREABLE');
        }
    }

    public function test_invitation_cannot_be_accepted_after_link_turns_unsafe(): void
    {
        $gina = User::factory()->create(['email' => 'gina@example.com']);
        $this->invite($this->owner, $this->saved, 'gina@example.com')->assertCreated();
        $token = $this->lastTokenFor('gina@example.com');
        $this->saved->link->forceFill(['security_status' => SecurityStatus::SUSPICIOUS, 'security_expires_at' => null])->save();

        $this->accept($gina, $token)->assertStatus(422)->assertJsonPath('code', 'LINK_NOT_SHAREABLE');
    }

    public function test_invitation_rules(): void
    {
        $this->invite($this->owner, $this->saved, $this->owner->email)->assertStatus(422)->assertJsonPath('code', 'CANNOT_SHARE_WITH_SELF');
        $this->invite($this->owner, $this->saved, 'not-an-email')->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');

        $stranger = User::factory()->create();
        $this->invite($stranger, $this->saved, 'x@example.com')->assertStatus(404);

        $unverified = User::factory()->unverified()->create();
        $theirs = $this->savedLinkFor($unverified);
        $this->invite($unverified, $theirs, 'x@example.com')->assertStatus(403)->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');

        // Public visibility never implies permission to invite.
        $public = $this->savedLinkFor($this->owner, ['visibility' => 'PUBLIC', 'published_at' => now()]);
        $this->invite($stranger, $public, 'x@example.com')->assertStatus(404);
    }

    public function test_deleting_link_invalidates_invitations_and_grants(): void
    {
        $hank = User::factory()->create(['email' => 'hank@example.com']);
        $ivy = User::factory()->create(['email' => 'ivy@example.com']);
        $this->share($this->owner, $this->saved, $hank);
        $this->invite($this->owner, $this->saved, 'ivy@example.com')->assertCreated();
        $ivyToken = $this->lastTokenFor('ivy@example.com');

        Sanctum::actingAs($this->owner);
        $this->deleteJson("/api/v1/links/{$this->saved->public_id}")->assertOk();

        $this->assertFalse($this->canView($hank, $this->saved));
        $this->accept($ivy, $ivyToken)->assertStatus(409);
        $this->assertSame(0, $this->saved->grants()->where('status', 'ACTIVE')->count());

        // Restoring does not resurrect access.
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/links/{$this->saved->public_id}/restore")->assertOk();
        $this->assertFalse($this->canView($hank, $this->saved));
    }

    public function test_tokens_are_not_logged_in_audit_trail(): void
    {
        $this->invite($this->owner, $this->saved, 'jill@example.com')->assertCreated();
        $token = $this->lastTokenFor('jill@example.com');
        $this->assertSame(0, AuditLog::query()->where('metadata', 'like', '%'.$token.'%')->count());
        $this->assertSame(0, LinkInvitation::query()->where('token_hash', $token)->count());
    }
}
