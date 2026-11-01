<?php

namespace App\Services\Security;

use App\Enums\ExtractionStatus;
use App\Enums\InvitationStatus;
use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Enums\SecuritySource;
use App\Enums\SecurityStatus;
use App\Jobs\ExtractLinkMetadataJob;
use App\Jobs\ScanLinkSecurityJob;
use App\Models\Link;
use App\Models\LinkInvitation;
use App\Models\LinkSecurityScan;
use App\Models\SavedLink;
use App\Models\User;
use App\Notifications\SecurityWarningNotification;
use App\Services\Audit\AuditAction;
use App\Services\Audit\AuditLogger;
use App\Services\Discovery\DiscoveryCache;
use App\Services\Url\InvalidUrlException;
use App\Services\Url\NormalizedUrl;
use App\Services\Url\UrlNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Orchestrates reputation scans and applies verdicts to canonical links.
 *
 *  - A failed or unavailable provider never produces CLEAR (see ReputationService::aggregate()).
 *  - Verdicts are applied in scan order: an older scan never overwrites a newer one.
 *  - Manual review decisions (appeals/admin) take precedence over provider results until they expire.
 *  - When a link becomes unsafe, it disappears from public discovery immediately (eligibility is
 *    evaluated at query time and the discovery cache is invalidated), owners are notified, and for
 *    MALICIOUS links pending invitations are revoked and viewer access is suspended.
 */
class SecurityScanService
{
    public function __construct(
        private readonly ReputationService $reputation,
        private readonly UrlNormalizer $normalizer,
        private readonly AuditLogger $audit,
        private readonly DiscoveryCache $discoveryCache,
        private readonly SecurityPolicy $policy,
    ) {}

    public function requestScan(Link $link, ScanTrigger $trigger, ?User $requestedBy = null): LinkSecurityScan
    {
        $scan = LinkSecurityScan::query()->create([
            'link_id' => $link->id,
            'trigger' => $trigger,
            'status' => ScanStatus::PENDING,
            'requested_by_user_id' => $requestedBy?->id,
        ]);

        ScanLinkSecurityJob::dispatch($scan->id)->afterCommit();

        return $scan;
    }

    public function runScan(LinkSecurityScan $scan): void
    {
        if ($scan->status === ScanStatus::COMPLETED) {
            return;
        }

        $link = Link::query()->find($scan->link_id);
        if ($link === null) {
            return;
        }

        $scan->update(['status' => ScanStatus::PROCESSING, 'started_at' => now()]);

        try {
            $url = $this->normalizer->normalize($link->normalized_url);
        } catch (InvalidUrlException) {
            $scan->update(['status' => ScanStatus::FAILED, 'error_code' => 'INVALID_URL', 'completed_at' => now()]);

            return;
        }

        ['verdict' => $verdict, 'results' => $results] = $this->reputation->evaluate($url);

        $scan->update([
            'status' => ScanStatus::COMPLETED,
            'verdict' => $verdict,
            'provider_results' => array_map(fn (ProviderVerdict $r) => $r->toArray(), $results),
            'completed_at' => now(),
        ]);

        $this->applyVerdict($link, $scan, $verdict);
    }

    /** Mark a scan failed after the job exhausted its retries (status stays as is; never CLEAR). */
    public function markScanFailed(LinkSecurityScan $scan, string $errorCode): void
    {
        $scan->update(['status' => ScanStatus::FAILED, 'error_code' => $errorCode, 'completed_at' => now()]);

        $link = Link::query()->find($scan->link_id);
        if ($link !== null && $link->security_status === SecurityStatus::PENDING) {
            $this->applyVerdict($link, $scan, SecurityStatus::UNKNOWN);
        }
    }

