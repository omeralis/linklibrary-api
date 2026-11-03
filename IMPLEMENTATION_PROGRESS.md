# IMPLEMENTATION_PROGRESS.md — LinkLibrary Backend

> Persistent source of truth for implementation progress.
> At the start of every session: read this file, then inspect the repository before continuing.
> Never delete recorded progress; append or annotate instead. Keep in sync with the actual repo state.

**Status legend:** `NOT_STARTED` · `IN_PROGRESS` · `COMPLETED` (implementation + tests verified) · `BLOCKED`

**Last updated:** 2026-11-02 (Asia/Riyadh)

---

## 1. Session Rules

1. Read this file and inspect the repository at session start.
2. Update this file immediately after each module or significant milestone.
3. A module is `COMPLETED` only after its implementation **and** relevant tests are verified.
4. Record files, migrations, endpoints, and test results per module.
5. Record errors, open issues, and technical decisions below.
6. Before ending a session, fill in **Next Task** and **Resume Commands**.
7. Never overwrite previous entries without preserving relevant information.

---

## 2. Environment

| Item | Value |
|------|-------|
| Framework | Laravel 13.x (resolved v13.39.0), PHP ^8.3 |
| Auth | Laravel Sanctum 4.3 (SPA cookies + bearer tokens) |
| DB | PostgreSQL 16 (dev `linklibrary`, tests `linklibrary_test`) |
| Cache/Queue | database drivers by default; Redis supported (`docker-compose.yml` uses Redis) |
| Tests | PHPUnit 12 — sandbox 230 passed; Windows/PHP 8.5: 222 passed + 1 skipped (pre-audit) |
| Static analysis | PHPStan 2 + Larastan 3, level 5 — **no errors** |
| Formatting | Laravel Pint — **passing** |
| Build sandbox | Built and verified in a Linux cloud sandbox, then copied to `E:\Dev\LinkLibrary` without `vendor/` and without `composer.lock` (see §8). |

---

## 3. Backend Module Checklist

