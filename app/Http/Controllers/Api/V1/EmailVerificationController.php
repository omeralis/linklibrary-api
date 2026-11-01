<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditAction;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EmailVerificationController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Signed (relative) URL from the verification e-mail. The signature + e-mail hash are the proof,
     * so the frontend can call this whether or not the user is logged in.
     */
    public function verify(Request $request, string $id, string $hash): JsonResponse
    {
        $user = Str::isUuid($id) ? User::query()->where('public_id', $id)->first() : null;
        if ($user === null || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            throw new ApiException('INVALID_VERIFICATION_LINK', 403, 'api.auth.invalid_verification_link');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            $this->audit->log(AuditAction::EMAIL_VERIFIED, $user, 'user', $user->public_id);
        }

        return ApiResponse::message('api.auth.email_verified');
    }

    public function resend(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->hasVerifiedEmail()) {
            return ApiResponse::message('api.auth.already_verified');
        }
        $user->sendEmailVerificationNotification();

        return ApiResponse::message('api.auth.verification_sent', 202);
    }
}
