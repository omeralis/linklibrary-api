<?php

namespace Tests\Feature\Security;

use App\Enums\ExtractionStatus;
use App\Enums\SecurityStatus;
use App\Models\Link;
use App\Models\SavedLink;
use App\Models\User;
use App\Notifications\LinkInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Sharing\SharingTestHelpers;
use Tests\TestCase;

/**
 * Temporary, reversible policy: while security scanning is "Coming Soon", owners may publish and share UNKNOWN
 * links after metadata processing succeeded. Nothing is ever marked CLEAR; SUSPICIOUS/MALICIOUS stay blocked.
 */
class UnverifiedSharingPolicyTest extends TestCase
{
    use RefreshDatabase, SharingTestHelpers;

    private function unknownLink(array $state = []): Link
    {
        return Link::factory()->security(SecurityStatus::UNKNOWN)->create($state);
    }

    public function test_default_policy_is_unchanged_and_strict(): void
    {
        $this->useSecurityPolicy(allowUnverifiedSharing: false, scanningComingSoon: false); // strict policy, independent of the environment
        $user = $this->actingAsUser();
        $saved = $this->savedLinkFor($user, link: $this->unknownLink());

        $this->patchJson("/api/v1/links/{$saved->public_id}/visibility", ['visibility' => 'PUBLIC'])
            ->assertStatus(422)->assertJsonPath('code', 'LINK_NOT_PUBLISHABLE');
        $this->getJson('/api/v1/public/features')->assertJsonPath('data.unverified_sharing', false)->assertJsonPath('data.security_scanning', 'AVAILABLE');
    }

    public function test_owner_can_publish_unknown_link_when_flag_enabled_and_it_stays_unknown(): void
    {
        $this->useSecurityPolicy(allowUnverifiedSharing: true, scanningComingSoon: true);
        $user = $this->actingAsUser();
        $link = $this->unknownLink();
        $saved = $this->savedLinkFor($user, link: $link, attributes: ['visibility' => 'PRIVATE']);

        $this->getJson("/api/v1/links/{$saved->public_id}")
            ->assertJsonPath('data.permissions.can_publish', true)
            ->assertJsonPath('data.permissions.can_share', true)
            ->assertJsonPath('data.security.status', 'UNKNOWN')
            ->assertJsonPath('data.security.verified', false)
            ->assertJsonPath('data.security.scanning', 'COMING_SOON')
            ->assertJsonPath('data.security.warning', 'security.unknown');

        $this->patchJson("/api/v1/links/{$saved->public_id}/visibility", ['visibility' => 'PUBLIC'])->assertOk();

        // Never promoted to CLEAR.
        $this->assertSame(SecurityStatus::UNKNOWN, $link->fresh()->security_status);

        // Served publicly with the warning; listed in public discovery.
        auth()->forgetGuards();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/public/links/{$saved->public_id}")
            ->assertOk()
            ->assertJsonPath('data.security.status', 'UNKNOWN')
            ->assertJsonPath('data.security.verified', false)
            ->assertJsonPath('data.security.warning', 'security.unknown');
        $this->getJson('/api/v1/public/links')->assertOk()->assertJsonFragment(['public_id' => $saved->public_id]);
        $this->getJson('/api/v1/public/features')->assertJsonPath('data.unverified_sharing', true)->assertJsonPath('data.security_scanning', 'COMING_SOON');
    }