| # | Module | Status | Verification |
|---|--------|--------|--------------|
| 0 | Project spec received | COMPLETED | Main backend prompt received 2026-11-01. |
| 1 | Repository/environment setup | COMPLETED | Laravel 13 skeleton (API-only), Postgres + Redis, Pint, PHPStan/Larastan, Docker Compose. |
| 2 | Database schema & migrations | COMPLETED | 10 migrations; `migrate:fresh --seed` and full `migrate:rollback` + re-migrate verified. FKs, CHECKs, partial unique indexes, composite FKs, GIN FTS indexes. |
| 3 | Authentication & account management | COMPLETED | `tests/Feature/Auth/*` (19 tests) + manual SPA cookie/CSRF flow against `php artisan serve`. |
| 4 | URL validation & normalization | COMPLETED | `tests/Unit/Url/UrlNormalizerTest` (41 cases). |
| 5 | SSRF-safe fetching | COMPLETED | `SafeHttpClientTest`, `IpAddressPolicyTest`, `CurlTransportTest` (real cURL vs local fixture server). |
| 6 | Link management | COMPLETED | `tests/Feature/Links/SavedLinkTest` (14 tests). |
| 7 | Metadata extraction pipeline | COMPLETED | `PipelineTest` (HTML/OG/Twitter/JSON-LD, YouTube oEmbed, GitHub API, PDF, robots, retries, stale jobs) + parser unit tests. |
| 8 | Manual metadata & precedence | COMPLETED | `SavedLinkTest::test_update_manual_metadata_and_precedence`, `PipelineTest::test_ai_enrichment_respects_manual_overrides_and_private_data`. |
| 9 | AI integration | COMPLETED | Anthropic + OpenAI-compatible adapters, Null provider; validator/prompt tests; pipeline tests incl. failure, retry, prompt injection, suspicion escalation. Live provider calls NOT exercised (no credentials; mocked by design). |
| 10 | Security scanning / threat intel / appeals | COMPLETED | Safe Browsing v4 + Web Risk + heuristic providers; aggregation tests; scan/appeal/manual-review/rescan feature tests. Live Google APIs NOT exercised (no keys). |
| 11 | Categories & tags | COMPLETED | Bilingual seeded categories (21), admin CRUD, tag policy (shared vocabulary, per-link private associations), tests. |
| 12 | Collections | COMPLETED | `CollectionTest` (5 tests) incl. DB-level cross-user protection. |
| 13 | Private invitations | COMPLETED | `InvitationTest` (11 tests). |
| 14 | Recursive grant-based sharing | COMPLETED | `AccessGraphTest` (12 tests) + `ConcurrentRevocationTest` (multi-process, 4 rounds). |
| 15 | Public links / internal URLs | COMPLETED | `/l/{publicId}` route data in resources; 404 semantics tested. |
| 16 | Public discovery & profiles | COMPLETED | `PublicDiscoveryTest` (11 tests). |
| 17 | Search (PostgreSQL FTS) | COMPLETED | `SearchTest` (5 tests) incl. Arabic folding, prefix, stemming, privacy. |
| 18 | Notifications | COMPLETED | DB + mail notifications; endpoints tested in `NotificationsAndAdminTest`. |
| 19 | Audit logging | COMPLETED | `AuditLogger` used across auth/links/sharing/security; assertions in several tests; admin endpoint. |
| 20 | Background jobs & scheduler | COMPLETED | `ScheduledJobsTest` (5 tests) + real `queue:work` run against the dev database. |
| 21 | API response conventions & errors | COMPLETED | `ApiConventionsTest` (envelopes, locale, no stack traces, headers, CORS, OpenAPI parity). |
| 22 | Performance & caching | COMPLETED | Indexes, eager loading (`withPresentation`, lazy loading disabled outside production), versioned public cache, pagination caps. |
| 23 | Security hardening | COMPLETED | See `docs/SECURITY.md`; security-focused tests across suites. |
| 24 | Automated tests | COMPLETED | 228 tests passing (`php artisan test`). |
| 25 | OpenAPI 3.1 docs | COMPLETED | `docs/openapi.yaml` (66 paths, generated by `scripts/build-openapi.php`); test asserts every `/api/v1` route is documented. |
| 26 | Developer experience | COMPLETED | `.env.example`, README, Dockerfile, `docker-compose.yml` (app, worker, scheduler, postgres, redis, mailpit), docs. Docker images NOT built in sandbox (no Docker Hub access) — see §9. |
| 27 | Final verification & report | COMPLETED | §7 and final report in chat. |

---

## 4. Module Details

### Files (high level)
- **Config:** `config/linklibrary.php` (branding, frontend URLs, fetch limits, security, AI, sharing, retention), `config/cors.php`, `config/sanctum.php`.
- **Enums:** `app/Enums/*` (Visibility, SecurityStatus, ExtractionStatus, AiStatus, ProcessingStatus, SourceType, InvitationStatus, GrantStatus, ScanTrigger, ScanStatus, AppealStatus, TagSource, SecuritySource, AccountStatus, UserRole, Locale, Theme).
- **Models:** User, Link, SavedLink, Category, Tag, Collection, CollectionItem, LinkAccessGrant, LinkInvitation, LinkSecurityScan, LinkSecurityAppeal, LinkExtractionJob, LinkAiAnalysis, AuditLog (+ `Concerns/HasPublicId`).
- **Services:** `Url`, `Http` (SafeHttpClient, CurlTransport, IpAddressPolicy, DNS), `Security` (policy, scan service, reputation providers), `Extraction` (service, parsers, robots, extractors: YouTube, Vimeo, GitHub, Generic HTML/PDF), `Ai` (service, adapters, prompt, validator), `Links` (LinkService, TagService, CollectionService), `Sharing` (AccessGraphService, InvitationService, LinkAccessResolver), `Search`, `Discovery`, `Account`, `Audit`, `Media`.
- **HTTP:** controllers `app/Http/Controllers/Api/V1/*` (+ `Admin/*`), form requests, resources, middleware (SetLocale, SecurityHeaders, EnsureAccountIsActive, EnsureEmailIsVerified), `app/Support/ApiResponse`, `ApiExceptionRenderer`, `FrontendUrl`, `ArabicNormalizer`.
- **Jobs:** ScanLinkSecurityJob, ExtractLinkMetadataJob, AnalyzeLinkWithAiJob, RescanDueLinksJob, RefreshStaleMetadataJob, CleanupExpiredInvitationsJob, PruneProcessingRecordsJob, RecalculatePopularityJob. Schedule in `routes/console.php`.
- **Notifications:** VerifyEmail, ResetPassword, PasswordChanged, EmailChanged, LinkInvitation, InvitationAccepted, AccessRevoked, SecurityWarning, LinkProcessingFailed.
- **Console:** `linklibrary:make-admin {email} [--revoke]`.
- **Lang:** `lang/{en,ar}/api.php`, `lang/{en,ar}/mail.php`, `lang/ar/validation.php`.
- **Docs:** `README.md`, `docs/ARCHITECTURE.md`, `docs/SECURITY.md`, `docs/ERD.md`, `docs/FRONTEND_INTEGRATION.md`, `docs/API_EXAMPLES.md`, `docs/AI_AND_PRIVACY.md`, `docs/openapi.yaml`.
- **Tests:** `tests/Unit/**` (113), `tests/Feature/**` (115), fakes in `tests/Support/*`.

