<?php

namespace Tests\Feature\Discovery;

use App\Models\Collection;
use App\Models\SavedLink;
use App\Models\User;
use App\Services\Discovery\DiscoveryCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Sharing\SharingTestHelpers;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase, SharingTestHelpers;

    private function linkWith(User $owner, string $title, array $attrs = []): SavedLink
    {
        return $this->savedLinkFor($owner, $attrs, $this->clearLink(['title' => $title, 'description' => null]));
    }

    public function test_own_search_matches_titles_notes_with_prefix_and_stemming(): void
    {
        $me = $this->actingAsUser();
        $a = $this->linkWith($me, 'Running Laravel queues in production');
        $b = $this->linkWith($me, 'Cooking pasta', ['notes' => 'remember the kubernetes migration']);
        $this->linkWith(User::factory()->create(), 'Laravel secrets of another user');

        $this->getJson('/api/v1/search?q=laravel')->assertOk()->assertJsonPath('meta.pagination.total', 1)->assertJsonPath('data.0.public_id', $a->public_id);
        $this->getJson('/api/v1/search?q=lara')->assertJsonPath('meta.pagination.total', 1);           // prefix
        $this->getJson('/api/v1/search?q=run')->assertJsonPath('meta.pagination.total', 1);            // stem/prefix
        $this->getJson('/api/v1/search?q=kubernetes')->assertJsonPath('data.0.public_id', $b->public_id); // own notes
    }

    public function test_arabic_search_is_normalization_aware(): void
    {
        $me = $this->actingAsUser();
        $saved = $this->linkWith($me, 'مقدمة في الذكاء الاصطناعي للمبتدئين');

        $this->getJson('/api/v1/search?q='.urlencode('الذكاء'))->assertJsonPath('data.0.public_id', $saved->public_id);
        $this->getJson('/api/v1/search?q='.urlencode('مقدمه'))->assertJsonPath('meta.pagination.total', 1); // ة/ه folding
    }

    public function test_search_never_returns_unauthorized_private_content(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $stranger = User::factory()->create();
        $shared = $this->linkWith($owner, 'Quantum computing notes', ['notes' => 'confidential-merger-plan', 'allow_resharing' => false]);
        Notification::fake();
        $this->share($owner, $shared, $viewer);

        // Viewer: finds by public fields, never by the owner's notes.
        Sanctum::actingAs($viewer);
        $this->getJson('/api/v1/search?q=quantum&scope=shared')->assertJsonPath('meta.pagination.total', 1)->assertJsonMissingPath('data.0.notes');
        $this->getJson('/api/v1/search?q=confidential&scope=shared')->assertJsonPath('meta.pagination.total', 0);
        $this->getJson('/api/v1/search?q=confidential&scope=all')->assertJsonPath('meta.pagination.total', 0);
        $this->getJson('/api/v1/search?q=quantum&scope=all')->assertJsonPath('data.0.access_role', 'viewer');

        // Stranger: nothing.
        Sanctum::actingAs($stranger);
        foreach (['own', 'shared', 'all'] as $scope) {
            $this->getJson("/api/v1/search?q=quantum&scope={$scope}")->assertJsonPath('meta.pagination.total', 0);
        }

        // Public search: private link and notes invisible; public link searchable without notes.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/public/search?q=quantum')->assertJsonCount(0, 'data');
        $public = $this->linkWith($owner, 'Public quantum primer', ['visibility' => 'PUBLIC', 'published_at' => now(), 'notes' => 'owner-scratchpad']);
        app(DiscoveryCache::class)->invalidate(); // created directly in the DB (the API path invalidates itself)
        $this->getJson('/api/v1/public/search?q=quantum')->assertJsonCount(1, 'data')->assertJsonPath('data.0.public_id', $public->public_id);
        $this->getJson('/api/v1/public/search?q=scratchpad')->assertJsonCount(0, 'data');
    }

    public function test_query_validation(): void
    {
        $this->actingAsUser();
        $this->getJson('/api/v1/search')->assertStatus(422);
        $this->getJson('/api/v1/search?q=a')->assertStatus(422);
        $this->getJson('/api/v1/search?q='.str_repeat('x', 300))->assertStatus(422);
        $this->getJson('/api/v1/search?q='.urlencode("'); DROP TABLE users; --"))->assertOk();
        $this->getJson('/api/v1/search?q='.urlencode('!!! ***'))->assertStatus(422)->assertJsonValidationErrors(['q']);
        $this->getJson('/api/v1/public/search')->assertStatus(422);
    }

    public function test_collection_name_search(): void
    {
        $me = $this->actingAsUser();
        Collection::factory()->create(['user_id' => $me->id, 'name' => 'Reading list 2026']);
        Collection::factory()->create(['name' => 'Reading other user']);
        $this->getJson('/api/v1/search?q=reading&type=collections')->assertOk()->assertJsonCount(1, 'data');
    }
}
