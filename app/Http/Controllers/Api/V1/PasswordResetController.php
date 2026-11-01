<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Models\User;
use App\Notifications\Auth\PasswordChangedNotification;
use App\Services\Account\AccountService;
use App\Services\Audit\AuditAction;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

class PasswordResetController extends Controller
{
    public function __construct(private readonly AccountService $accounts, private readonly AuditLogger $audit) {}

    /** Always the same response, whether or not the address exists (no account enumeration). */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        Password::broker()->sendResetLink(['email' => $request->string('email')->toString()]);

        return ApiResponse::message('api.auth.reset_link_sent_if_exists', 202);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'password_changed_at' => now()])->setRememberToken(null);
                $user->save();
                $this->accounts->revokeOtherSessions($user);
                $user->notify(new PasswordChangedNotification);
                $this->audit->log(AuditAction::PASSWORD_RESET, $user, 'user', $user->public_id);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ApiException::unprocessable('INVALID_RESET_TOKEN', 'api.auth.invalid_reset_token');
        }

        return ApiResponse::message('api.auth.password_reset');
    }
}