---

## 5. Migrations Log

| # | Migration | Module | Applied? | Date |
|---|-----------|--------|----------|------|
| 1 | 0001_01_01_000000_create_users_table (users, password_reset_tokens, sessions) | Auth | Yes | 2026-11-01 |
| 2 | 0001_01_01_000001_create_cache_table | Infra | Yes | 2026-11-01 |
| 3 | 0001_01_01_000002_create_jobs_table | Infra | Yes | 2026-11-01 |
| 4 | 2026_11_01_000001_create_personal_access_tokens_table | Auth | Yes | 2026-11-01 |
| 5 | 2026_11_01_000010_create_taxonomy_tables (categories, tags) | Taxonomy | Yes | 2026-11-01 |
| 6 | 2026_11_01_000020_create_links_tables (links, saved_links, saved_link_tags, link_views, FTS) | Links | Yes | 2026-11-01 |
| 7 | 2026_11_01_000030_create_collections_tables | Collections | Yes | 2026-11-01 |
| 8 | 2026_11_01_000040_create_sharing_tables (link_access_grants, link_invitations) | Sharing | Yes | 2026-11-01 |
| 9 | 2026_11_01_000050_create_processing_tables (scans, appeals, extraction jobs, AI analyses) | Processing | Yes | 2026-11-01 |
| 10 | 2026_11_01_000060_create_notifications_and_audit_tables | Notifications/Audit | Yes | 2026-11-01 |

---

## 6. API Endpoints Registry

80 routes under `/api/v1` (`php artisan route:list --path=api`), all documented in `docs/openapi.yaml`.
Unauthenticated routes are limited to: register, login, forgot/reset password, signed e-mail verification,
and `/public/*`. All `/admin/*` routes are gated by `can:admin`. Verified 2026-11-01.

---

## 7. Test Results Log

