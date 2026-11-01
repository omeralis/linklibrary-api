# Notes for AI coding agents

- Read `IMPLEMENTATION_PROGRESS.md` first: it is the source of truth for status, decisions and next steps.
- API-only Laravel 13 backend (PostgreSQL, Sanctum). Business logic lives in `app/Services/**`; controllers stay thin.
- Never make live network calls in tests; use the fakes in `tests/Support`.
- All outbound requests to user-supplied URLs MUST go through `App\Services\Http\SafeHttpClient`.
- Unauthorized access to private resources must return the same 404 as non-existent ones.
- Run before finishing: `vendor/bin/pint`, `vendor/bin/phpstan analyse --memory-limit=1G`, `php artisan test`,
  and `php scripts/build-openapi.php` after changing routes.
