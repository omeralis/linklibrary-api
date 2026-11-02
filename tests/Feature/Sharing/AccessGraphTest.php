<?php

namespace Tests\Feature\Sharing;

use App\Enums\SecurityStatus;
use App\Exceptions\ApiException;
use App\Models\LinkAccessGrant;
use App\Models\SavedLink;
use App\Models\User;
use App\Notifications\AccessRevokedNotification;
use App\Services\Sharing\AccessGraphService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccessGraphTest extends TestCase
{
    use RefreshDatabase, SharingTestHelpers;

    private User $a;

    private User $b;

    private User $c;

    private User $d;

    private User $e;

    private SavedLink $saved;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        [$this->a, $this->b, $this->c, $this->d, $this->e] = User::factory()->count(5)->create()->all();
        $this->saved = $this->savedLinkFor($this->a, ['allow_resharing' => true]);
    }

    private function revoke(User $as, string $grantId): TestResponse
    {
        Sanctum::actingAs($as);

        return $this->deleteJson("/api/v1/links/{$this->saved->public_id}/access/{$grantId}");
    }

    public function test_chain_revocation_cascades(): void
    {
        // A → B → C → D
        $this->share($this->a, $this->saved, $this->b);
        $this->share($this->b, $this->saved, $this->c);
        $this->share($this->c, $this->saved, $this->d);
        foreach ([$this->b, $this->c, $this->d] as $u) {
            $this->assertTrue($this->canView($u, $this->saved));
        }

        $this->revoke($this->a, $this->grantId($this->saved, $this->a, $this->b))->assertOk()->assertJsonPath('data.revoked_users', 3);

        foreach ([$this->b, $this->c, $this->d] as $u) {
            $this->assertFalse($this->canView($u, $this->saved));
        }
        $this->assertSame(2, LinkAccessGrant::query()->where('revoke_reason', 'PARENT_REVOKED')->count());
        Notification::assertSentTo([$this->b, $this->c, $this->d], AccessRevokedNotification::class);
    }

    public function test_independent_paths_survive_revocation(): void
    {
        // A → B → C → D, plus A → C directly.
        $this->share($this->a, $this->saved, $this->b);
        $this->share($this->b, $this->saved, $this->c);
        $this->share($this->c, $this->saved, $this->d); // created through C's best (shortest) grant at that time: via B
        $this->share($this->a, $this->saved, $this->c);  // independent direct grant

        $this->revoke($this->a, $this->grantId($this->saved, $this->a, $this->b))->assertOk();

        $this->assertFalse($this->canView($this->b, $this->saved));
        $this->assertTrue($this->canView($this->c, $this->saved), 'C keeps access through the direct grant');
        $this->assertFalse($this->canView($this->d, $this->saved), 'D derived from the revoked path (strict lineage)');

        // C may reshare again through the surviving path.
        $this->share($this->c, $this->saved, $this->d);
        $this->assertTrue($this->canView($this->d, $this->saved));
        $dGrant = LinkAccessGrant::query()->where('grantee_user_id', $this->d->id)->where('status', 'ACTIVE')->firstOrFail();
        $this->assertSame(2, $dGrant->depth);
    }

    public function test_user_with_two_direct_paths_keeps_access(): void
    {
        $this->share($this->a, $this->saved, $this->b);
        $this->share($this->a, $this->saved, $this->c);
        $this->share($this->b, $this->saved, $this->d);
        $this->share($this->c, $this->saved, $this->d); // second independent path for D

        $this->revoke($this->a, $this->grantId($this->saved, $this->a, $this->b))->assertOk();

        $this->assertTrue($this->canView($this->d, $this->saved));
        $this->assertTrue($this->canView($this->c, $this->saved));
        $this->assertFalse($this->canView($this->b, $this->saved));
    }

    public function test_branching_revocation_only_affects_the_subtree(): void
    {
        $this->share($this->a, $this->saved, $this->b);
        $this->share($this->a, $this->saved, $this->c);
        $this->share($this->b, $this->saved, $this->d);
        $this->share($this->b, $this->saved, $this->e);

        $this->revoke($this->a, $this->grantId($this->saved, $this->a, $this->b))->assertOk();

        $this->assertTrue($this->canView($this->c, $this->saved));
        foreach ([$this->b, $this->d, $this->e] as $u) {
            $this->assertFalse($this->canView($u, $this->saved));
        }
    }

    public function test_cycles_and_owner_reinvites_are_rejected(): void
    {
        $this->share($this->a, $this->saved, $this->b);
        $this->share($this->b, $this->saved, $this->c);
        $this->share($this->c, $this->saved, $this->d);

        $this->invite($this->c, $this->saved, $this->b->email)->assertCreated(); // invitation itself is allowed…
        $this->accept($this->b, $this->lastTokenFor($this->b->email))->assertStatus(409)->assertJsonPath('code', 'GRANT_CYCLE'); // …but cannot close a cycle

        $this->invite($this->d, $this->saved, $this->a->email)->assertStatus(409)->assertJsonPath('code', 'ALREADY_OWNER');

        // Graph invariant: no grant's grantee appears on its own ancestor chain.
        foreach (LinkAccessGrant::query()->where('status', 'ACTIVE')->get() as $grant) {
            $seen = [$this->a->id];
            for ($g = $grant; $g !== null; $g = $g->parent_grant_id ? LinkAccessGrant::query()->find($g->parent_grant_id) : null) {
                $this->assertNotContains($g->grantee_user_id, array_slice($seen, 1));
                $seen[] = $g->grantee_user_id;
            }
        }
    }

    public function test_resharing_toggle(): void
    {
        $this->saved->forceFill(['allow_resharing' => false])->save();
        $this->share($this->a, $this->saved, $this->b);

        $this->invite($this->b, $this->saved, $this->c->email)->assertStatus(403);
        Sanctum::actingAs($this->b);
        $this->getJson("/api/v1/links/{$this->saved->public_id}")->assertJsonPath('data.permissions.can_reshare', false);

        Sanctum::actingAs($this->a);
        $this->patchJson("/api/v1/links/{$this->saved->public_id}/sharing-settings", ['allow_resharing' => true])->assertOk();
        $this->invite($this->b, $this->saved, $this->c->email)->assertCreated();
        $pendingToken = $this->lastTokenFor($this->c->email);

        // Disabling resharing: existing access remains, pending downstream invitation cannot be accepted.
        Sanctum::actingAs($this->a);
        $this->patchJson("/api/v1/links/{$this->saved->public_id}/sharing-settings", ['allow_resharing' => false])->assertOk();
        $this->assertTrue($this->canView($this->b, $this->saved));
        $this->accept($this->c, $pendingToken)->assertStatus(403);
        $this->assertFalse($this->canView($this->c, $this->saved));
    }

    public function test_existing_downstream_access_survives_disabling_resharing(): void
    {
        $this->share($this->a, $this->saved, $this->b);
        $this->share($this->b, $this->saved, $this->c);

        Sanctum::actingAs($this->a);
        $this->patchJson("/api/v1/links/{$this->saved->public_id}/sharing-settings", ['allow_resharing' => false])->assertOk();

        $this->assertTrue($this->canView($this->c, $this->saved));
        $this->invite($this->c, $this->saved, $this->d->email)->assertStatus(403);
    }

    public function test_users_keeping_access_through_another_path_are_not_counted_or_notified(): void
    {
        // A → B → C, A → B → D and A → D directly: revoking B removes B and C, D keeps access.
        $this->share($this->a, $this->saved, $this->b);
        $this->share($this->b, $this->saved, $this->c);
        $this->share($this->b, $this->saved, $this->d);
        $this->share($this->a, $this->saved, $this->d);

        $this->revoke($this->a, $this->grantId($this->saved, $this->a, $this->b))
            ->assertOk()
            ->assertJsonPath('data.revoked_users', 2);

        $this->assertFalse($this->canView($this->b, $this->saved));
        $this->assertFalse($this->canView($this->c, $this->saved));
        $this->assertTrue($this->canView($this->d, $this->saved));
        Notification::assertSentTo([$this->b, $this->c], AccessRevokedNotification::class);
        Notification::assertNotSentTo($this->d, AccessRevokedNotification::class);
    }

    public function test_revocation_permissions(): void
    {
        $this->share($this->a, $this->saved, $this->b);
        $this->share($this->b, $this->saved, $this->c);
        $this->share($this->a, $this->saved, $this->d);

        // D (unrelated viewer) cannot revoke B's or C's grants.
        $this->revoke($this->d, $this->grantId($this->saved, $this->a, $this->b))->assertStatus(404);
        $this->revoke($this->d, $this->grantId($this->saved, $this->b, $this->c))->assertStatus(404);
        // A stranger cannot even see the link.
        $this->revoke($this->e, $this->grantId($this->saved, $this->a, $this->b))->assertStatus(404);

        // C can leave; B (grantor) can revoke what B issued.
        $this->revoke($this->c, $this->grantId($this->saved, $this->b, $this->c))->assertOk();
        $this->assertFalse($this->canView($this->c, $this->saved));
        $this->share($this->b, $this->saved, $this->c);
        $this->revoke($this->b, $this->grantId($this->saved, $this->b, $this->c))->assertOk();
        $this->assertFalse($this->canView($this->c, $this->saved));
        $this->assertTrue($this->canView($this->b, $this->saved));

        // Revoking twice is idempotent.
        $this->revoke($this->a, $this->grantId($this->saved, $this->a, $this->d))->assertOk();
        $this->revoke($this->a, $this->grantId($this->saved, $this->a, $this->d))->assertOk()->assertJsonPath('data.revoked_users', 0);
    }

    public function test_access_list_for_owner_and_viewer(): void
    {
        $this->share($this->a, $this->saved, $this->b);
        $this->share($this->b, $this->saved, $this->c);
        $this->share($this->a, $this->saved, $this->d);

        Sanctum::actingAs($this->a);
        $this->getJson("/api/v1/links/{$this->saved->public_id}/access")->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.viewer_count', 3)
            ->assertJsonMissingPath('data.0.grantee.email');

        Sanctum::actingAs($this->b);
        $this->getJson("/api/v1/links/{$this->saved->public_id}/access")->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.grantee.username', $this->c->username);

        Sanctum::actingAs($this->e);
        $this->getJson("/api/v1/links/{$this->saved->public_id}/access")->assertStatus(404);
    }

    public function test_visibility_round_trip_preserves_only_valid_grants(): void
    {
        $this->share($this->a, $this->saved, $this->b);
        $this->share($this->a, $this->saved, $this->c);
        $this->revoke($this->a, $this->grantId($this->saved, $this->a, $this->c))->assertOk();

        Sanctum::actingAs($this->a);
        $this->patchJson("/api/v1/links/{$this->saved->public_id}/visibility", ['visibility' => 'PUBLIC'])->assertOk();
        $this->assertSame(2, LinkAccessGrant::query()->where('saved_link_id', $this->saved->id)->count(), 'history kept');
        $this->patchJson("/api/v1/links/{$this->saved->public_id}/visibility", ['visibility' => 'PRIVATE'])->assertOk();

        $this->assertTrue($this->canView($this->b, $this->saved));
        $this->assertFalse($this->canView($this->c, $this->saved));
    }

    public function test_malicious_links_suspend_viewer_access(): void
    {
        $this->share($this->a, $this->saved, $this->b);
        $this->saved->link->forceFill(['security_status' => SecurityStatus::MALICIOUS, 'security_expires_at' => null])->save();

        Sanctum::actingAs($this->b);
        $this->getJson("/api/v1/links/{$this->saved->public_id}")->assertStatus(403)->assertJsonPath('code', 'LINK_SECURITY_BLOCKED');
        $this->getJson("/api/v1/links/{$this->saved->public_id}/open")->assertStatus(403);
    }

    public function test_guessed_identifiers_do_not_grant_access(): void
    {
        Sanctum::actingAs($this->e);
        $this->getJson("/api/v1/links/{$this->saved->public_id}")->assertStatus(404);
        $this->getJson('/api/v1/links/'.Str::uuid())->assertStatus(404);
        $this->postJson('/api/v1/invitations/'.str_repeat('a', 64).'/accept')->assertStatus(404);
        $this->postJson('/api/v1/invitations/../../etc/accept')->assertStatus(404);
    }

    public function test_service_rejects_privilege_escalation(): void
    {
        $this->share($this->a, $this->saved, $this->b);
        $graph = app(AccessGraphService::class);

        // E has no grant: cannot create a downstream grant even if called directly.
        $this->expectException(ApiException::class);
        DB::transaction(function () use ($graph) {
            $graph->lockSavedLink($this->saved->id);
            $graph->createGrantLocked($this->saved, $this->e, $this->d);
        });
    }
}