| Date | Command | Scope | Result |
|------|---------|-------|--------|
| 2026-11-01 | `php artisan test --testsuite=Unit` | Unit | 113 passed |
| 2026-11-01 | `vendor/bin/phpstan analyse` (level 5 + Larastan) | app, database, routes | No errors |
| 2026-11-01 | `vendor/bin/phpunit` | Full suite | **228 passed, 1126 assertions** (Unit 113, Feature 115) |
| 2026-11-01 | `vendor/bin/pint --test` | Formatting | Passed |
| 2026-11-01 | `php artisan migrate:fresh --seed`, `migrate:rollback --step=20`, `migrate --seed` | Schema | OK |
| 2026-11-01 | Manual: `php artisan serve` + curl (token register, save link, SPA csrf-cookie + session login, CSRF rejection) | HTTP stack | OK |
| 2026-11-01 | Manual: `php artisan queue:work --stop-when-empty` on dev DB | Worker | Scan → extraction → notifications processed |
| 2026-11-01 | Sandbox: `php artisan test` after adding test-DB safeguards | Full suite | 228 passed |
| 2026-11-01 | Sandbox: safeguard check (phpunit forced to `linklibrary`) | Guard | Refused before migrating; dev DB untouched |
| 2026-11-01 | Windows 11 + PHP 8.5.12 + PostgreSQL 18.6: `scripts\local-setup.ps1` (exit 0) | Local setup | composer.lock created (laravel/framework v13.39.0, sanctum v4.3.3, phpunit 12.5.42); 10/10 migrations ran; CategorySeeder (21) + DemoSeeder (2 users) ran; `linklibrary:db-check --tables` 26/26 tables |
| 2026-11-01 | Windows: `php artisan test` (test DB `linklibrary_test`) | Full suite | **222 passed, 1 skipped (ConcurrentRevocationTest: no pcntl on Windows), 5 deprecated, 1091 assertions** |
| 2026-11-01 | Audit (sandbox, PHP 8.3) after readiness fixes | Full suite | **230 passed, 1219 assertions** incl. new `FrontendContractTest` (2 tests); PHPStan clean; Pint clean |
| 2026-11-01 | Windows re-run after audit fixes | Full suite | PENDING — expected 229 passed + 1 skipped, 0 deprecated |
| 2026-11-02 | Real-backend integration audit (cloud sandbox: PHP 8.5.12, PostgreSQL 16, isolated DB `linklibrary_e2e`; tests on `linklibrary_test`) | Full suite | **231 passed, 1245 assertions** (new: independent-path revocation test; contract walk now covers `DELETE /links/{id}/access/{grantId}`); PHPStan clean; Pint clean |
| 2026-11-02 | Frontend `e2e/real` Playwright suite against this API (see frontend `docs/REAL_BACKEND_INTEGRATION_REPORT.md`) | HTTP + UI | **42 passed, 0 failed** (1 opt-in screenshot test skipped) |
| 2026-11-02 | `php artisan linklibrary:doctor --network` (cloud sandbox) | Doctor | All checks OK except outbound HTTPS probe (sandbox DNS policy; real extraction of pypi.org verified separately) and the expected warnings (mail=log, heuristic-only security). Windows run: NOT VERIFIED in this session |

---

## 8. Technical Decisions

| Date | Decision | Rationale |
|------|----------|-----------|
| 2026-11-01 | Laravel 13 (latest stable), PHP ^8.3; API-only (no Vite/npm, tinker, pail, collision). | Current stable; fewer dependencies. |
| 2026-11-01 | Sandbox-only: packagist.org unreachable → generated a Composer `package` repo from GitHub tags. The delivered `composer.json` has no custom repositories and **no `composer.lock` is shipped**; run `composer update` once on a normal machine to create the lock from packagist. | Build sandbox network policy. |
| 2026-11-01 | Canonical `links` + per-user `saved_links`; one saved instance per (user, link); re-saving restores the soft-deleted row as PRIVATE. | Isolation + dedup + concurrency safety. |
| 2026-11-01 | Fragment policy: dropped from normalized URL unless `#!`/`#/` routes; query strings preserved verbatim. | Spec: don't strip params that can change the resource. |
| 2026-11-01 | Security status lives on the canonical link; CLEAR expires after 7 days → treated as UNKNOWN. | One scan per URL; stale verdicts must not keep links public. |
| 2026-11-01 | UNKNOWN URLs may be fetched for metadata by default (`SECURITY_FETCH_WHEN_UNKNOWN=true`), never published/shared. | App stays useful without paid threat intel; SSRF client protects the server. |
| 2026-11-01 | Manual review verdicts override provider results until they expire. | Appeals must be meaningful. |
| 2026-11-01 | Strict grant lineage: derived grants depend only on their recorded parent. | Prevents access silently re-attaching to unintended paths; documented in ARCHITECTURE.md. |
| 2026-11-01 | Graph mutations serialized with `SELECT … FOR UPDATE` on the saved link. | Simple, provably consistent concurrency. |
| 2026-11-01 | Processing status derived at read time from security/extraction/AI states. | No redundant state to drift. |
| 2026-11-01 | AI analysis per canonical link, from public extracted content only; AI category/tags applied per saved link with source `AI`. | Privacy + cost. |
| 2026-11-01 | Tags: shared normalized vocabulary, private per-link associations; public tag list only from eligible public links. | Spec requires a documented policy. |
| 2026-11-01 | Categories are system-managed (admin role); deactivation instead of deletion. | Spec: prevent unauthorized modification. |
| 2026-11-01 | Invitation e-mails omit the link title/URL; in-app acceptance by invitation id is also supported. | Privacy; DB notifications must not contain tokens. |
| 2026-11-01 | Locale precedence: `?locale` > user preference > Accept-Language > default. | Browsers always send Accept-Language. |
| 2026-11-01 | Robots.txt network/5xx failures → retryable `ROBOTS_UNAVAILABLE` (not cached). | RFC 9309; avoids permanently blocking on transient errors. |
| 2026-11-01 | Uploaded images re-encoded to WebP with GD; SVG rejected. | Strip metadata/polyglots/scripts. |