    public function test_publishing_requires_processed_metadata_and_verified_email_and_ownership(): void
    {
        $this->useSecurityPolicy(allowUnverifiedSharing: true, scanningComingSoon: true);
        $user = $this->actingAsUser();

        foreach ([ExtractionStatus::PENDING, ExtractionStatus::PROCESSING, ExtractionStatus::FAILED] as $extraction) {
            $saved = $this->savedLinkFor($user, link: $this->unknownLink(['extraction_status' => $extraction]));
            $this->patchJson("/api/v1/links/{$saved->public_id}/visibility", ['visibility' => 'PUBLIC'])
                ->assertStatus(422)->assertJsonPath('code', 'LINK_NOT_PUBLISHABLE');
        }

        // BLOCKED for our own safety (private address, unsafe redirect, security policy) stays unpublishable.
        foreach (['BLOCKED_IP', 'UNSAFE_REDIRECT', 'SECURITY_POLICY', null] as $code) {
            $unsafe = $this->savedLinkFor($user, link: $this->unknownLink(['extraction_status' => ExtractionStatus::BLOCKED, 'extraction_error_code' => $code]));
            $this->patchJson("/api/v1/links/{$unsafe->public_id}/visibility", ['visibility' => 'PUBLIC'])
                ->assertStatus(422)->assertJsonPath('code', 'LINK_NOT_PUBLISHABLE');
        }

        $partial = $this->savedLinkFor($user, link: $this->unknownLink(['extraction_status' => ExtractionStatus::PARTIAL]));
        $this->patchJson("/api/v1/links/{$partial->public_id}/visibility", ['visibility' => 'PUBLIC'])->assertOk();

        // Unverified e-mail is still refused.
        $unverified = User::factory()->unverified()->create();
        $mine = $this->savedLinkFor($unverified, link: $this->unknownLink());
        Sanctum::actingAs($unverified);
        $this->patchJson("/api/v1/links/{$mine->public_id}/visibility", ['visibility' => 'PUBLIC'])->assertStatus(403);

        // Ownership: someone else's link is a 404.
        Sanctum::actingAs($user);
        $this->patchJson("/api/v1/links/{$mine->public_id}/visibility", ['visibility' => 'PUBLIC'])->assertNotFound();
    }

    public function test_site_that_refuses_robots_can_be_published_and_shared_with_manual_details(): void
    {
        $this->useSecurityPolicy(allowUnverifiedSharing: true, scanningComingSoon: true);
        $user = $this->actingAsUser();

        foreach (['ROBOTS_DISALLOWED', 'ACCESS_RESTRICTED'] as $code) {
            $saved = $this->savedLinkFor($user, link: $this->unknownLink(['extraction_status' => ExtractionStatus::BLOCKED, 'extraction_error_code' => $code]));
            $this->patchJson("/api/v1/links/{$saved->public_id}/visibility", ['visibility' => 'PUBLIC'])->assertOk();
            $this->getJson("/api/v1/links/{$saved->public_id}")
                ->assertOk()
                ->assertJsonPath('data.permissions.can_publish', true)
                ->assertJsonPath('data.permissions.can_share', true)
                ->assertJsonPath('data.security.status', 'UNKNOWN');
        }

        // Still never CLEAR, and a security-blocked link stays blocked even with that extraction code.
        $malicious = $this->savedLinkFor($user, link: $this->unknownLink(['security_status' => SecurityStatus::MALICIOUS, 'extraction_status' => ExtractionStatus::BLOCKED, 'extraction_error_code' => 'ROBOTS_DISALLOWED']));
        $this->patchJson("/api/v1/links/{$malicious->public_id}/visibility", ['visibility' => 'PUBLIC'])->assertStatus(422);
    }

    public function test_suspicious_malicious_and_pending_links_stay_blocked(): void
    {
        $this->useSecurityPolicy(allowUnverifiedSharing: true, scanningComingSoon: true);
        Notification::fake();
        $user = $this->actingAsUser();

        foreach ([SecurityStatus::PENDING, SecurityStatus::SUSPICIOUS, SecurityStatus::MALICIOUS] as $status) {
            $saved = $this->savedLinkFor($user, link: Link::factory()->security($status)->create());
            $this->patchJson("/api/v1/links/{$saved->public_id}/visibility", ['visibility' => 'PUBLIC'])
                ->assertStatus(422)->assertJsonPath('code', 'LINK_NOT_PUBLISHABLE');
            $this->invite($user, $saved, 'friend@example.com')->assertStatus(422)->assertJsonPath('code', 'LINK_NOT_SHAREABLE');
        }
    }

