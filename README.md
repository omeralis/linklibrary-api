# LinkLibrary — Backend API

> Temporary product name. Branding, URLs and e-mail sender names are configurable (`BRAND_*`, `FRONTEND_*`, `MAIL_FROM_*`).

Laravel 13 REST API for an AI-assisted link library and sharing platform: save links, automatic metadata
extraction, optional AI summaries/categories/tags, collections, private sharing with recursive resharing,
public discovery, PostgreSQL full-text search (Arabic + English), notifications and audit logging.

The UI is a separate Next.js application. Everything is served under **`/api/v1`**.

## Tech stack

| Layer | Technology |
|---|---|
| Language / framework | PHP 8.3+ (tested on 8.3 and 8.5), Laravel 13 |
| Auth | Laravel Sanctum 4 — SPA cookie sessions for the browser, bearer tokens (`ll_` prefix) for server/mobile clients |
| Database | PostgreSQL 14+ (tested on 16 and 18) with built-in `arabic` / `english` / `simple` full-text search |
| Queues / cache | Database driver by default; Redis recommended in production |
| Tests / quality | PHPUnit 12, Laravel Pint, PHPStan 2 + Larastan 3 (level 5), OpenAPI contract tests |
| API contract | OpenAPI 3.1 (`docs/openapi.yaml`) |

## Documentation

| Document | Content |
|---|---|
| [`docs/openapi.yaml`](docs/openapi.yaml) | **API reference** — OpenAPI 3.1, also served by the app at `GET /docs/openapi.yaml` |
| [`docs/FRONTEND_INTEGRATION_HANDOFF.md`](docs/FRONTEND_INTEGRATION_HANDOFF.md) | Frontend handoff: auth flows, envelopes, error codes, pagination, statuses |
| [`docs/FRONTEND_INTEGRATION.md`](docs/FRONTEND_INTEGRATION.md) | Domains, CORS, CSRF, cookies, SameSite for Next.js |
| [`docs/API_EXAMPLES.md`](docs/API_EXAMPLES.md) | curl examples |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | Layers, processing pipeline, access-graph algorithm, caching, jobs |
| [`docs/SECURITY.md`](docs/SECURITY.md) | SSRF guarantees, URL policy, security statuses, threat intel |
| [`docs/ERD.md`](docs/ERD.md) | Entity-relationship diagram and database decisions |
| [`docs/AI_AND_PRIVACY.md`](docs/AI_AND_PRIVACY.md) | What is (and is never) sent to AI providers |
| [`IMPLEMENTATION_PROGRESS.md`](IMPLEMENTATION_PROGRESS.md) | Implementation status, decisions, test results |

## Requirements

- PHP **8.3+** with `pdo_pgsql`, `intl`, `mbstring`, `curl`, `gd` (WebP), `fileinfo`; `pcntl` for one concurrency test (skipped on Windows)
- Composer 2
- PostgreSQL **14+**
- Redis (optional)

## Local installation

```bash
git clone https://github.com/omeralis/linklibrary-api.git
cd linklibrary-api
composer install                     # versions are pinned by composer.lock
cp .env.example .env                 # Windows: copy .env.example .env
php artisan key:generate
```

### PostgreSQL setup

Create a role and two databases — one for development and a **separate** one for tests
(the test suite refuses to run against a database whose name does not end in `_test`):

```sql
CREATE ROLE linklibrary LOGIN PASSWORD 'choose-a-local-password';
CREATE DATABASE linklibrary      OWNER linklibrary ENCODING 'UTF8';
CREATE DATABASE linklibrary_test OWNER linklibrary ENCODING 'UTF8';
```

Any existing role (for example `postgres`) works too — just set it in `.env`.

### Environment configuration

