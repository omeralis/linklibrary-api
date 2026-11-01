<?php

namespace App\Support;

use App\Exceptions\ApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Renders every API error as: { success: false, message, errors, code }.
 * Stack traces and internal messages are only included when APP_DEBUG is on.
 */
final class ApiExceptionRenderer
{
    public function render(Throwable $e, Request $request): JsonResponse
    {
        [$status, $code, $message, $errors, $headers] = $this->map($e);

        $payload = [
            'success' => false,
            'message' => $message,
            'errors' => (object) $errors,
            'code' => $code,
        ];

        if (config('app.debug') && $status >= 500) {
            $payload['debug'] = [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 15),
            ];
        }

        return new JsonResponse($payload, $status, $headers);
    }

    /** @return array{0:int,1:string,2:string,3:array<string,mixed>,4:array<string,string>} */
    private function map(Throwable $e): array
    {
        return match (true) {
            $e instanceof ApiException => [$e->status, $e->errorCode, __($e->messageKey, $e->replace), $e->errors, []],
            $e instanceof ValidationException => [422, 'VALIDATION_ERROR', __('api.errors.validation'), $e->errors(), []],
            $e instanceof AuthenticationException => [401, 'UNAUTHENTICATED', __('api.errors.unauthenticated'), [], []],
            $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => [403, 'FORBIDDEN', __('api.errors.forbidden'), [], []],
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => [404, 'NOT_FOUND', __('api.errors.not_found'), [], []],
            $e instanceof MethodNotAllowedHttpException => [405, 'METHOD_NOT_ALLOWED', __('api.errors.method_not_allowed'), [], []],
            $e instanceof ThrottleRequestsException, $e instanceof TooManyRequestsHttpException => [429, 'RATE_LIMITED', __('api.errors.rate_limited'), [], $this->headers($e)],
            $e instanceof InvalidSignatureException => [403, 'INVALID_SIGNATURE', __('api.auth.invalid_verification_link'), [], []],
            $e instanceof TokenMismatchException => [419, 'CSRF_TOKEN_MISMATCH', __('api.errors.csrf'), [], []],
            $e instanceof UniqueConstraintViolationException => [409, 'CONFLICT', __('api.errors.conflict'), [], []],
            $e instanceof QueryException => [500, 'SERVER_ERROR', __('api.errors.server'), [], []],
            $e instanceof HttpExceptionInterface => match ($e->getStatusCode()) {
                403 => [403, 'FORBIDDEN', __('api.errors.forbidden'), [], []],
                404 => [404, 'NOT_FOUND', __('api.errors.not_found'), [], []],
                419 => [419, 'CSRF_TOKEN_MISMATCH', __('api.errors.csrf'), [], []],
                429 => [429, 'RATE_LIMITED', __('api.errors.rate_limited'), [], $this->headers($e)],
                503 => [503, 'SERVICE_UNAVAILABLE', __('api.errors.unavailable'), [], $this->headers($e)],
                default => [$e->getStatusCode(), 'HTTP_ERROR', __('api.errors.http'), [], $this->headers($e)],
            },
            default => [500, 'SERVER_ERROR', __('api.errors.server'), [], []],
        };
    }

    /** @return array<string, string> */
    private function headers(Throwable $e): array
    {
        if ($e instanceof HttpExceptionInterface) {
            return array_map(fn ($v) => is_array($v) ? implode(', ', $v) : (string) $v, $e->getHeaders());
        }

        return [];
    }
}