    /**
     * Check a URL discovered while processing a link (e.g. redirect target). Only escalates.
     */
    public function checkRelatedUrl(Link $link, NormalizedUrl $url, ScanTrigger $trigger): void
    {
        ['verdict' => $verdict, 'results' => $results] = $this->reputation->evaluate($url);

        $scan = LinkSecurityScan::query()->create([
            'link_id' => $link->id,
            'trigger' => $trigger,
            'status' => ScanStatus::COMPLETED,
            'verdict' => $verdict,
            'provider_results' => array_map(fn (ProviderVerdict $r) => $r->toArray() + ['url_host' => $url->host], $results),
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        if ($verdict->isUnsafe()) {
            $this->applyVerdict($link, $scan, $verdict);
        }
    }

    /**
     * Supplementary AI signal: may raise CLEAR/UNKNOWN to SUSPICIOUS; can never lower a status.
     *
     * @param  list<string>  $reasons
     */
    public function raiseSuspicion(Link $link, array $reasons): void
    {
        $link->refresh();
        if ($link->security_status->isUnsafe() || $link->security_source === SecuritySource::MANUAL_REVIEW) {
            return;
        }

        $scan = LinkSecurityScan::query()->create([
            'link_id' => $link->id,
            'trigger' => ScanTrigger::AI_SIGNAL,
            'status' => ScanStatus::COMPLETED,
            'verdict' => SecurityStatus::SUSPICIOUS,
            'provider_results' => [['provider' => 'ai', 'verdict' => 'SUSPICIOUS', 'authoritative' => false, 'threats' => array_slice($reasons, 0, 5), 'error_code' => null]],
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $this->applyVerdict($link, $scan, SecurityStatus::SUSPICIOUS);
    }

    /** Administrator decision (appeal resolution or manual review). */
    public function applyManualVerdict(Link $link, SecurityStatus $verdict, User $reviewer, ?string $note = null): void
    {
        $scan = LinkSecurityScan::query()->create([
            'link_id' => $link->id,
            'trigger' => ScanTrigger::MANUAL,
            'status' => ScanStatus::COMPLETED,
            'verdict' => $verdict,
            'provider_results' => [['provider' => 'manual_review', 'verdict' => $verdict->value, 'authoritative' => true, 'threats' => [], 'error_code' => null]],
            'requested_by_user_id' => $reviewer->id,
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $this->applyVerdict($link, $scan, $verdict, manual: true, actor: $reviewer);
        $this->audit->log(AuditAction::SECURITY_MANUAL_VERDICT, $reviewer, 'link', (string) $link->id, ['verdict' => $verdict->value, 'has_note' => $note !== null]);
    }

    private function applyVerdict(Link $link, LinkSecurityScan $scan, SecurityStatus $verdict, bool $manual = false, ?User $actor = null): void
    {
        $changed = DB::transaction(function () use ($link, $scan, $verdict, $manual): ?array {
            /** @var Link|null $locked */
            $locked = Link::query()->whereKey($link->id)->lockForUpdate()->first();
            if ($locked === null) {
                return null;
            }

            if (! $manual && $locked->last_security_scan_id !== null && $locked->last_security_scan_id > $scan->id) {
                return null; // a newer scan already decided
            }

            if (! $manual && $locked->security_source === SecuritySource::MANUAL_REVIEW) {
                $manualStillValid = $locked->security_status !== SecurityStatus::CLEAR
                    || ($locked->security_expires_at !== null && $locked->security_expires_at->isFuture());
                if ($manualStillValid) {
                    return null; // reviewed decision stands until it expires
                }
            }

            $before = $locked->effectiveSecurityStatus();
            $wasFetchable = $this->policy->mayFetch($locked);

            $locked->forceFill([
                'security_status' => $verdict,
                'security_source' => $manual ? SecuritySource::MANUAL_REVIEW : SecuritySource::PROVIDER,
                'security_checked_at' => now(),
                'security_expires_at' => $verdict === SecurityStatus::CLEAR ? now()->addDays((int) config('linklibrary.security.clear_ttl_days', 7)) : null,
                'last_security_scan_id' => $scan->id,
            ])->save();

            return ['before' => $before, 'after' => $locked->effectiveSecurityStatus(), 'was_fetchable' => $wasFetchable, 'link' => $locked];
        });

        if ($changed === null) {
            return;
        }

        /** @var Link $fresh */
        $fresh = $changed['link'];
        $before = $changed['before'];
        $after = $changed['after'];

        if ($before !== $after) {
            $this->discoveryCache->invalidate();
            $this->audit->log(AuditAction::SECURITY_STATUS_CHANGED, null, 'link', (string) $fresh->id, [
                'from' => $before->value,
                'to' => $after->value,
                'trigger' => $scan->trigger->value,
            ]);

            if ($after->isUnsafe()) {
                $this->onBecameUnsafe($fresh, $after);
            }
        }

        if (! $changed['was_fetchable'] && $this->policy->mayFetch($fresh) && $fresh->extraction_status === ExtractionStatus::PENDING) {
            ExtractLinkMetadataJob::dispatch($fresh->id, $fresh->extraction_version)->afterCommit();
        }
    }

    private function onBecameUnsafe(Link $link, SecurityStatus $status): void
    {
        $savedLinks = SavedLink::query()->where('link_id', $link->id)->with('user')->get();

        if ($status === SecurityStatus::MALICIOUS) {
            LinkInvitation::query()
                ->whereIn('saved_link_id', $savedLinks->pluck('id'))
                ->where('status', InvitationStatus::PENDING->value)
                ->update(['status' => InvitationStatus::REVOKED->value, 'revoked_at' => now(), 'updated_at' => now()]);
        }

        foreach ($savedLinks as $savedLink) {
            Notification::send($savedLink->user, new SecurityWarningNotification($savedLink->public_id, $status));
        }
    }
}