Edit `.env` (never commit it; it is git-ignored):

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=linklibrary
DB_USERNAME=linklibrary
DB_PASSWORD=your-local-password
FRONTEND_URL=http://localhost:3000
```

For tests create `.env.testing` (also git-ignored) with the same credentials and `DB_DATABASE=linklibrary_test`.
`phpunit.xml` forces `APP_ENV=testing`, so tests never touch the development database.

All other settings are documented inline in `.env.example` and in `config/linklibrary.php`:

| Area | Keys |
|---|---|
| Database | `DB_*` |
| Redis / cache / queue | `REDIS_*`, `CACHE_STORE`, `QUEUE_CONNECTION` |
| Mail | `MAIL_*` |
| Frontend | `FRONTEND_URL`, `FRONTEND_PATH_*`, `FRONTEND_SERVER_KEY`, `SANCTUM_STATEFUL_DOMAINS`, `CORS_ALLOWED_ORIGINS`, `SESSION_*` |
| Safe fetching | `LINK_FETCH_*` |
| Threat intelligence | `SECURITY_PROVIDERS`, `GOOGLE_SAFE_BROWSING_API_KEY`, `GOOGLE_WEB_RISK_API_KEY` |
| AI (optional) | `AI_PROVIDER=anthropic\|openai\|null`, `ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `OPENAI_ENDPOINT` |
| Extraction (optional) | `GITHUB_API_TOKEN`, `YOUTUBE_API_KEY` |

**Without a threat-intelligence provider every URL stays `UNKNOWN`** — links can be saved and used privately but
cannot be published or shared. For local development only, `php artisan linklibrary:dev-mark-clear` marks
URLs as `CLEAR` (runs only with `APP_ENV=local`). Without AI credentials the app works fully; AI fields are absent.

### Migrations and seeders

```bash
php artisan linklibrary:db-check          # verify connection, server version, FTS configurations
php artisan migrate --seed                # bilingual categories (+ demo users when APP_ENV=local)
php artisan linklibrary:db-check --tables # verify all expected tables exist
php artisan storage:link                  # public URLs for uploaded avatars/covers
php artisan linklibrary:doctor            # full environment health check
```

Local demo accounts (seeded only when `APP_ENV=local`): `admin@linklibrary.test` / `demo@linklibrary.test`,
password `Password12345`. Never use them in a shared environment.
Grant the admin role to an account: `php artisan linklibrary:make-admin someone@example.com`.

**Windows shortcut:** `scripts\local-setup.cmd` runs the checks, migrations, seeders, doctor and test suite
in one go and writes a log to `storage/logs/local-setup.log`.

## Running the application

```bash
php artisan serve                     # http://localhost:8000
```

### Queue workers and scheduler

Queues: `security` (reputation scans), `extraction` (metadata), `ai` (enrichment), `default` (mail/notifications).

```bash
php artisan queue:work --queue=security,extraction,ai,default --tries=3 --timeout=120
php artisan schedule:work             # development; production cron: * * * * * php artisan schedule:run
```

Scheduled tasks (`routes/console.php`): security rescans, stale metadata refresh, expired-invitation cleanup,
pruning of processing records, popularity recalculation, Sanctum token pruning and a scheduler heartbeat
(checked by `linklibrary:doctor`).

### Docker Compose (alternative)

```bash
cp .env.example .env
docker compose up -d --build          # app, worker, scheduler, postgres, redis, mailpit
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

## Running tests

```bash
php artisan test                      # or vendor/bin/phpunit — uses linklibrary_test only
vendor/bin/pint --test                # formatting
vendor/bin/phpstan analyse --memory-limit=1G
php scripts/build-openapi.php         # regenerate docs/openapi.yaml after route changes
```

The suite never makes live network calls: HTTP transport, DNS, threat-intel and AI providers are replaced by
deterministic fakes (`tests/Support`). `FrontendContractTest` validates real responses against `docs/openapi.yaml`.

## Useful commands

```bash
php artisan route:list --path=api     # all endpoints
php artisan queue:failed              # inspect failed jobs
```
