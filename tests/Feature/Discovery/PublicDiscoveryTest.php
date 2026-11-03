<?php

namespace Tests\Feature\Discovery;

use App\Enums\ScanTrigger;
use App\Enums\SecurityStatus;
use App\Jobs\RecalculatePopularityJob;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Link;
use App\Models\SavedLink;
use App\Models\User;
use App\Services\Discovery\DiscoveryCache;
use App\Services\Links\TagService;
use App\Services\Security\SecurityScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublicDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCategories();
        $this->owner = User::factory()->publicProfile()->create(['username' => 'publisher']);
    }

    private function publicLink(array $attrs = [], ?Link $link = null, ?User $owner = null): SavedLink
    {
        return $this->savedLinkFor($owner ?? $this->owner, ['visibility' => 'PUBLIC', 'published_at' => now()] + $attrs, $link);
    }

    public function test_only_public_clear_links_from_active_accounts_are_listed(): void
    {
        $this->useSecurityPolicy(allowUnverifiedSharing: false, scanningComingSoon: false); // strict policy, independent of the environment
        $visible = $this->publicLink();
        $private = $this->savedLinkFor($this->owner, ['notes' => 'n']);
        $suspicious = $this->publicLink(link: Link::factory()->security(SecurityStatus::SUSPICIOUS)->create());
        $expired = $this->publicLink(link: Link::factory()->create(['security_expires_at' => now()->subHour()]));
        $deleted = $this->publicLink();
        $deleted->delete();
        $suspendedOwner = User::factory()->suspended()->create();
        $fromSuspended = $this->publicLink(owner: $suspendedOwner);

        $ids = collect($this->getJson('/api/v1/public/links')->assertOk()->json('data'))->pluck('public_id')->all();

        $this->assertSame([$visible->public_id], $ids);
        foreach ([$private, $suspicious, $expired, $deleted, $fromSuspended] as $hidden) {
            $this->getJson("/api/v1/public/links/{$hidden->public_id}")->assertStatus(404)->assertJsonPath('code', 'NOT_FOUND');
        }
    }

    public function test_public_responses_contain_no_private_fields(): void
    {
        $saved = $this->publicLink(['notes' => 'SECRET-NOTE', 'allow_resharing' => true]);

        $json = $this->getJson("/api/v1/public/links/{$saved->public_id}")->assertOk()->json('data');

        $this->assertSame('public', $json['access_role']);
        foreach (['notes', 'visibility', 'allow_resharing', 'is_favorite', 'overrides', 'extracted', 'processing', 'permissions'] as $field) {
            $this->assertArrayNotHasKey($field, $json);
        }
        $this->assertStringNotContainsString('SECRET-NOTE', json_encode($json));
        $this->assertStringNotContainsString($this->owner->email, json_encode($json));
        $this->assertSame('publisher', $json['owner']['username']);
    }

    public function test_owner_identity_hidden_when_profile_private(): void
    {
        $private = User::factory()->create();
        $saved = $this->publicLink(owner: $private);
        $this->getJson("/api/v1/public/links/{$saved->public_id}")->assertOk()->assertJsonPath('data.owner', null);
        $this->getJson("/api/v1/public/users/{$private->username}")->assertStatus(404);
    }

    public function test_unsafe_status_removes_link_from_discovery_immediately(): void
    {
        $saved = $this->publicLink();
        $this->getJson('/api/v1/public/links')->assertJsonCount(1, 'data'); // primes the cache

        $this->intel->default = SecurityStatus::MALICIOUS;
        app(SecurityScanService::class)->runScan(app(SecurityScanService::class)->requestScan($saved->link, ScanTrigger::PERIODIC));

        $this->assertSame(SecurityStatus::MALICIOUS, $saved->link->fresh()->security_status);
        $this->getJson('/api/v1/public/links')->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/public/links/{$saved->public_id}")->assertStatus(404);
    }

    public function test_visibility_change_invalidates_cached_discovery(): void
    {
        $saved = $this->publicLink();
        $this->getJson('/api/v1/public/links')->assertJsonCount(1, 'data');

        Sanctum::actingAs($this->owner);
        $this->patchJson("/api/v1/links/{$saved->public_id}/visibility", ['visibility' => 'PRIVATE'])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/public/links')->assertJsonCount(0, 'data');
    }

    public function test_filters_and_sorting(): void
    {
        $video = $this->publicLink(link: Link::factory()->create(['source_type' => 'VIDEO', 'language' => 'ar']));
        $article = $this->publicLink(['category_id' => Category::query()->where('slug', 'programming')->value('id')]);
        app(TagService::class)->addUserTags($article, ['Laravel']);
        $article->forceFill(['popularity_score' => 50])->save();

        $this->getJson('/api/v1/public/links?source_type=VIDEO')->assertJsonCount(1, 'data')->assertJsonPath('data.0.public_id', $video->public_id);
        $this->getJson('/api/v1/public/links?category=programming')->assertJsonCount(1, 'data')->assertJsonPath('data.0.public_id', $article->public_id);
        $this->getJson('/api/v1/public/links?category=technology')->assertJsonCount(1, 'data'); // parent includes children
        $this->getJson('/api/v1/public/links?tag=laravel')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/public/links?language=ar')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/public/links?sort=popular')->assertJsonPath('data.0.public_id', $article->public_id);
        $this->getJson('/api/v1/public/links?sort=hacked')->assertStatus(422);
    }

    public function test_public_tags_only_expose_tags_of_public_links(): void
    {
        $public = $this->publicLink();
        $private = $this->savedLinkFor($this->owner);
        app(TagService::class)->addUserTags($public, ['open-topic']);
        app(TagService::class)->addUserTags($private, ['secret-project-x']);

        $slugs = collect($this->getJson('/api/v1/public/tags')->assertOk()->json('data'))->pluck('slug')->all();
        $this->assertContains('open-topic', $slugs);
        $this->assertNotContains('secret-project-x', $slugs);
    }

    public function test_public_categories(): void
    {
        $this->publicLink(['category_id' => Category::query()->where('slug', 'science')->value('id')]);
        $data = collect($this->getJson('/api/v1/public/categories', ['Accept-Language' => 'ar'])->assertOk()->json('data'))->keyBy('slug');

        $this->assertSame('العلوم', $data['science']['name']);
        $this->assertSame(1, $data['science']['links_count']);
    }

    public function test_public_profile_and_collections_exclude_private_links(): void
    {
        $public = $this->publicLink();
        $private = $this->savedLinkFor($this->owner, ['custom_title' => 'PRIVATE-TITLE']);
        $collection = Collection::factory()->public()->create(['user_id' => $this->owner->id]);
        $privateCollection = Collection::factory()->create(['user_id' => $this->owner->id]);
        foreach ([$public, $private] as $i => $s) {
            DB::table('collection_items')->insert(['collection_id' => $collection->id, 'saved_link_id' => $s->id, 'user_id' => $this->owner->id, 'position' => $i]);
        }

        $this->getJson('/api/v1/public/users/publisher')->assertOk()
            ->assertJsonPath('data.stats.public_links', 1)
            ->assertJsonPath('data.stats.public_collections', 1)
            ->assertJsonMissingPath('data.email');
        $this->getJson('/api/v1/public/users/publisher/links')->assertJsonCount(1, 'data');

        $collections = $this->getJson('/api/v1/public/users/publisher/collections')->assertOk();
        $collections->assertJsonCount(1, 'data')->assertJsonPath('data.0.items_count', 1);

        $response = $this->getJson("/api/v1/public/collections/{$collection->public_id}")->assertOk();
        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.public_id', $public->public_id);
        $this->assertStringNotContainsString('PRIVATE-TITLE', $response->getContent());

        $this->getJson("/api/v1/public/collections/{$privateCollection->public_id}")->assertStatus(404);
        // Collection membership never grants access to the private link.
        $this->getJson("/api/v1/public/links/{$private->public_id}")->assertStatus(404);
    }

    public function test_views_are_counted_once_per_viewer_per_day_and_popularity_recalculated(): void
    {
        $saved = $this->publicLink();
        for ($i = 0; $i < 5; $i++) {
            $this->getJson("/api/v1/public/links/{$saved->public_id}");
        }
        $this->assertSame(1, DB::table('link_views')->where('saved_link_id', $saved->id)->count());

        $this->savedLinkFor(User::factory()->create(), link: $saved->link); // another saver of the same URL
        (new RecalculatePopularityJob)->handle(app(DiscoveryCache::class));

        $this->assertSame(4, $saved->fresh()->popularity_score); // 1 unique view + 3 per other saver
    }

    public function test_public_endpoints_are_rate_limited(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/v1/public/categories');
        }
        $this->getJson('/api/v1/public/categories')->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED');
    }
}
