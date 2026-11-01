<?php

namespace Tests\Feature\Links;

use App\Enums\SecurityStatus;
use App\Jobs\ExtractLinkMetadataJob;
use App\Jobs\ScanLinkSecurityJob;
use App\Models\Link;
use App\Models\SavedLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SavedLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCategories();
    }

    public function test_saving_creates_private_link_and_queues_scan(): void
    {
        Queue::fake();
        $this->actingAsUser();

        $response = $this->postJson('/api/v1/links', ['url' => 'HTTPS://Example.com/Article?id=7#top']);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Link saved successfully.')
            ->assertJsonPath('data.visibility', 'PRIVATE')
            ->assertJsonPath('data.processing_status', 'PENDING')
            ->assertJsonPath('data.allow_resharing', false)
            ->assertJsonPath('data.internal_path', '/l/'.$response->json('data.public_id'));

        $link = Link::query()->firstOrFail();
        $this->assertSame('https://example.com/Article?id=7', $link->normalized_url);
        $this->assertSame('HTTPS://Example.com/Article?id=7#top', $link->original_url);
        Queue::assertPushed(ScanLinkSecurityJob::class);
        Queue::assertNotPushed(ExtractLinkMetadataJob::class); // extraction waits for the scan
    }

    public function test_deduplicates_canonical_links_and_per_user_saves(): void
    {
        Queue::fake();
        $alice = $this->actingAsUser();
        $this->postJson('/api/v1/links', ['url' => 'https://example.com/a'])->assertCreated();
        $again = $this->postJson('/api/v1/links', ['url' => 'https://EXAMPLE.com:443/a#x']);
        $again->assertOk()->assertJsonPath('meta.already_saved', true);

        $this->actingAsUser();
        $this->postJson('/api/v1/links', ['url' => 'https://example.com/a'])->assertCreated();

        $this->assertSame(1, Link::query()->count());
        $this->assertSame(2, SavedLink::query()->count());
        $this->assertSame(1, SavedLink::query()->where('user_id', $alice->id)->count());
    }

    public function test_rejects_unsafe_urls_synchronously(): void
    {
        Queue::fake();
        $this->actingAsUser();

        foreach (['javascript:alert(1)', 'file:///etc/passwd', 'https://user:pw@example.com/', 'not a url'] as $url) {
            $this->postJson('/api/v1/links', ['url' => $url])->assertStatus(422)->assertJsonPath('code', 'INVALID_URL');
        }
        foreach (['http://127.0.0.1/', 'http://169.254.169.254/latest/', 'http://[::1]/', 'http://app.localhost/', 'https://example.com:22/'] as $url) {
            $this->postJson('/api/v1/links', ['url' => $url])->assertStatus(422)->assertJsonPath('code', 'URL_NOT_ALLOWED');
        }
        $this->assertSame(0, Link::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_create_with_manual_metadata_tags_and_category(): void
    {
        Queue::fake();
        $this->actingAsUser();

        $this->postJson('/api/v1/links', [
            'url' => 'https://example.com/guide',
            'custom_title' => 'My title',
            'custom_description' => 'My description',
            'notes' => 'private note',
            'category' => 'programming',
            'tags' => ['Laravel', 'ذكاء اصطناعي'],
        ])->assertCreated()
            ->assertJsonPath('data.title', 'My title')
            ->assertJsonPath('data.notes', 'private note')
            ->assertJsonPath('data.category.slug', 'programming')
            ->assertJsonPath('data.tags.0.source', 'USER')
            ->assertJsonCount(2, 'data.tags');
    }

    public function test_list_filters_and_pagination(): void
    {
        $user = $this->actingAsUser();
        $fav = $this->savedLinkFor($user);
        $fav->forceFill(['is_favorite' => true])->save();
        $this->savedLinkFor($user);
        $this->savedLinkFor($user, link: Link::factory()->create(['source_type' => 'VIDEO']));
        $this->savedLinkFor(User::factory()->create()); // someone else's

        $this->getJson('/api/v1/links?per_page=2')->assertOk()
            ->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonPath('meta.pagination.per_page', 2)
            ->assertJsonPath('meta.pagination.has_more', true)
            ->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/links?favorite=1')->assertJsonPath('meta.pagination.total', 1)->assertJsonPath('data.0.public_id', $fav->public_id);
        $this->getJson('/api/v1/links?source_type=VIDEO')->assertJsonPath('meta.pagination.total', 1);
        $this->getJson('/api/v1/links?per_page=500')->assertStatus(422);
        $this->getJson('/api/v1/links?sort=drop_table')->assertStatus(422);
    }

    public function test_update_manual_metadata_and_precedence(): void
    {
        $user = $this->actingAsUser();
        $saved = $this->savedLinkFor($user);
        $extractedTitle = $saved->link->title;

        $this->getJson("/api/v1/links/{$saved->public_id}")->assertJsonPath('data.title', $extractedTitle);

        $this->patchJson("/api/v1/links/{$saved->public_id}", [
            'custom_title' => 'Override',
            'custom_image_url' => 'https://cdn.example.com/x.png',
            'custom_source_type' => 'DOCUMENT',
        ])->assertOk()
            ->assertJsonPath('data.title', 'Override')
            ->assertJsonPath('data.extracted.title', $extractedTitle)
            ->assertJsonPath('data.image_url', 'https://cdn.example.com/x.png')
            ->assertJsonPath('data.source_type', 'DOCUMENT');

        $this->patchJson("/api/v1/links/{$saved->public_id}", ['custom_image_url' => 'http://10.0.0.1/x.png'])
            ->assertStatus(422)->assertJsonPath('code', 'URL_NOT_ALLOWED');

        // Clearing the override falls back to extracted metadata.
        $this->patchJson("/api/v1/links/{$saved->public_id}", ['custom_title' => null])->assertJsonPath('data.title', $extractedTitle);
    }

    public function test_favorite_toggle(): void
    {
        $user = $this->actingAsUser();
        $saved = $this->savedLinkFor($user);
        $this->postJson("/api/v1/links/{$saved->public_id}/favorite")->assertJsonPath('data.is_favorite', true);
        $this->postJson("/api/v1/links/{$saved->public_id}/favorite")->assertJsonPath('data.is_favorite', false);
        $this->postJson("/api/v1/links/{$saved->public_id}/favorite", ['favorite' => true])->assertJsonPath('data.is_favorite', true);
    }

    public function test_visibility_transitions_respect_security_and_verification(): void
    {
        $user = $this->actingAsUser();
        $clear = $this->savedLinkFor($user);
        $this->patchJson("/api/v1/links/{$clear->public_id}/visibility", ['visibility' => 'PUBLIC'])
            ->assertOk()->assertJsonPath('data.visibility', 'PUBLIC');
        $this->assertNotNull($clear->fresh()->published_at);

        $this->patchJson("/api/v1/links/{$clear->public_id}/visibility", ['visibility' => 'PRIVATE'])->assertJsonPath('data.visibility', 'PRIVATE');
        $this->assertNull($clear->fresh()->published_at);

        foreach ([SecurityStatus::PENDING, SecurityStatus::UNKNOWN, SecurityStatus::SUSPICIOUS, SecurityStatus::MALICIOUS] as $status) {
            $saved = $this->savedLinkFor($user, link: Link::factory()->security($status)->create());
            $this->patchJson("/api/v1/links/{$saved->public_id}/visibility", ['visibility' => 'PUBLIC'])
                ->assertStatus(422)->assertJsonPath('code', 'LINK_NOT_PUBLISHABLE');
        }

        // Expired CLEAR verdict is no longer publishable.
        $expired = $this->savedLinkFor($user, link: Link::factory()->create(['security_expires_at' => now()->subMinute()]));
        $this->patchJson("/api/v1/links/{$expired->public_id}/visibility", ['visibility' => 'PUBLIC'])->assertStatus(422);

        $unverified = $this->actingAsUser(User::factory()->unverified()->create());
        $s = $this->savedLinkFor($unverified);
        $this->patchJson("/api/v1/links/{$s->public_id}/visibility", ['visibility' => 'PUBLIC'])->assertStatus(403)->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
    }

    public function test_delete_restore_and_permanent_delete(): void
    {
        $user = $this->actingAsUser();
        $saved = $this->savedLinkFor($user, ['visibility' => 'PUBLIC', 'published_at' => now()]);

        $this->deleteJson("/api/v1/links/{$saved->public_id}")->assertOk();
        $this->getJson("/api/v1/links/{$saved->public_id}")->assertStatus(404);
        $this->getJson('/api/v1/links?trashed=only')->assertJsonPath('meta.pagination.total', 1);

        $this->postJson("/api/v1/links/{$saved->public_id}/restore")->assertOk()->assertJsonPath('data.visibility', 'PRIVATE');

        $this->deleteJson("/api/v1/links/{$saved->public_id}", ['permanent' => true])->assertOk();
        $this->assertSame(0, SavedLink::withTrashed()->count());
    }

    public function test_resaving_a_deleted_link_restores_it_as_private(): void
    {
        Queue::fake();
        $user = $this->actingAsUser();
        $saved = $this->savedLinkFor($user, ['visibility' => 'PUBLIC', 'published_at' => now(), 'allow_resharing' => true]);
        $saved->delete();

        $this->postJson('/api/v1/links', ['url' => $saved->link->normalized_url])->assertCreated()
            ->assertJsonPath('data.public_id', $saved->public_id)
            ->assertJsonPath('data.visibility', 'PRIVATE')
            ->assertJsonPath('data.allow_resharing', false);
    }

    public function test_owner_only_operations_return_404_for_others(): void
    {
        $owner = User::factory()->create();
        $saved = $this->savedLinkFor($owner);
        $this->actingAsUser();

        $this->getJson("/api/v1/links/{$saved->public_id}")->assertStatus(404);
        $this->patchJson("/api/v1/links/{$saved->public_id}", ['custom_title' => 'pwned'])->assertStatus(404);
        $this->deleteJson("/api/v1/links/{$saved->public_id}")->assertStatus(404);
        $this->patchJson("/api/v1/links/{$saved->public_id}/visibility", ['visibility' => 'PUBLIC'])->assertStatus(404);
        $this->patchJson("/api/v1/links/{$saved->public_id}/sharing-settings", ['allow_resharing' => true])->assertStatus(404);
        $this->postJson("/api/v1/links/{$saved->public_id}/reanalyze")->assertStatus(404);
        $this->getJson("/api/v1/links/{$saved->public_id}/security")->assertStatus(404);
        $this->getJson('/api/v1/links/not-a-uuid')->assertStatus(404);
        $this->assertNull($saved->fresh()->custom_title);
    }

    public function test_processing_status_and_reanalyze(): void
    {
        Queue::fake();
        $user = $this->actingAsUser();
        $saved = $this->savedLinkFor($user);
        $version = $saved->link->extraction_version;

        $this->getJson("/api/v1/links/{$saved->public_id}/processing-status")->assertOk()->assertJsonPath('data.status', 'COMPLETED');
        $this->postJson("/api/v1/links/{$saved->public_id}/reanalyze")->assertStatus(202)->assertJsonPath('data.extraction', 'PENDING');
        $this->assertSame($version + 1, $saved->link->fresh()->extraction_version);
        Queue::assertPushed(ExtractLinkMetadataJob::class);
    }

    public function test_open_endpoint_blocks_malicious_links(): void
    {
        $user = $this->actingAsUser();
        $bad = $this->savedLinkFor($user, link: Link::factory()->security(SecurityStatus::MALICIOUS)->create());
        $ok = $this->savedLinkFor($user);

        $this->getJson("/api/v1/links/{$bad->public_id}/open")->assertStatus(403)->assertJsonPath('code', 'LINK_SECURITY_BLOCKED');
        $this->getJson("/api/v1/links/{$bad->public_id}")->assertJsonPath('data.open_url', null)->assertJsonPath('data.security.can_open', false);
        $this->getJson("/api/v1/links/{$ok->public_id}/open")->assertOk()->assertJsonPath('data.url', $ok->link->normalized_url);
    }

    public function test_tags_add_and_remove(): void
    {
        $user = $this->actingAsUser();
        $saved = $this->savedLinkFor($user);
        $this->postJson("/api/v1/links/{$saved->public_id}/tags", ['tags' => ['PHP', 'Web Dev']])->assertOk()->assertJsonCount(2, 'data.tags');
        $this->deleteJson("/api/v1/links/{$saved->public_id}/tags/web-dev")->assertOk()->assertJsonCount(1, 'data.tags');
        $this->getJson('/api/v1/tags')->assertOk()->assertJsonPath('data.0.slug', 'php');
    }
}
