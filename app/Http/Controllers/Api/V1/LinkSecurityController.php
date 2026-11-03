<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AppealStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AppealResource;
use App\Http\Resources\SecurityScanResource;
use App\Models\LinkSecurityAppeal;
use App\Models\LinkSecurityScan;
use App\Services\Audit\AuditAction;
use App\Services\Audit\AuditLogger;
use App\Services\Links\LinkService;
use App\Services\Security\SecurityPolicy;
use App\Services\Sharing\LinkAccessResolver;
use App\Support\ApiResponse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Owner-only security details, rescans and appeals. */
class LinkSecurityController extends Controller
{
    public function __construct(
        private readonly LinkAccessResolver $resolver,
        private readonly LinkService $links,
        private readonly SecurityPolicy $policy,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request, string $publicId): JsonResponse
    {
        $savedLink = $this->resolver->resolveOwned($publicId, $request->user());
        $link = $savedLink->link;

        $scans = LinkSecurityScan::query()->where('link_id', $link->id)->latest('id')->limit(20)->get();
        $appeals = LinkSecurityAppeal::query()->where('link_id', $link->id)->where('user_id', $request->user()->id)->latest('id')->limit(10)->get();

        return ApiResponse::success([
            'status' => $link->effectiveSecurityStatus()->value,
            'stored_status' => $link->security_status->value,
            'source' => $link->security_source->value,
            'checked_at' => $link->security_checked_at?->toIso8601String(),
            'expires_at' => $link->security_expires_at?->toIso8601String(),
            'can_publish' => $this->policy->mayPublish($link),
            'can_share' => $this->policy->mayShare($link),
            'can_open' => $this->policy->mayOpen($link, true),
            'scanning' => $this->policy->scanningComingSoon() ? 'COMING_SOON' : 'AVAILABLE',
            'unverified_sharing' => $this->policy->isUnverifiedButAllowed($link),
            'disclaimer' => __('api.security.disclaimer'),
            'history' => SecurityScanResource::collection($scans)->resolve($request),
            'appeals' => AppealResource::collection($appeals)->resolve($request),
        ]);
    }

    public function rescan(Request $request, string $publicId): JsonResponse
    {
        if ($this->policy->scanningComingSoon()) {
            throw ApiException::unprocessable('SECURITY_SCANNING_COMING_SOON', 'api.security.coming_soon');
        }
        $savedLink = $this->resolver->resolveOwned($publicId, $request->user());
        $scan = $this->links->requestRescan($savedLink, $request->user());

        return ApiResponse::success((new SecurityScanResource($scan->fresh() ?? $scan)), 'api.security.rescan_queued', 202);
    }

    public function appeal(Request $request, string $publicId): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']]);
        $savedLink = $this->resolver->resolveOwned($publicId, $request->user());
        $link = $savedLink->link;

        if (! $link->security_status->isUnsafe()) {
            throw ApiException::unprocessable('APPEAL_NOT_APPLICABLE', 'api.security.appeal_not_applicable');
        }

        try {
            // Savepoint: a unique violation must not poison an enclosing transaction.
            $appeal = DB::transaction(fn () => LinkSecurityAppeal::query()->create([
                'link_id' => $link->id,
                'saved_link_id' => $savedLink->id,
                'user_id' => $request->user()->id,
                'security_status_at_submission' => $link->security_status,
                'reason' => $data['reason'],
                'status' => AppealStatus::PENDING,
            ]));
        } catch (UniqueConstraintViolationException) {
            throw ApiException::conflict('APPEAL_ALREADY_PENDING', 'api.security.appeal_pending');
        }

        $this->audit->log(AuditAction::APPEAL_SUBMITTED, $request->user(), 'saved_link', $savedLink->public_id, ['appeal_id' => $appeal->public_id]);

        return ApiResponse::created(new AppealResource($appeal), 'api.security.appeal_submitted');
    }
}
