<?php

namespace App\Services\Sharing;

use App\Enums\GrantStatus;
use App\Enums\InvitationStatus;
use App\Exceptions\ApiException;
use App\Models\LinkAccessGrant;
use App\Models\LinkInvitation;
use App\Models\SavedLink;
use App\Models\User;
use App\Services\Audit\AuditAction;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Access graph for private links.
 *
 * Model
 *  - Each grant is an explicit edge grantor → grantee on one saved link.
 *  - Root grants (parent_grant_id NULL) must be issued by the link owner.
 *  - A derived grant records the exact parent grant through which the grantor held access, and the
 *    grantor of a derived grant must be the grantee of its parent.
 *
 * Effective access
 *  - A grant is VALID iff it and every ancestor are ACTIVE, the edge chain is consistent and the root
 *    was issued by the current owner. A user has access iff they own the link or hold ≥ 1 valid grant.
 *  - Multiple independent paths are supported: revoking one path does not affect another.
 *  - Lineage is strict: a derived grant depends only on the parent grant it was created through. If
 *    that parent is revoked, the derived grant is revoked as well, even if the grantor still has access
 *    through a different path (the grantor may re-share explicitly). This prevents access from silently
 *    "re-attaching" to a path the revoking party did not intend to authorise.
 *
 * Integrity
 *  - All mutations for a saved link are serialized with SELECT … FOR UPDATE on the saved link row.
 *  - Cycles are impossible: a grantee may not be the owner or anyone on the parent chain.
 *  - Revocation marks the grant and all ACTIVE descendants REVOKED in one statement, and revokes
 *    pending invitations that were issued through any revoked grant.
 */
class AccessGraphService
{
    private const MAX_DEPTH = 64;

    public function __construct(private readonly AuditLogger $audit) {}

    public function canView(SavedLink $savedLink, ?User $user): bool
    {
        if ($user === null || $savedLink->trashed()) {
            return false;
        }
        if ($savedLink->user_id === $user->id) {
            return true;
        }

        return $this->bestValidGrant($savedLink, $user) !== null;
    }

    /**
     * The valid grant closest to the owner (then oldest) — used as parent when the user reshares.
     */
    public function bestValidGrant(SavedLink $savedLink, User $user): ?LinkAccessGrant
    {
        $ids = $this->validGrantIdsForGrantee($user->id, $savedLink->id);
        if ($ids === []) {
            return null;
        }

        return LinkAccessGrant::query()
            ->whereIn('id', $ids)
            ->orderBy('depth')
            ->orderBy('id')
            ->first();
    }

    public function canReshare(SavedLink $savedLink, User $user): bool
    {
        if ($savedLink->trashed()) {
            return false;
        }
        if ($savedLink->user_id === $user->id) {
            return true;
        }

        return $savedLink->allow_resharing && $this->bestValidGrant($savedLink, $user) !== null;
    }

    /**
     * IDs of the user's grants that are currently valid (walks each chain upwards to the root).
     *
     * @return list<int>
     */
    public function validGrantIdsForGrantee(int $userId, ?int $savedLinkId = null): array
    {
        $linkFilter = $savedLinkId !== null ? 'AND g.saved_link_id = ?' : '';
        $bindings = $savedLinkId !== null ? [$userId, $savedLinkId] : [$userId];

        $sql = <<<SQL
            WITH RECURSIVE chain (start_id, cur_id, parent_id, status, grantor, grantee, saved_link_id, consistent, lvl) AS (
                SELECT g.id, g.id, g.parent_grant_id, g.status, g.grantor_user_id, g.grantee_user_id, g.saved_link_id, TRUE, 1
                FROM link_access_grants g
                WHERE g.grantee_user_id = ? AND g.status = 'ACTIVE' {$linkFilter}
              UNION ALL
                SELECT c.start_id, p.id, p.parent_grant_id, p.status, p.grantor_user_id, p.grantee_user_id, p.saved_link_id,
                       (p.grantee_user_id = c.grantor AND p.saved_link_id = c.saved_link_id), c.lvl + 1
                FROM link_access_grants p
                JOIN chain c ON p.id = c.parent_id
                WHERE c.lvl < ?
            )
            SELECT c.start_id
            FROM chain c
            JOIN saved_links s ON s.id = c.saved_link_id
            WHERE s.deleted_at IS NULL
            GROUP BY c.start_id, s.user_id
            HAVING bool_and(c.status = 'ACTIVE' AND c.consistent)
               AND bool_or(c.parent_id IS NULL AND c.grantor = s.user_id)
        SQL;

        $bindings[] = self::MAX_DEPTH;

        return array_map(fn ($row) => (int) $row->start_id, DB::select($sql, $bindings));
    }

