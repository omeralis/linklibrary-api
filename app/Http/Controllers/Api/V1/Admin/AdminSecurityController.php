<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AppealStatus;
use App\Enums\SecurityStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AppealResource;
use App\Models\Link;
use App\Models\LinkSecurityAppeal;
use App\Services\Audit\AuditAction;
use App\Services\Audit\AuditLogger;
use App\Services\Security\SecurityScanService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Security review (administrators only — route group is gated with can:admin). */
class AdminSecurityController extends Controller
{
    public function __construct(private readonly SecurityScanService $security, private readonly AuditLogger $audit) {}

    public function appeals(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['sometimes', Rule::in(['PENDING', 'APPROVED', 'REJECTED'])]]);
        $page = LinkSecurityAppeal::query()->with(['link', 'user'])
            ->where('status', $data['status'] ?? AppealStatus::PENDING->value)
            ->oldest('id')
            ->paginate(ApiResponse::perPage());

        return ApiResponse::paginated($page, function (LinkSecurityAppeal $a) {
            $r = new AppealResource($a);
            $r->forAdmin = true;

            return $r;
        });
    }

    public function resolveAppeal(Request $request, string $appealId): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['APPROVE', 'REJECT'])],
            'verdict' => ['required_if:decision,APPROVE', Rule::in(['CLEAR', 'SUSPICIOUS'])],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        if (! LinkSecurityAppeal::isValidPublicId($appealId)) {
            throw ApiException::notFound();
        }
        $appeal = LinkSecurityAppeal::query()->with('link')->where('public_id', $appealId)->first() ?? throw ApiException::notFound();
        if ($appeal->status !== AppealStatus::PENDING) {
            throw ApiException::conflict('APPEAL_ALREADY_RESOLVED', 'api.security.appeal_resolved_already');
        }

        $approve = $data['decision'] === 'APPROVE';
        $verdict = $approve ? SecurityStatus::from($data['verdict']) : null;

        $appeal->update([
            'status' => $approve ? AppealStatus::APPROVED : AppealStatus::REJECTED,
            'reviewer_user_id' => $request->user()->id,
            'reviewer_notes' => $data['notes'] ?? null,
            'resolution_verdict' => $verdict,
            'resolved_at' => now(),
        ]);

        if ($verdict !== null) {
            $this->security->applyManualVerdict($appeal->link, $verdict, $request->user(), $data['notes'] ?? null);
        }
        $this->audit->log(AuditAction::APPEAL_RESOLVED, $request->user(), 'appeal', $appeal->public_id, ['decision' => $data['decision'], 'verdict' => $verdict?->value]);

        $r = new AppealResource($appeal->fresh(['link', 'user']));
        $r->forAdmin = true;

        return ApiResponse::success($r, 'api.security.appeal_resolved');
    }

    public function setVerdict(Request $request, int $linkId): JsonResponse
    {
        $data = $request->validate([
            'verdict' => ['required', Rule::in(['CLEAR', 'SUSPICIOUS', 'MALICIOUS'])],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        $link = Link::query()->find($linkId) ?? throw ApiException::notFound();
        $this->security->applyManualVerdict($link, SecurityStatus::from($data['verdict']), $request->user(), $data['notes'] ?? null);
        $link->refresh();

        return ApiResponse::success([
            'link_id' => $link->id,
            'security_status' => $link->security_status->value,
            'security_source' => $link->security_source->value,
            'expires_at' => $link->security_expires_at?->toIso8601String(),
        ], 'api.security.verdict_applied');
    }
}