    public function test_unknown_links_can_be_shared_privately_but_only_by_the_owner_flow(): void
    {
        $this->useSecurityPolicy(allowUnverifiedSharing: true, scanningComingSoon: true);
        Notification::fake();
        $owner = User::factory()->create();
        $bob = User::factory()->create(['email' => 'bob@example.com']);
        $saved = $this->savedLinkFor($owner, link: $this->unknownLink());

        $this->invite($owner, $saved, 'bob@example.com')->assertCreated();
        Notification::assertSentTo($bob, LinkInvitationNotification::class);
        $this->accept($bob, $this->lastTokenFor('bob@example.com'))->assertOk()
            ->assertJsonPath('data.access_role', 'viewer')
            ->assertJsonPath('data.security.verified', false)
            ->assertJsonPath('data.security.warning', 'security.unknown');

        // A stranger cannot invite on someone else's link (404 like a missing resource).
        $mallory = User::factory()->create();
        $this->invite($mallory, $saved, 'x@example.com')->assertNotFound();
    }

    public function test_links_stay_private_by_default_and_flag_off_restores_strict_policy(): void
    {
        $this->useSecurityPolicy(allowUnverifiedSharing: true);
        $user = $this->actingAsUser();
        $saved = $this->savedLinkFor($user, link: $this->unknownLink());
        $this->assertSame('PRIVATE', $saved->fresh()->visibility->value);
        $this->patchJson("/api/v1/links/{$saved->public_id}/visibility", ['visibility' => 'PUBLIC'])->assertOk();

        // Reversible: with the flag off the already-published UNKNOWN link disappears from public pages.
        $this->useSecurityPolicy(allowUnverifiedSharing: false);
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/public/links/{$saved->public_id}")->assertNotFound();
        $this->assertFalse(SavedLink::query()->publiclyEligible()->whereKey($saved->id)->exists());
    }

    public function test_link_that_becomes_malicious_after_publication_is_hidden_again(): void
    {
        $this->useSecurityPolicy(allowUnverifiedSharing: true);
        $user = $this->actingAsUser();
        $link = $this->unknownLink();
        $saved = $this->savedLinkFor($user, ['visibility' => 'PUBLIC', 'published_at' => now()], $link);
        $this->assertTrue(SavedLink::query()->publiclyEligible()->whereKey($saved->id)->exists());

        $link->forceFill(['security_status' => SecurityStatus::MALICIOUS])->save();
        $this->assertFalse(SavedLink::query()->publiclyEligible()->whereKey($saved->id)->exists());
    }

    public function test_manual_rescan_is_disabled_while_coming_soon_but_records_are_kept(): void
    {
        $this->useSecurityPolicy(allowUnverifiedSharing: true, scanningComingSoon: true);
        $user = $this->actingAsUser();
        $saved = $this->savedLinkFor($user, link: $this->unknownLink());

        $this->postJson("/api/v1/links/{$saved->public_id}/security/rescan")
            ->assertStatus(422)->assertJsonPath('code', 'SECURITY_SCANNING_COMING_SOON');
        $this->assertDatabaseCount('link_security_scans', 0);
        $this->getJson("/api/v1/links/{$saved->public_id}/security")
            ->assertOk()->assertJsonPath('data.scanning', 'COMING_SOON')->assertJsonPath('data.unverified_sharing', true);
    }

    public function test_manual_rescan_works_when_scanning_is_available(): void
    {
        $this->useSecurityPolicy(allowUnverifiedSharing: true, scanningComingSoon: false);
        $user = $this->actingAsUser();
        $saved = $this->savedLinkFor($user, link: $this->unknownLink());

        $this->postJson("/api/v1/links/{$saved->public_id}/security/rescan")->assertStatus(202);
    }

    public function test_expired_clear_verdict_is_treated_as_unknown_and_never_upgraded(): void
    {
        $this->useSecurityPolicy(allowUnverifiedSharing: true);
        $user = $this->actingAsUser();
        $link = Link::factory()->create(['security_status' => SecurityStatus::CLEAR, 'security_expires_at' => now()->subDay()]);
        $saved = $this->savedLinkFor($user, link: $link);

        $this->getJson("/api/v1/links/{$saved->public_id}")
            ->assertJsonPath('data.security.status', 'UNKNOWN')
            ->assertJsonPath('data.security.verified', false);
    }
}
