<?php

namespace App\Services\Sharing;

use App\Enums\InvitationStatus;
use App\Exceptions\ApiException;
use App\Models\LinkInvitation;
use App\Models\SavedLink;
use App\Models\User;
use App\Notifications\AccessRevokedNotification;
use App\Notifications\InvitationAcceptedNotification;
use App\Notifications\LinkInvitationNotification;
use App\Services\Audit\AuditAction;
use App\Services\Audit\AuditLogger;
use App\Services\Security\SecurityPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * E-mail invitations to private links.
 *
 *  - Tokens are 256-bit random values; only their SHA-256 hash is stored. The plaintext token is
 *    delivered exclusively by e-mail and never returned by the API or written to logs.
 *  - Accepting requires authentication AND a verified e-mail address equal to the invited address.
 *  - Creating and accepting require the link to be currently CLEAR, and the inviter to still be
 *    authorised (owner, or a valid grant + resharing enabled).
 */
class InvitationService
{
    public function __construct(
        private readonly AccessGraphService $graph,
        private readonly SecurityPolicy $policy,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{0: LinkInvitation, 1: string} invitation + plaintext token (for the e-mail only) */
    public function invite(SavedLink $savedLink, User $inviter, string $email): array
    {
        $email = User::normalizeEmail($email);
        $savedLink->loadMissing(['link', 'user']);

        if (! $this->policy->mayShare($savedLink->link)) {
            throw ApiException::unprocessable('LINK_NOT_SHAREABLE', 'api.sharing.not_shareable', [
                'security_status' => [$savedLink->link->effectiveSecurityStatus()->value],
            ]);
        }
        if ($email === $inviter->email) {
            throw ApiException::unprocessable('CANNOT_SHARE_WITH_SELF', 'api.sharing.self');
        }
        if ($email === $savedLink->user->email) {
            throw ApiException::conflict('ALREADY_OWNER', 'api.sharing.already_owner');
        }

        $token = bin2hex(random_bytes(32));

        $invitation = DB::transaction(function () use ($savedLink, $inviter, $email, $token): LinkInvitation {
            $locked = $this->graph->lockSavedLink($savedLink->id);
            if ($locked === null || $locked->trashed()) {
                throw ApiException::notFound();
            }

            $inviterGrant = null;
            if ($inviter->id !== $locked->user_id) {
                if (! $locked->allow_resharing) {
                    throw ApiException::forbidden('api.sharing.resharing_disabled');
                }
                $inviterGrant = $this->graph->bestValidGrant($locked, $inviter);
                if ($inviterGrant === null) {
                    throw ApiException::notFound();
                }
            }

            $invitee = User::query()->where('email', $email)->first();
            if ($invitee !== null) {
                $edgeExists = $locked->activeGrants()
                    ->where('grantor_user_id', $inviter->id)
                    ->where('grantee_user_id', $invitee->id)
                    ->exists();
                if ($edgeExists) {
                    throw ApiException::conflict('ALREADY_SHARED', 'api.sharing.already_shared');
                }
            }

            $pending = LinkInvitation::query()
                ->where('saved_link_id', $locked->id)
                ->where('status', InvitationStatus::PENDING->value)
                ->where('expires_at', '>', now())
                ->count();
            if ($pending >= (int) config('linklibrary.sharing.max_pending_invitations_per_link', 50)) {
                throw ApiException::conflict('TOO_MANY_PENDING_INVITATIONS', 'api.sharing.too_many_pending');
            }

            // Re-inviting the same address replaces the previous pending invitation (new token).
            LinkInvitation::query()
                ->where('saved_link_id', $locked->id)
                ->where('inviter_user_id', $inviter->id)
                ->where('email', $email)
                ->where('status', InvitationStatus::PENDING->value)
                ->update(['status' => InvitationStatus::REVOKED->value, 'revoked_at' => now(), 'revoked_by_user_id' => $inviter->id, 'updated_at' => now()]);

            return LinkInvitation::query()->create([
                'saved_link_id' => $locked->id,
                'inviter_user_id' => $inviter->id,
                'inviter_grant_id' => $inviterGrant?->id,
                'email' => $email,
                'token_hash' => LinkInvitation::hashToken($token),
                'status' => InvitationStatus::PENDING,
                'expires_at' => now()->addHours((int) config('linklibrary.sharing.invitation_ttl_hours', 168)),
            ]);
        });

        $notification = new LinkInvitationNotification($token, $invitation->public_id, $inviter->name, $invitation->expires_at);
        $recipient = User::query()->where('email', $email)->first();
        if ($recipient !== null) {
            $recipient->notify($notification);
        } else {
            Notification::route('mail', $email)->notify($notification);
        }

        $this->audit->log(AuditAction::INVITATION_SENT, $inviter, 'saved_link', $savedLink->public_id, [
            'invitation_id' => $invitation->public_id,
            'recipient_domain' => substr((string) strrchr($email, '@'), 1),
            'via_grant' => $invitation->inviter_grant_id !== null,
        ]);

        return [$invitation, $token];
    }

    public function acceptByToken(string $token, User $user): SavedLink
    {
        if (strlen($token) !== 64 || ! ctype_xdigit($token)) {
            throw new ApiException('INVITATION_NOT_FOUND', 404, 'api.sharing.invitation_not_found');
        }
        $invitation = LinkInvitation::query()->where('token_hash', LinkInvitation::hashToken(strtolower($token)))->first();

        return $this->accept($invitation, $user);
    }

    /** In-app acceptance (the invitation is addressed to the user's verified e-mail). */
    public function acceptById(string $publicId, User $user): SavedLink
    {
        $invitation = LinkInvitation::isValidPublicId($publicId)
            ? LinkInvitation::query()->where('public_id', $publicId)->first()
            : null;

        return $this->accept($invitation, $user);
    }

    private function accept(?LinkInvitation $found, User $user): SavedLink
    {
        if (! $user->hasVerifiedEmail()) {
            throw new ApiException('EMAIL_NOT_VERIFIED', 403, 'api.auth.email_not_verified');
        }
        // Wrong recipient and unknown invitation are indistinguishable to the caller.
        if ($found === null || ! hash_equals($found->email, $user->email)) {
            throw new ApiException('INVITATION_NOT_FOUND', 404, 'api.sharing.invitation_not_found');
        }

        [$savedLink, $invitation, $alreadyAccepted] = DB::transaction(function () use ($found, $user) {
            $savedLink = $this->graph->lockSavedLink($found->saved_link_id);
            $invitation = LinkInvitation::query()->whereKey($found->id)->lockForUpdate()->firstOrFail();

            if ($invitation->status === InvitationStatus::ACCEPTED && $invitation->accepted_by_user_id === $user->id) {
                return [$savedLink, $invitation, true];
            }
            if ($invitation->effectiveStatus() === InvitationStatus::EXPIRED) {
                throw new ApiException('INVITATION_EXPIRED', 410, 'api.sharing.invitation_expired');
            }
            if ($invitation->status !== InvitationStatus::PENDING) {
                throw new ApiException('INVITATION_NOT_PENDING', 409, 'api.sharing.invitation_not_pending');
            }
            if ($savedLink === null || $savedLink->trashed()) {
                throw new ApiException('INVITATION_NOT_FOUND', 404, 'api.sharing.invitation_not_found');
            }
            $savedLink->load(['link', 'user']);
            if (! $savedLink->user->isActive()) {
                throw new ApiException('INVITATION_NOT_FOUND', 404, 'api.sharing.invitation_not_found');
            }
            if (! $this->policy->mayShare($savedLink->link)) {
                throw ApiException::unprocessable('LINK_NOT_SHAREABLE', 'api.sharing.not_shareable');
            }

            $inviter = User::query()->findOrFail($invitation->inviter_user_id);
            if ($inviter->id !== $savedLink->user_id) {
                $stillAuthorised = $savedLink->allow_resharing
                    && $invitation->inviter_grant_id !== null
                    && in_array($invitation->inviter_grant_id, $this->graph->validGrantIdsForGrantee($inviter->id, $savedLink->id), true);
                if (! $stillAuthorised) {
                    throw ApiException::forbidden('api.sharing.inviter_not_authorized');
                }
            }

            if ($user->id === $savedLink->user_id) {
                throw ApiException::conflict('ALREADY_OWNER', 'api.sharing.already_owner');
            }

            $this->graph->createGrantLocked($savedLink, $inviter, $user, $invitation);

            $invitation->update([
                'status' => InvitationStatus::ACCEPTED,
                'accepted_at' => now(),
                'accepted_by_user_id' => $user->id,
            ]);

            return [$savedLink, $invitation, false];
        });

        if (! $alreadyAccepted) {
            $this->audit->log(AuditAction::INVITATION_ACCEPTED, $user, 'saved_link', $savedLink->public_id, ['invitation_id' => $invitation->public_id]);
            $inviter = User::query()->find($invitation->inviter_user_id);
            $inviter?->notify(new InvitationAcceptedNotification($savedLink->public_id, $user->name));
        }

        return $savedLink;
    }

    public function revoke(LinkInvitation $invitation, User $actor): void
    {
        DB::transaction(function () use ($invitation, $actor) {
            $this->graph->lockSavedLink($invitation->saved_link_id);
            $fresh = LinkInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            if ($fresh->status === InvitationStatus::PENDING) {
                $fresh->update(['status' => InvitationStatus::REVOKED, 'revoked_at' => now(), 'revoked_by_user_id' => $actor->id]);
            }
        });

        $this->audit->log(AuditAction::INVITATION_REVOKED, $actor, 'saved_link', $invitation->savedLink->public_id, ['invitation_id' => $invitation->public_id]);
    }

    /**
     * Notify users who lost access.
     *
     * @param  list<int>  $userIds
     */
    public function notifyRevoked(SavedLink $savedLink, array $userIds): void
    {
        foreach (User::query()->whereIn('id', $userIds)->get() as $user) {
            $user->notify(new AccessRevokedNotification($savedLink->public_id));
        }
    }
}
