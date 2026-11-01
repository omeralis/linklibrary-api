<?php

namespace Tests\Feature\Links;

use App\Models\Collection;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CollectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_crud_add_remove_and_reorder(): void
    {
        $me = $this->actingAsUser();
        [$l1, $l2, $l3] = [$this->savedLinkFor($me), $this->savedLinkFor($me), $this->savedLinkFor($me)];

        $id = $this->postJson('/api/v1/collections', ['name' => 'Reading', 'description' => 'قائمة القراءة'])
            ->assertCreated()->assertJsonPath('data.visibility', 'PRIVATE')->json('data.public_id');

        $this->postJson("/api/v1/collections/{$id}/links", ['links' => [$l1->public_id, $l2->public_id, $l3->public_id]])->assertOk()->assertJsonPath('data.added', 3);
        $this->postJson("/api/v1/collections/{$id}/links", ['links' => [$l1->public_id]])->assertJsonPath('data.added', 0); // idempotent

        $this->patchJson("/api/v1/collections/{$id}/reorder", ['links' => [$l3->public_id, $l1->public_id]])->assertOk();
        $order = collect($this->getJson("/api/v1/collections/{$id}")->assertOk()->json('data.items'))->pluck('public_id')->all();
        $this->assertSame([$l3->public_id, $l1->public_id, $l2->public_id], $order);

        $this->deleteJson("/api/v1/collections/{$id}/links/{$l1->public_id}")->assertOk();
        $this->getJson("/api/v1/collections/{$id}")->assertJsonCount(2, 'data.items');

        $this->patchJson("/api/v1/collections/{$id}", ['name' => 'Renamed'])->assertOk()->assertJsonPath('data.name', 'Renamed');
        $this->getJson('/api/v1/collections')->assertJsonPath('meta.pagination.total', 1)->assertJsonPath('data.0.items_count', 2);
        $this->deleteJson("/api/v1/collections/{$id}")->assertOk();
        $this->assertSame(0, Collection::query()->count());
        $this->assertSame(3, $me->savedLinks()->count(), 'deleting a collection keeps the links');
    }

    public function test_cross_user_manipulation_is_impossible(): void
    {
        $owner = User::factory()->create();
        $collection = Collection::factory()->create(['user_id' => $owner->id]);
        $theirLink = $this->savedLinkFor($owner);

        $me = $this->actingAsUser();
        $mine = Collection::factory()->create(['user_id' => $me->id]);

        $this->getJson("/api/v1/collections/{$collection->public_id}")->assertStatus(404);
        $this->patchJson("/api/v1/collections/{$collection->public_id}", ['name' => 'x'])->assertStatus(404);
        $this->postJson("/api/v1/collections/{$mine->public_id}/links", ['links' => [$theirLink->public_id]])->assertStatus(422)->assertJsonPath('code', 'INVALID_LINKS');

        // Defence in depth: the database itself refuses cross-user membership.
        $this->expectException(QueryException::class);
        DB::table('collection_items')->insert(['collection_id' => $mine->id, 'saved_link_id' => $theirLink->id, 'user_id' => $me->id, 'position' => 1]);
    }

    public function test_public_collections_require_verified_email(): void
    {
        $this->actingAsUser(User::factory()->unverified()->create());
        $this->postJson('/api/v1/collections', ['name' => 'x', 'visibility' => 'PUBLIC'])->assertStatus(403)->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
    }

    public function test_cover_image_url_is_validated(): void
    {
        $this->actingAsUser();
        $this->postJson('/api/v1/collections', ['name' => 'x', 'cover_image_url' => 'http://169.254.169.254/x.png'])->assertStatus(422);
        $this->postJson('/api/v1/collections', ['name' => 'x', 'cover_image_url' => 'https://img.example.com/c.png'])->assertCreated();
    }

    public function test_link_filter_by_collection(): void
    {
        $me = $this->actingAsUser();
        $in = $this->savedLinkFor($me);
        $this->savedLinkFor($me);
        $c = Collection::factory()->create(['user_id' => $me->id]);
        Sanctum::actingAs($me);
        $this->postJson("/api/v1/collections/{$c->public_id}/links", ['links' => [$in->public_id]]);
        $this->getJson("/api/v1/links?collection={$c->public_id}")->assertJsonPath('meta.pagination.total', 1);
    }
}
