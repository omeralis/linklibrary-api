<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locale for user-facing messages. Precedence: explicit ?locale=ar|en, then the signed-in user's
 * saved preference, then Accept-Language, then the application default.
 */
class SetLocale
{
    private const SUPPORTED = ['ar', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->resolve($request));

        $response = $next($request);
        $response->headers->set('Content-Language', app()->getLocale());
        $vary = (string) $response->headers->get('Vary', '');
        if (! str_contains($vary, 'Accept-Language')) {
            $response->headers->set('Vary', ltrim($vary.', Accept-Language', ', '));
        }

        return $response;
    }

    private function resolve(Request $request): string
    {
        $query = $request->query('locale');
        if (is_string($query) && in_array($query, self::SUPPORTED, true)) {
            return $query;
        }

        // The sanctum guard resolves both cookie sessions and bearer tokens.
        $user = auth('sanctum')->user();
        if ($user instanceof User && in_array($user->locale, self::SUPPORTED, true)) {
            return $user->locale;
        }

        $header = strtolower((string) $request->header('Accept-Language', ''));
        if ($header !== '') {
            $preferred = $request->getPreferredLanguage(self::SUPPORTED);
            if ($preferred !== null && str_contains($header, $preferred)) {
                return $preferred;
            }
        }

        return (string) config('app.locale', 'en');
    }
}