---

### 2026-11-01 — Local PostgreSQL configuration (Windows 11, PostgreSQL 18)
- `.env` created from `.env.example` (dev DB `linklibrary` @ 127.0.0.1:5432) with a newly generated `APP_KEY` (was missing).
- `.env.testing` created (DB `linklibrary_test`); both files ignored by Git (`.gitignore` adds `.env.testing`, `.env.*.local`).
- `phpunit.xml`: `APP_ENV`, `DB_CONNECTION`, `DB_DATABASE` now `force="true"` → tests always target `linklibrary_test`.
- `tests/TestCase.php::refreshApplication()` aborts the run unless `APP_ENV=testing` and the DB name ends with `_test` (runs before RefreshDatabase/DatabaseTruncation).
- New read-only command `php artisan linklibrary:db-check [--tables] [--env=testing]` (connectivity, server version, FTS configs, expected tables).
- `scripts/local-setup.ps1` (+ `local-setup.cmd`): tooling/extension checks → env sanity checks → composer install/update (creates `composer.lock` if missing) → APP_KEY if missing → `config:clear` → connectivity check (dev + test) → `migrate --seed` → schema verification → `php artisan test`. Never drops databases or runs `migrate:fresh`.
- `CurlTransportTest` made Windows-compatible (`NUL` instead of `/dev/null`); `ConcurrentRevocationTest` is skipped on Windows (no `pcntl`).

### 2026-11-01 — Backend API integration readiness audit
- Verified from the Windows log: all migrations + seeders, 26/26 tables, test isolation (`linklibrary_test`).
- Routes: 80 under `/api/v1`; only auth bootstrap + `/public/*` are unauthenticated; `/admin/*` gated by `can:admin`; invitations require verified e-mail; verification uses relative signed URLs.
- New read-only `php artisan linklibrary:doctor [--network]` (DB, queue worker, scheduler heartbeat, mail, CA bundle, threat-intel/AI providers, CORS/Sanctum/session, storage link).
- New `php artisan linklibrary:dev-mark-clear` (local only).
- New `tests/Feature/Api/FrontendContractTest.php` + `tests/Support/OpenApiValidator.php`: walks the frontend flow and validates every response against `docs/openapi.yaml` (including undocumented keys) and checks documented request params against validation rules.
- New `docs/FRONTEND_INTEGRATION_HANDOFF.md` (endpoints with guards, auth flows, payloads, error catalogue, pagination, integration order).
- Recommendation: **GO** for the Next.js frontend.

### 2026-11-01 — Published to GitHub
- Repository: https://github.com/omeralis/linklibrary-api — **PRIVATE**, default branch `main`.
- Initial commit `e4332eb49e443dde61af7a2070ccce881681bdcf` (285 files), pushed without force; no prior Git history existed.
- `.gitignore` hardened: `.env`, `.env.*` (except `.env.example`), `vendor/`, `node_modules/`, `_to_delete/`, `*.log`, DB dumps (`*.sql`, `*.dump`, `*.backup`, `*.bak`), archives, temp files, key/cert files.
- `.gitattributes`: `*.cmd`, `*.bat`, `*.ps1` checked out with CRLF.
- Secret scan before commit (values never printed): no API keys, tokens, private keys, app keys or real DB credentials in tracked files; the real DB password appears only in the ignored `.env` / `.env.testing`. Intentional local-only placeholders remain: demo password `Password12345` (DemoSeeder, APP_ENV=local only), docker-compose dev `POSTGRES_PASSWORD`, test fixture `token=SECRET…` in `SafeHttpClientTest`.
- README rewritten: stack, installation, PostgreSQL setup, env config, migrations/seeders, queue/scheduler, tests, API docs location.
- Git author: `omeralis <37330522+omeralis@users.noreply.github.com>` (repo-local config). GitHub CLI authenticated as `omeralis` (scopes repo, read:org, gist).