    /**
     * Saved link IDs (not deleted) the user can view through at least one valid grant.
     *
     * @return list<int>
     */
    public function accessibleSavedLinkIds(User $user): array
    {
        $grantIds = $this->validGrantIdsForGrantee($user->id);
        if ($grantIds === []) {
            return [];
        }

        return LinkAccessGrant::query()
            ->whereIn('id', $grantIds)
            ->distinct()
            ->pluck('saved_link_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Create (or return the existing identical) grant. Must be called inside a transaction that holds
     * the saved link row lock (see lockSavedLink()).
     */
    public function createGrantLocked(SavedLink $savedLink, User $grantor, User $grantee, ?LinkInvitation $invitation = null): LinkAccessGrant
    {
        if ($savedLink->trashed()) {
            throw ApiException::notFound();
        }
        if ($grantee->id === $savedLink->user_id) {
            throw ApiException::conflict('ALREADY_OWNER', 'api.sharing.already_owner');
        }
        if ($grantee->id === $grantor->id) {
            throw ApiException::unprocessable('CANNOT_SHARE_WITH_SELF', 'api.sharing.self');
        }

        $parent = null;
        if ($grantor->id !== $savedLink->user_id) {
            if (! $savedLink->allow_resharing) {
                throw ApiException::forbidden('api.sharing.resharing_disabled');
            }
            $parent = $this->bestValidGrant($savedLink, $grantor);
            if ($parent === null) {
                throw ApiException::forbidden('api.sharing.no_access');
            }
            if (in_array($grantee->id, $this->ancestorUserIds($parent), true)) {
                throw ApiException::conflict('GRANT_CYCLE', 'api.sharing.cycle');
            }
        }

        $existing = LinkAccessGrant::query()
            ->where('saved_link_id', $savedLink->id)
            ->where('grantor_user_id', $grantor->id)
            ->where('grantee_user_id', $grantee->id)
            ->where('status', GrantStatus::ACTIVE->value)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $grant = LinkAccessGrant::query()->create([
            'saved_link_id' => $savedLink->id,
            'grantor_user_id' => $grantor->id,
            'grantee_user_id' => $grantee->id,
            'parent_grant_id' => $parent?->id,
            'invitation_id' => $invitation?->id,
            'depth' => $parent === null ? 1 : $parent->depth + 1,
            'status' => GrantStatus::ACTIVE,
        ]);

        $this->audit->log(AuditAction::GRANT_CREATED, $grantor, 'saved_link', $savedLink->public_id, [
            'grant_id' => $grant->public_id,
            'grantee_id' => $grantee->public_id,
            'parent_grant_id' => $parent?->public_id,
            'depth' => $grant->depth,
        ]);

        return $grant;
    }

    /**
     * Revoke a grant and every dependent grant. Idempotent.
     *
     * @return list<int> user IDs whose grants were revoked by this call
     */
    public function revokeGrant(LinkAccessGrant $grant, ?User $actor, string $reason = 'REVOKED'): array
    {
        return DB::transaction(function () use ($grant, $actor, $reason): array {
            $this->lockSavedLink($grant->saved_link_id);

            return $this->revokeSubtreeLocked([$grant->id], $actor, $reason, $grant->saved_link_id);
        });
    }

    /**
     * Revoke every grant and pending invitation of a saved link (e.g. on deletion).
     *
     * @return list<int>
     */
    public function revokeAllForSavedLink(SavedLink $savedLink, ?User $actor, string $reason): array
    {
        return DB::transaction(function () use ($savedLink, $actor, $reason): array {
            $this->lockSavedLink($savedLink->id);
            $rootIds = LinkAccessGrant::query()
                ->where('saved_link_id', $savedLink->id)
                ->where('status', GrantStatus::ACTIVE->value)
                ->pluck('id')->map(fn ($id) => (int) $id)->all();

            $affected = $rootIds === [] ? [] : $this->revokeSubtreeLocked($rootIds, $actor, $reason, $savedLink->id);

            LinkInvitation::query()
                ->where('saved_link_id', $savedLink->id)
                ->where('status', InvitationStatus::PENDING->value)
                ->update(['status' => InvitationStatus::REVOKED->value, 'revoked_at' => now(), 'revoked_by_user_id' => $actor?->id, 'updated_at' => now()]);

            return $affected;
        });
    }

    /**
     * Revoke every grant held by a user (account deletion), cascading to dependants.
     */
    public function revokeAllHeldBy(User $user, string $reason): void
    {
        $grants = LinkAccessGrant::query()
            ->where('grantee_user_id', $user->id)
            ->where('status', GrantStatus::ACTIVE->value)
            ->get();

        foreach ($grants as $grant) {
            $this->revokeGrant($grant, null, $reason);
        }
    }

    /**
     * @param  list<int>  $grantIds
     * @return list<int>
     */
    private function revokeSubtreeLocked(array $grantIds, ?User $actor, string $reason, int $savedLinkId): array
    {
        $placeholders = implode(',', array_fill(0, count($grantIds), '?'));
        $rows = DB::select(<<<SQL
            WITH RECURSIVE subtree (id, lvl) AS (
                SELECT id, 1 FROM link_access_grants WHERE id IN ({$placeholders}) AND saved_link_id = ?
              UNION
                SELECT c.id, s.lvl + 1 FROM link_access_grants c JOIN subtree s ON c.parent_grant_id = s.id WHERE s.lvl < ?
            )
            UPDATE link_access_grants g
               SET status = 'REVOKED',
                   revoked_at = NOW(),
                   revoked_by_user_id = ?,
                   revoke_reason = CASE WHEN g.id IN ({$placeholders}) THEN ? ELSE 'PARENT_REVOKED' END,
                   updated_at = NOW()
              FROM subtree
             WHERE g.id = subtree.id AND g.status = 'ACTIVE'
            RETURNING g.id, g.public_id, g.grantee_user_id
        SQL, [...$grantIds, $savedLinkId, self::MAX_DEPTH, $actor?->id, ...$grantIds, $reason]);

        if ($rows === []) {
            return [];
        }

        $revokedIds = array_map(fn ($r) => (int) $r->id, $rows);

        // Pending invitations issued through a revoked grant can no longer be accepted.
        LinkInvitation::query()
            ->whereIn('inviter_grant_id', $revokedIds)
            ->where('status', InvitationStatus::PENDING->value)
            ->update(['status' => InvitationStatus::REVOKED->value, 'revoked_at' => now(), 'revoked_by_user_id' => $actor?->id, 'updated_at' => now()]);

        $savedLink = SavedLink::withTrashed()->find($savedLinkId);
        foreach ($rows as $row) {
            $this->audit->log(AuditAction::GRANT_REVOKED, $actor, 'saved_link', $savedLink?->public_id, [
                'grant_id' => $row->public_id,
                'reason' => in_array((int) $row->id, $grantIds, true) ? $reason : 'PARENT_REVOKED',
            ]);
        }

        return array_values(array_unique(array_map(fn ($r) => (int) $r->grantee_user_id, $rows)));
    }

    /**
     * Users on the chain from the owner down to (and including) the parent grant's grantee.
     *
     * @return list<int>
     */
    private function ancestorUserIds(LinkAccessGrant $grant): array
    {
        $rows = DB::select(<<<'SQL'
            WITH RECURSIVE up (id, parent_id, grantor, grantee, lvl) AS (
                SELECT id, parent_grant_id, grantor_user_id, grantee_user_id, 1 FROM link_access_grants WHERE id = ?
              UNION ALL
                SELECT p.id, p.parent_grant_id, p.grantor_user_id, p.grantee_user_id, u.lvl + 1
                FROM link_access_grants p JOIN up u ON p.id = u.parent_id WHERE u.lvl < ?
            )
            SELECT grantor, grantee FROM up
        SQL, [$grant->id, self::MAX_DEPTH]);

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row->grantor;
            $ids[] = (int) $row->grantee;
        }

        return array_values(array_unique($ids));
    }

    /** Serialize all access-graph mutations for one saved link. */
    public function lockSavedLink(int $savedLinkId): ?SavedLink
    {
        return SavedLink::withTrashed()->whereKey($savedLinkId)->lockForUpdate()->first();
    }
}
