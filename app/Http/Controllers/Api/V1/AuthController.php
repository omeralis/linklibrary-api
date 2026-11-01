<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Account\AccountService;
use App\Services\Audit\AuditAction;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Two authentication modes:
 *  - SPA (Next.js on a Sanctum stateful domain): call GET /sanctum/csrf-cookie, then POST /login with
 *    the X-XSRF-TOKEN header; a session cookie (HttpOnly, SameSite) is issued.
 *  - Token (non-browser clients): POST /login with "device_name" from a non-stateful origin; a
 *    Sanctum bearer token is returned once.
 */
class AuthController extends Controller
{
    private static ?string $dummyHash = null;

    public function __construct(private readonly AccountService $accounts, private readonly AuditLogger $audit) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->accounts->register($request->validated());

        return ApiResponse::created($this->establish($request, $user, false), 'api.auth.registered');
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->string('email')->toString())->first();

        // Constant-ish work whether or not the account exists (no user enumeration via timing).
        $password = $request->string('password')->toString();
        if ($user === null) {
            Hash::check($password, self::$dummyHash ??= Hash::make(bin2hex(random_bytes(16))));
        }
        $valid = $user !== null && Hash::check($password, $user->password);

        if (! $valid) {
            $this->audit->log(AuditAction::USER_LOGIN_FAILED, $user, 'user', $user?->public_id);
            throw ValidationException::withMessages(['email' => [__('api.auth.failed')]]);
        }

        $this->accounts->ensureActive($user);
        $this->audit->log(AuditAction::USER_LOGIN, $user, 'user', $user->public_id);

        return ApiResponse::success($this->establish($request, $user, $request->boolean('remember')), 'api.auth.logged_in');
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $tokenId = $user->currentPersonalAccessTokenId();

        if ($tokenId !== null) {
            $user->tokens()->whereKey($tokenId)->delete();
        } else {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }

        $this->audit->log(AuditAction::USER_LOGOUT, $user, 'user', $user->public_id);

        return ApiResponse::message('api.auth.logged_out');
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(new UserResource($request->user()));
    }

    /** @return array<string, mixed> */
    private function establish(Request $request, User $user, bool $remember): array
    {
        if ($request->hasSession()) {
            Auth::guard('web')->login($user, $remember);
            $request->session()->regenerate();

            return ['user' => (new UserResource($user))->resolve($request), 'auth_mode' => 'session'];
        }

        $deviceName = (string) ($request->input('device_name') ?: 'api');
        $minutes = config('sanctum.expiration');
        $token = $user->createToken(mb_substr($deviceName, 0, 100), ['*'], $minutes ? now()->addMinutes((int) $minutes) : null);

        return [
            'user' => (new UserResource($user))->resolve($request),
            'auth_mode' => 'token',
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
        ];
    }
}