## 9. Errors & Unresolved Issues

| Date | Issue | Status | Resolution / note |
|------|-------|--------|-------------------|
| 2026-11-01 | packagist.org returns 403 in build sandbox. | RESOLVED (workaround) | GitHub-tag-based Composer repo; see §8. |
| 2026-11-01 | `composer.lock` not delivered (sandbox lock references local paths). | RESOLVED | Generated on Windows from packagist and committed to GitHub. |
| 2026-11-01 | Docker images not built/run in sandbox (no Docker Hub). | OPEN | `Dockerfile`/`docker-compose.yml` written but untested; verify with `docker compose up --build`. |
| 2026-11-01 | Live AI providers and Google Safe Browsing/Web Risk not exercised (no credentials; tests mock them). | OPEN | Verify with real keys in a staging environment. Check current Safe Browsing licensing (non-commercial) vs Web Risk. |
| 2026-11-01 | Arabic stemming is light (Snowball); mitigated with orthographic folding + `simple` prefix matching. | ACCEPTED LIMITATION | Documented in ARCHITECTURE.md. |
| 2026-11-01 | `SESSION_DRIVER=database` required for session listing endpoint. | ACCEPTED | Endpoint reports `supported: false` otherwise. |
| 2026-11-01 | `.env.example` contained a real DB password. | RESOLVED | Moved to ignored `.env` / `.env.testing`; `.env.example` now has `DB_PASSWORD=` (blank). |
| 2026-11-01 | `php artisan test` unavailable (collision removed). | RESOLVED | Re-added `nunomaduro/collision` to require-dev; verified in sandbox (228 passed). |
| 2026-11-01 | Local Windows verification (PostgreSQL 18). | RESOLVED | `scripts\local-setup.cmd` completed (exit 0); see §7. |
| 2026-11-01 | 5 deprecated tests on PHP 8.5: `curl_close()` deprecated (no-op since 8.0) in `CurlTransport`; same class of issue: `imagedestroy()` in `ImageStorage`. Production code, not just tests. | RESOLVED (verify on Windows) | Removed both calls; deprecations now collected in `storage/logs/deprecations.log` during tests. |
| 2026-11-01 | Expected 4xx `ApiException`s were reported as ERROR with stack traces (laravel.log grew to 1.4 MB during tests). | RESOLVED | `ApiException implements ShouldntReport`. |
| 2026-11-01 | `POST /collections` returned `sort_order: null` (DB default not loaded). | RESOLVED | Found by the new OpenAPI contract test. |
| 2026-11-01 | Token login returned `expires_at: null` although tokens expire. | RESOLVED | Expiry now set on creation. |
| 2026-11-01 | OpenAPI gaps: `language`/`sort` on public search & user links, `meta.scope/collection/email_verified/allow_resharing/viewer_count/supported`, category description fields; `/shared-with-me` accepted ignored filters. | RESOLVED | Spec regenerated; `SharedLinksRequest`; contract test enforces docs ↔ rules ↔ responses. |
| 2026-11-01 | SSR rate limiting: Next.js server would share one IP budget on public endpoints. | RESOLVED | Optional `FRONTEND_SERVER_KEY` + `X-Frontend-Key`/`X-Client-IP`. |
| 2026-11-01 | Without a threat-intel key all links stay UNKNOWN → publish/share/explore untestable locally. | MITIGATED | `php artisan linklibrary:dev-mark-clear` (APP_ENV=local only, audited manual review). Production still needs Web Risk/Safe Browsing. |
| 2026-11-01 | Windows PHP may lack a CA bundle (`curl.cainfo`) → HTTPS extraction fails. | OPEN (check) | `php artisan linklibrary:doctor --network` reports it. |
| 2026-11-02 | Recursive revocation counted (`revoked_users`) and notified users who still had access through an independent path (e.g. owner→bob→dave plus owner→dave: revoking bob notified dave "access removed"). Found by the real-backend E2E suite. | RESOLVED | `AccessGraphService::revokeGrant` returns only users with no remaining valid grant; regression test `test_users_keeping_access_through_another_path_are_not_counted_or_notified`. |
| 2026-11-02 | OpenAPI `Envelope.data` emitted as `[]` (invalid schema; frontend generator had to patch it). | RESOLVED | `scripts/build-openapi.php` emits an any-value schema with a description; spec regenerated. |
| 2026-11-02 | `DELETE /links/{id}/access/{grantId}` documented as message-only although it returns `{ revoked_users }`. | RESOLVED | Documented; covered by `FrontendContractTest`. |
| 2026-11-01 | Fixed during testing: middleware priority (locale on 401), savepoints around unique-violation catches, `CSRF_TOKEN_MISMATCH` code mapping, Windows-1256 decoding, verification signature (relative URLs). | RESOLVED | Covered by tests. |

