<?php

namespace Tests\Feature\Api;

use App\Enums\ScanTrigger;
use App\Enums\SecurityStatus;
use App\Models\Link;
use App\Models\LinkSecurityScan;
use App\Models\User;
use App\Notifications\AccessRevokedNotification;
use App\Services\Security\SecurityScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationsAndAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_endpoints(): void
    {
        $me = $this->actingAsUser();
        $me->notify(new AccessRevokedNotification('11111111-1111-4111-8111-111111111111'));
        $me->notify(new AccessRevokedNotification('22222222-2222-4222-8222-222222222222'));
        User::factory()->create()->notify(new AccessRevokedNotification('33333333-3333-4333-8333-333333333333'));

        $this->getJson('/api/v1/notifications/unread-count')->assertJsonPath('data.unread', 2);
        $list = $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.kind', 'sharing.access_revoked');
        $id = $list->json('data.0.id');

        $this->patchJson("/api/v1/notifications/{$id}/read")->assertOk()->assertJsonPath('data.read', true);
        $this->getJson('/api/v1/notifications/unread-count')->assertJsonPath('data.unread', 1);
        $this->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.updated', 1);
        $this->getJson('/api/v1/notifications?unread=1')->assertJsonCount(0, 'data');

        $foreign = User::query()->where('id', '!=', $me->id)->first()->notifications()->first();
        $this->patchJson("/api/v1/notifications/{$foreign->id}/read")->assertStatus(404);
    }

    public function test_admin_routes_are_gated(): void
    {
        $this->actingAsUser();
        $this->getJson('/api/v1/admin/security/appeals')->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
        $this->getJson('/api/v1/admin/audit-logs')->assertStatus(403);
        $this->postJson('/api/v1/admin/categories', ['slug' => 'x', 'name_ar' => 'x', 'name_en' => 'x'])->assertStatus(403);
    }

    public function test_appeal_flow_and_manual_review(): void
    {
        $owner = $this->actingAsUser();
        $saved = $this->savedLinkFor($owner, link: Link::factory()->security(SecurityStatus::SUSPICIOUS)->create());
        $clean = $this->savedLinkFor($owner);

        $this->postJson("/api/v1/links/{$clean->public_id}/security/appeal", ['reason' => 'This is fine, please check'])->assertStatus(422)->assertJsonPath('code', 'APPEAL_NOT_APPLICABLE');
        $appealId = $this->postJson("/api/v1/links/{$saved->public_id}/security/appeal", ['reason' => 'This is my own company website.'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/links/{$saved->public_id}/security/appeal", ['reason' => 'Second request for review'])->assertStatus(409);

        $security = $this->getJson("/api/v1/links/{$saved->public_id}/security")->assertOk();
        $security->assertJsonPath('data.status', 'SUSPICIOUS')->assertJsonPath('data.appeals.0.status', 'PENDING');
        $this->assertStringContainsString('cannot guarantee', $security->json('data.disclaimer'));

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/security/appeals')->assertOk()->assertJsonPath('data.0.id', $appealId);
        $this->postJson("/api/v1/admin/security/appeals/{$appealId}/resolve", ['decision' => 'APPROVE', 'verdict' => 'CLEAR', 'notes' => 'Verified owner'])
            ->assertOk()->assertJsonPath('data.status', 'APPROVED');

        $link = $saved->link->fresh();
        $this->assertSame(SecurityStatus::CLEAR, $link->security_status);
        $this->assertSame('MANUAL_REVIEW', $link->security_source->value);

        // Provider results do not override a valid manual decision...
        app(SecurityScanService::class)->runScan(
            app(SecurityScanService::class)->requestScan($link, ScanTrigger::PERIODIC)
        );
        $this->assertSame(SecurityStatus::CLEAR, $link->fresh()->security_status);

        // ...and admins can still escalate manually.
        $this->patchJson("/api/v1/admin/security/links/{$link->id}", ['verdict' => 'MALICIOUS'])->assertOk()->assertJsonPath('data.security_status', 'MALICIOUS');
        $this->getJson('/api/v1/admin/audit-logs?action=security.manual_verdict')->assertOk()->assertJsonPath('meta.pagination.total', 2);
    }

    public function test_rescan_requests_are_deduplicated_and_audited(): void
    {
        Queue::fake();
        $owner = $this->actingAsUser();
        $saved = $this->savedLinkFor($owner);

        $this->postJson("/api/v1/links/{$saved->public_id}/security/rescan")->assertStatus(202)->assertJsonPath('data.trigger', 'RESCAN_REQUEST');
        $this->postJson("/api/v1/links/{$saved->public_id}/security/rescan")->assertStatus(202);
        $this->assertSame(1, LinkSecurityScan::query()->where('trigger', 'RESCAN_REQUEST')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.rescan_requested']);
    }

    public function test_admin_category_management(): void
    {
        $this->seedCategories();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/categories', ['slug' => 'podcasts', 'name_ar' => 'بودكاست', 'name_en' => 'Podcasts', 'parent' => 'entertainment'])
            ->assertCreated()->assertJsonPath('data.parent_slug', 'entertainment');
        $this->patchJson('/api/v1/admin/categories/podcasts', ['name_en' => 'Podcasts & Audio'])->assertOk();
        $this->deleteJson('/api/v1/admin/categories/podcasts')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->actingAsUser();
        $slugs = collect($this->getJson('/api/v1/categories')->json('data'))->pluck('slug');
        $this->assertNotContains('podcasts', $slugs, 'deactivated categories are hidden');
    }
}
