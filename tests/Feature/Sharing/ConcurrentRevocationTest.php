<?php

namespace Tests\Feature\Sharing;

use App\Exceptions\ApiException;
use App\Models\LinkAccessGrant;
use App\Models\SavedLink;
use App\Models\User;
use App\Services\Sharing\AccessGraphService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

/**
 * Real concurrency: several OS processes mutate the same access graph at the same time (separate
 * PostgreSQL connections). Afterwards the graph must be consistent: no ACTIVE grant may depend on a
 * REVOKED ancestor, and effective access must match the stored statuses.
 */
class ConcurrentRevocationTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for concurrency tests.');
        }
    }

    public function test_concurrent_revocations_and_reshares_keep_the_graph_consistent(): void
    {
        for ($round = 0; $round < 4; $round++) {
            [$saved, $users] = $this->buildGraph();
            $grants = fn (User $from, User $to) => LinkAccessGrant::query()->where('saved_link_id', $saved->id)
                ->where('grantor_user_id', $from->id)->where('grantee_user_id', $to->id)->firstOrFail()->id;

            [$a, $b, $c, $d, $e, $f] = $users;
            $operations = [
                fn (AccessGraphService $g) => $g->revokeGrant(LinkAccessGrant::query()->findOrFail($grants($a, $b)), $a),
                fn (AccessGraphService $g) => $g->revokeGrant(LinkAccessGrant::query()->findOrFail($grants($b, $c)), $b),
                fn (AccessGraphService $g) => $g->revokeGrant(LinkAccessGrant::query()->findOrFail($grants($a, $b)), $a), // duplicate
                fn (AccessGraphService $g) => DB::transaction(function () use ($g, $saved, $c, $e) {
                    $g->lockSavedLink($saved->id);
                    $g->createGrantLocked(SavedLink::query()->findOrFail($saved->id), $c, $e);
                }),
                fn (AccessGraphService $g) => DB::transaction(function () use ($g, $saved, $d, $f) {
                    $g->lockSavedLink($saved->id);
                    $g->createGrantLocked(SavedLink::query()->findOrFail($saved->id), $d, $f);
                }),
            ];

            $this->runConcurrently($operations);
            $this->assertGraphConsistent($saved);
        }
    }

    /** @return array{0: SavedLink, 1: list<User>} */
    private function buildGraph(): array
    {
        $users = User::factory()->count(6)->create()->all();
        [$a, $b, $c, $d] = $users;
        $saved = $this->savedLinkFor($a, ['allow_resharing' => true]);
        $graph = app(AccessGraphService::class);
        $edge = function (User $from, User $to) use ($graph, $saved) {
            DB::transaction(function () use ($graph, $saved, $from, $to) {
                $graph->lockSavedLink($saved->id);
                $graph->createGrantLocked($saved, $from, $to);
            });
        };
        $edge($a, $b); // A → B → C → D
        $edge($b, $c);
        $edge($c, $d);

        return [$saved, $users];
    }

    /** @param list<callable(AccessGraphService): mixed> $operations */
    private function runConcurrently(array $operations): void
    {
        DB::disconnect();
        $pids = [];
        foreach ($operations as $operation) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $ok = true;
                try {
                    DB::purge();
                    usleep(random_int(0, 20_000));
                    $operation(app(AccessGraphService::class));
                } catch (ApiException) {
                    // Legitimate rejection (e.g. the grantor lost access concurrently).
                } catch (Throwable $t) {
                    fwrite(STDERR, $t->getMessage()."\n");
                    $ok = false;
                }
                // Leave without running PHPUnit/Laravel shutdown handlers in the child.
                pcntl_exec($ok ? '/bin/true' : '/bin/false');
            }
            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status), 'a concurrent operation failed unexpectedly');
        }
        DB::reconnect();
    }

    private function assertGraphConsistent(SavedLink $saved): void
    {
        $grants = LinkAccessGrant::query()->where('saved_link_id', $saved->id)->get()->keyBy('id');
        foreach ($grants as $grant) {
            if ($grant->status->value !== 'ACTIVE') {
                continue;
            }
            for ($p = $grant->parent_grant_id; $p !== null; $p = $grants[$p]->parent_grant_id) {
                $this->assertSame('ACTIVE', $grants[$p]->status->value, "ACTIVE grant {$grant->id} depends on revoked ancestor {$p}");
            }
        }

        $graph = app(AccessGraphService::class);
        foreach ($grants->pluck('grantee_user_id')->unique() as $userId) {
            $user = User::query()->findOrFail($userId);
            $hasActive = $grants->where('grantee_user_id', $userId)->contains(fn ($g) => $g->status->value === 'ACTIVE');
            $this->assertSame($hasActive, $graph->canView($saved->fresh(), $user));
        }
    }
}
