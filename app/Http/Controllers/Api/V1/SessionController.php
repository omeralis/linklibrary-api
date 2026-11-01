<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Account\AccountService;
use App\Services\Audit\AuditAction;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Session management. Session identifiers are never exposed: clients see a SHA-256 fingerprint.
 * Listing browser sessions requires SESSION_DRIVER=database.
 */
class SessionController extends Controller
{
    public function __construct(private readonly AccountService $accounts, private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        if (config('session.driver') !== 'database') {
            return ApiResponse::success([], meta: ['supported' => false]);
        }
        $current = $request->hasSession() ? $request->session()->getId() : null;

        $sessions = DB::table((string) config('session.table', 'sessions'))
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_activity')
            ->limit(50)
            ->get()
            ->map(fn ($s) => [
                'id' => hash('sha256', (string) $s->id),
                'ip_address' => $s->ip_address,
                'user_agent' => mb_substr((string) $s->user_agent, 0, 255),
                'last_active_at' => date(DATE_ATOM, (int) $s->last_activity),
                'current' => $current !== null && hash_equals((string) $s->id, $current),
            ]);

        return ApiResponse::success($sessions, meta: ['supported' => true]);
    }

    public function destroy(Request $request, string $sessionId): JsonResponse
    {
        $deleted = 0;
        if (config('session.driver') === 'database' && preg_match('/^[0-9a-f]{64}$/', $sessionId) === 1) {
            $ids = DB::table((string) config('session.table', 'sessions'))->where('user_id', $request->user()->id)->pluck('id');
            foreach ($ids as $id) {
                if (hash_equals(hash('sha256', (string) $id), $sessionId)) {
                    $deleted = DB::table((string) config('session.table', 'sessions'))->where('id', $id)->delete();
                }
            }
        }
        if ($deleted === 0) {
            throw ApiException::notFound();
        }
        $this->audit->log(AuditAction::SESSION_REVOKED, $request->user(), 'user', $request->user()->public_id);

        return ApiResponse::message('api.sessions.revoked');
    }

    public function revokeOthers(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->accounts->assertPassword($user, $request->input('current_password'));
        $this->accounts->revokeOtherSessions(
            $user,
            $request->hasSession() ? $request->session()->getId() : null,
            $user->currentPersonalAccessTokenId(),
        );
        $this->audit->log(AuditAction::SESSION_REVOKED, $user, 'user', $user->public_id, ['scope' => 'others']);

        return ApiResponse::message('api.sessions.revoked');
    }

    public function tokens(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $currentId = $user->currentPersonalAccessTokenId();

        $tokens = $user->tokens()->orderByDesc('id')->limit(100)->get()->map(fn (PersonalAccessToken $t) => [
            'id' => $t->id,
            'name' => $t->name,
            'last_used_at' => $t->last_used_at?->toIso8601String(),
            'expires_at' => $t->expires_at?->toIso8601String(),
            'created_at' => $t->created_at?->toIso8601String(),
            'current' => $t->id === $currentId,
        ]);

        return ApiResponse::success($tokens);
    }

    public function destroyToken(Request $request, int $tokenId): JsonResponse
    {
        $deleted = $request->user()->tokens()->whereKey($tokenId)->delete();
        if ($deleted === 0) {
            throw ApiException::notFound();
        }
        $this->audit->log(AuditAction::TOKEN_REVOKED, $request->user(), 'user', $request->user()->public_id);

        return ApiResponse::message('api.sessions.revoked');
    }
}