---

## 10. Next Task

**Exact next task:** Clone/pull from `https://github.com/omeralis/linklibrary-api` (private) for any new work. Re-run `scripts\local-setup.cmd` to confirm 0 deprecations on PHP 8.5 (expected 229 passed, 1 skipped) and run `php artisan linklibrary:doctor --network`; then start the Next.js frontend following `docs/FRONTEND_INTEGRATION_HANDOFF.md` §9. Later: verify `docker compose up --build`. Optional next steps: configure a real threat-intel provider (Web Risk for commercial use) and an AI provider in staging; start the Next.js frontend using `docs/FRONTEND_INTEGRATION.md` and `docs/openapi.yaml`.

**Resume commands:**
```bash
cd E:\Dev\LinkLibrary
git pull                             # repo: github.com/omeralis/linklibrary-api (private)
composer install                     # composer.lock is committed
copy .env.example .env               # (cp on macOS/Linux)
php artisan key:generate
# create PostgreSQL role/databases: linklibrary + linklibrary_test (see README)
php artisan migrate --seed
php artisan storage:link
php artisan test
vendor/bin/phpstan analyse --memory-limit=1G
php artisan serve
php artisan queue:work --queue=security,extraction,ai,default
php artisan schedule:work
```

---

## 11. Session History

| Date | Summary |
|------|---------|
| 2026-11-02 | Real-backend integration audit with the Next.js frontend: fixed recursive-revocation over-reporting (count + notifications), OpenAPI `Envelope.data` and revoke-response schema; 231 tests passing; frontend real E2E 42/42. Windows-side checks (PHP 8.5 on Windows, PG18, doctor --network, storage link) not re-verified in this session. |
| 2026-11-01 | Created progress file; repo empty; spec missing → blocked. |
| 2026-11-01 | Local Windows setup verified; integration-readiness audit done (fixes above), handoff doc written. GO for frontend. |
| 2026-11-01 | Published backend to private GitHub repo `omeralis/linklibrary-api` (`main`, initial commit e4332eb); README/.gitignore updated; secret scan clean. |
| 2026-11-01 | Spec received. Bootstrapped Laravel 13 in sandbox; implemented full schema, auth, URL/SSRF layer, security scanning, extraction, AI, links, taxonomy, collections, invitations, access graph, discovery, search, notifications, audit, jobs/scheduler, OpenAPI, docs, Docker files. 228 tests passing, PHPStan clean. Copied to `E:\Dev\LinkLibrary`. |
| 2026-11-03 | AI plan updated for the 2 GB VPS: no local LLM; Gemini free tier provider + `AiQuotaGuard` (rpm, daily cap by Pacific day, 1 in flight), quota exhaustion waits for the reset (no skip, no paid fallback); `processing.ai_wait` in the API; mail checks in `doctor`; `docs/AI_INTEGRATION_PLAN.md`. 244 tests pass. Live Gemini/Groq/SMTP NOT VERIFIED. |
| 2026-11-03 | Temporary policy flags: security scanning "Coming Soon" (manual rescan disabled, 422) and publish/share of UNKNOWN links (flag `SECURITY_ALLOW_UNVERIFIED_SHARING`), `GET /public/features`, `security.verified/can_share/scanning` in link payloads. 254 backend tests pass. See docs/SECURITY.md. |
