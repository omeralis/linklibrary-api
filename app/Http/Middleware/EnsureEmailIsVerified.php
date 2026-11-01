<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof MustVerifyEmail || ! $user->hasVerifiedEmail()) {
            throw new ApiException('EMAIL_NOT_VERIFIED', 403, 'api.auth.email_not_verified');
        }

        return $next($request);
    }
}
