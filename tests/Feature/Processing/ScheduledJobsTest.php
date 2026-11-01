<?php

namespace Tests\Feature\Processing;

use App\Enums\InvitationStatus;
use App\Enums\SecurityStatus;
use App\Jobs\CleanupExpiredInvitationsJob;
use App\Jobs\ExtractLinkMetadataJob;
use App\Jobs\PruneProcessingRecordsJob;
use App\Jobs\RefreshStaleMetadataJob;
use App\Jobs\RescanDueLinksJob;
use App\Jobs\ScanLinkSecurityJob;
use App\Models\Link;
use App\Models\LinkInvitation;
use App\Models\User;
use App\Services\Security\SecurityScanService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScheduledJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduler_registers_recurring_tasks(): void
    {
        $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->description ?? $e->command)->implode(' | ');
        foreach (['RescanDueLinksJob', 'RefreshStaleMetadataJob', 'CleanupExpiredInvitationsJob', 'PruneProcessingRecordsJob', 'RecalculatePopularityJob'] as $job) {
            $this->assertStringContainsString($job, $events);
        }
    }

    public function test_rescan_due_links(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $expiring = $this->savedLinkFor($owner, link: Link::factory()->create(['security_expires_at' => now()->addHours(2)]))->link;
        $fresh = $this->savedLinkFor($owner, link: Link::factory()->create(['security_expires_at' => now()->addDays(6)]))->link;
        $unknown = $this->savedLinkFor($owner, link: Link::factory()->security(SecurityStatus::UNKNOWN)->create(['security_checked_at' => now()->subDays(2)]))->link;
        $orphan = Link::factory()->create(['security_expires_at' => now()->addHour()]); // nobody saved it

        (new RescanDueLinksJob)->handle(app(SecurityScanService::class));

        $scanned = DB::table('link_security_scans')->where('trigger', 'PERIODIC')->pluck('link_id')->all();
        $this->assertEqualsCanonicalizing([$expiring->id, $unknown->id], $scanned);
        $this->assertNotContains($fresh->id, $scanned);
        $this->assertNotContains($orphan->id, $scanned);
        Queue::assertPushed(ScanLinkSecurityJob::class, 2);
    }

    public function test_refresh_stale_metadata_bumps_version(): void
    {
        Queue::fake();
        $link = $this->savedLinkFor(User::factory()->create(), link: Link::factory()->create(['last_extracted_at' => now()->subDays(40)]))->link;

        (new RefreshStaleMetadataJob)->handle();

        $this->assertSame(2, $link->fresh()->extraction_version);
        Queue::assertPushed(ExtractLinkMetadataJob::class);
    }

    public function test_cleanup_expired_invitations(): void
    {
        $saved = $this->savedLinkFor(User::factory()->create());
        $make = fn (array $a) => LinkInvitation::query()->create($a + ['saved_link_id' => $saved->id, 'inviter_user_id' => $saved->user_id, 'email' => uniqid().'@example.com', 'token_hash' => hash('sha256', uniqid('', true))]);
        $expired = $make(['status' => InvitationStatus::PENDING, 'expires_at' => now()->subHour()]);
        $valid = $make(['status' => InvitationStatus::PENDING, 'expires_at' => now()->addDay()]);
        $old = $make(['status' => InvitationStatus::REVOKED, 'expires_at' => now()->subDays(60)]);
        DB::table('link_invitations')->where('id', $old->id)->update(['updated_at' => now()->subDays(40)]);

        (new CleanupExpiredInvitationsJob)->handle();

        $this->assertSame(InvitationStatus::EXPIRED, $expired->fresh()->status);
        $this->assertSame(InvitationStatus::PENDING, $valid->fresh()->status);
        $this->assertNull($old->fresh());
    }

    public function test_prune_keeps_referenced_records(): void
    {
        $link = Link::factory()->create(['updated_at' => now()->subDays(60)]);
        $kept = $this->savedLinkFor(User::factory()->create())->link;
        $scanOld = DB::table('link_security_scans')->insertGetId(['link_id' => $kept->id, 'trigger' => 'PERIODIC', 'status' => 'COMPLETED', 'verdict' => 'CLEAR', 'created_at' => now()->subDays(400), 'updated_at' => now()]);
        $scanReferenced = DB::table('link_security_scans')->insertGetId(['link_id' => $kept->id, 'trigger' => 'PERIODIC', 'status' => 'COMPLETED', 'verdict' => 'CLEAR', 'created_at' => now()->subDays(400), 'updated_at' => now()]);
        DB::table('links')->where('id', $kept->id)->update(['last_security_scan_id' => $scanReferenced]);

        (new PruneProcessingRecordsJob)->handle();

        $this->assertNull(Link::query()->find($link->id), 'orphan canonical link removed');
        $this->assertNotNull(Link::query()->find($kept->id));
        $this->assertDatabaseMissing('link_security_scans', ['id' => $scanOld]);
        $this->assertDatabaseHas('link_security_scans', ['id' => $scanReferenced]);
    }
}
