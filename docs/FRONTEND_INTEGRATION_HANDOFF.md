# Frontend Integration Handoff — LinkLibrary API

> Audience: the Next.js (App Router, TypeScript, native `fetch`) team.
> Status: **GO** — backend verified locally on Windows 11 / PHP 8.5 / PostgreSQL 18 (see `IMPLEMENTATION_PROGRESS.md`).
> Source of truth for shapes: [`docs/openapi.yaml`](openapi.yaml) (OpenAPI 3.1). Every response below is
> validated against it by `tests/Feature/Api/FrontendContractTest.php`, so the spec and the API cannot drift silently.

---

## 1. Local environment for frontend development

| Service | Command (from `E:\Dev\LinkLibrary`) | Why it is required |
|---|---|---|
| API | `php artisan serve` → `http://localhost:8000` | Use **`localhost`** (not `127.0.0.1`) so cookies are shared with `http://localhost:3000` |
| Queue worker | `php artisan queue:work --queue=security,extraction,ai,default` | Without it every saved link stays `PENDING` and no e-mails are sent |
| Scheduler | `php artisan schedule:work` | Rescans, invitation expiry, popularity, cleanup |
| Readiness check | `php artisan linklibrary:doctor` (`--network` to test one real HTTPS fetch) | Read-only report of everything below |
| Mail | `MAIL_MAILER=log` (default) writes mails to `storage/logs/laravel.log`; Mailpit (`smtp`, port 1025, UI :8025) recommended | Verification, reset and invitation links |
| Publish/share without a threat-intel key | `php artisan linklibrary:dev-mark-clear --all-unknown` (APP_ENV=local only) | Without an authoritative provider all links stay `UNKNOWN` → not publishable/shareable |
| Local accounts | `admin@linklibrary.test` / `demo@linklibrary.test`, password `Password12345` (seeded only when `APP_ENV=local`) | Quick sign-in; admin can review appeals |

Backend `.env` values that must match the frontend: `APP_URL=http://localhost:8000`, `FRONTEND_URL=http://localhost:3000`,
`SANCTUM_STATEFUL_DOMAINS=localhost:3000`, `CORS_ALLOWED_ORIGINS=http://localhost:3000`, `SESSION_DOMAIN=null`.
Production: see [`FRONTEND_INTEGRATION.md`](FRONTEND_INTEGRATION.md) (same-site subdomains, `SESSION_DOMAIN=.example.com`, `SESSION_SECURE_COOKIE=true`).

---

## 2. Authentication

### 2.1 Browser (recommended): Sanctum SPA cookies

```ts
// lib/api.ts
const API = process.env.NEXT_PUBLIC_API_URL!; // http://localhost:8000

function xsrf() {
  const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
  return m ? decodeURIComponent(m[1]) : '';
}

export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
  const method = (init.method ?? 'GET').toUpperCase();
  if (method !== 'GET' && !xsrf()) {
    await fetch(`${API}/sanctum/csrf-cookie`, { credentials: 'include' });
  }
  const isForm = init.body instanceof FormData;
  const res = await fetch(`${API}/api/v1${path}`, {
    ...init,
    credentials: 'include',
    headers: {
      Accept: 'application/json',
      ...(isForm ? {} : { 'Content-Type': 'application/json' }),
      'Accept-Language': document.documentElement.lang || 'ar',
      ...(method !== 'GET' ? { 'X-XSRF-TOKEN': xsrf() } : {}),
      ...init.headers,
    },
  });
  const body = await res.json();
  if (!res.ok || body.success === false) throw new ApiError(res.status, body);
  return body as T;
}
```

1. `GET /sanctum/csrf-cookie` (once, and again after a `419 CSRF_TOKEN_MISMATCH`).
2. `POST /api/v1/auth/login` `{ email, password, remember }` → `data.auth_mode = "session"`; an HttpOnly session cookie is set.
3. `GET /api/v1/auth/me` on app load to know who is signed in (401 → guest).
4. `POST /api/v1/auth/logout`.

### 2.2 Token mode (server-side BFF, scripts, mobile)

`POST /auth/login` (or `/auth/register`) **with `device_name`** from a non-stateful origin returns
`data.token` (prefix `ll_`, expires after `SANCTUM_TOKEN_EXPIRATION_MINUTES`, default 30 days). Send `Authorization: Bearer <token>`.

### 2.3 Registration, verification, password reset

| Step | Endpoint | Notes |
|---|---|---|
| Register | `POST /auth/register` `{ name, username, email, password, password_confirmation, locale? }` | `username`: `^[a-z0-9_]{3,30}$`, lowercased; reserved names rejected. Session login in SPA mode. |
| E-mail link | frontend `/verify-email?id&hash&expires&signature` | Built by the backend from `FRONTEND_URL` |
| Verify | `GET /auth/email/verify/{id}/{hash}?expires=…&signature=…` | Pass `expires` **then** `signature`, unchanged. Works signed-in or not. `403 INVALID_SIGNATURE` when expired/tampered (60 min). |
| Resend | `POST /auth/email/verification-notification` | auth, 6/min |
| Forgot | `POST /auth/forgot-password` `{ email }` | Always `202` with the same message (no account enumeration) |
| Reset page | frontend `/reset-password?token&email` | |
| Reset | `POST /auth/reset-password` `{ token, email, password, password_confirmation }` | `422 INVALID_RESET_TOKEN`; signs out other sessions |

**Verified e-mail is required** to publish links, create public collections, send and accept invitations
(`403 EMAIL_NOT_VERIFIED`). Show a "verify your e-mail" banner when `user.email_verified === false`.

Register response:

```json
{
  "success": true,
  "message": "Account created. Please check your e-mail to verify your address.",
  "data": {
    "user": {
      "public_id": "fe2dc82e-755d-4cf3-8826-e3378c146d6c",
      "name": "Sara Ali",
      "username": "sara",
      "email": "sara@example.com",
      "email_verified": false,
      "email_verified_at": null,
      "avatar_url": null,
      "bio": null,
      "locale": "en",
      "theme": "system",
      "profile_public": false,
      "is_admin": false,
      "created_at": "2026-11-01T19:32:03+00:00"
    },
    "auth_mode": "token",
    "token": "1|ll_<redacted-example-token>",
    "token_type": "Bearer",
    "expires_at": "2026-12-01T19:32:03+00:00"
  }
}
```

---

## 3. Response conventions

**Success** `{ success: true, message: string|null, data, meta? }` · **Error** `{ success: false, message, errors, code }`.
`message` is localized (`ar`/`en`): `?locale=` > the signed-in user's saved `locale` > `Accept-Language` > `en`.

```json
{
  "success": false,
  "message": "The given data was invalid.",
  "errors": {
    "name": [
      "The name field is required."
    ],
    "username": [
      "The username field is required."
    ],
    "email": [
      "The email field must be a valid email address."
    ],
    "password": [
      "The password field is required."
    ]
  },
  "code": "VALIDATION_ERROR"
}
```

```json
{
  "success": false,
  "message": "This link cannot be published until it passes the security check.",
  "errors": {
    "security_status": [
      "UNKNOWN"
    ]
  },
  "code": "LINK_NOT_PUBLISHABLE"
}
```

```json
{
  "success": false,
  "message": "The requested resource was not found.",
  "errors": {},
  "code": "NOT_FOUND"
}
```

**Pagination** (`page`, `per_page` ≤ 50, default 20):

```json
"meta": { "pagination": { "current_page": 1, "per_page": 20, "has_more": true, "total": 42, "last_page": 3 } }
```

**Privacy rule:** anything the caller may not see returns **404 `NOT_FOUND`**, exactly like a non-existent id. Treat 404 as "not available".

### Error codes

| HTTP | `code` | Meaning / UI |
|---|---|---|
| 401 | `UNAUTHENTICATED` | Redirect to sign-in |
| 403 | `FORBIDDEN` | Not allowed (admin routes, resharing disabled…) |
| 403 | `EMAIL_NOT_VERIFIED` | Prompt to verify e-mail |
| 403 | `ACCOUNT_SUSPENDED` | Sign out, show notice |
| 403 | `LINK_SECURITY_BLOCKED` | Link is MALICIOUS: hide open button / viewer access suspended |
| 403 | `INVALID_SIGNATURE` | Verification link expired → offer resend |
| 404 | `NOT_FOUND`, `INVITATION_NOT_FOUND` | Not found or not accessible |
| 405 | `METHOD_NOT_ALLOWED` | Programming error |
| 409 | `ALREADY_SHARED`, `ALREADY_OWNER`, `GRANT_CYCLE`, `INVITATION_NOT_PENDING`, `TOO_MANY_PENDING_INVITATIONS`, `APPEAL_ALREADY_PENDING`, `NOT_DELETED`, `CONFLICT` | State conflict |
| 410 | `INVITATION_EXPIRED` | Ask the sender for a new invitation |
| 419 | `CSRF_TOKEN_MISMATCH` | Refresh CSRF cookie and retry once |
| 422 | `VALIDATION_ERROR` | Map `errors.<field>[]` onto form fields |
| 422 | `INVALID_URL` (`errors.url`), `URL_NOT_ALLOWED` | URL rejected before saving |
| 422 | `LINK_NOT_PUBLISHABLE`, `LINK_NOT_SHAREABLE` (`errors.security_status`) | Security status not CLEAR |
| 422 | `CANNOT_SHARE_WITH_SELF`, `INVALID_LINKS`, `INVALID_ORDER`, `COLLECTION_FULL`, `INVALID_IMAGE`, `APPEAL_NOT_APPLICABLE`, `LINK_BLOCKED`, `INVALID_RESET_TOKEN` | Domain validation |
| 429 | `RATE_LIMITED` | Back off; honour `Retry-After` |
| 500/503 | `SERVER_ERROR`, `SERVICE_UNAVAILABLE` | Generic error (no details in production) |

---

## 4. Saved links

### 4.1 Lifecycle and processing

`POST /links { url, custom_title?, custom_description?, custom_source_type?, notes?, category?, tags?[], collections?[] }`
returns **201** immediately (always `PRIVATE`), or **200** with `meta.already_saved = true` for a URL the user already saved.
Security scan → metadata extraction → optional AI run in the background.
Poll `GET /links/{id}/processing-status` every 2–5 s while `status` ∈ `PENDING|PROCESSING`; stop on `COMPLETED|PARTIAL|FAILED|BLOCKED`.

| `processing_status` | UI |
|---|---|
| `PENDING` / `PROCESSING` | Skeleton/spinner; show the URL/hostname |
| `COMPLETED` | Normal card |
| `PARTIAL` | Card with whatever metadata exists; suggest editing |
| `FAILED` | "Couldn't fetch details" + edit form (manual metadata always works) |
| `BLOCKED` | Security-blocked or robots/paywall; show security panel |

Display fields are already resolved by precedence (**user override > extracted > AI > hostname**): use
`title`, `description`, `image_url`, `source_type`, `category`. Edit forms should bind to `overrides.*`;
show `extracted.*` as placeholders. `ai` (null when AI is off) is a labelled suggestion block.

Owner representation (`GET /links/{id}`, trimmed):

```json
{
  "success": true,
  "message": null,
  "data": {
    "public_id": "9cb9e437-a3ef-40f5-870a-2f36b6caac1e",
    "internal_path": "/l/9cb9e437-a3ef-40f5-870a-2f36b6caac1e",
    "frontend_url": "http://localhost:3000/l/9cb9e437-a3ef-40f5-870a-2f36b6caac1e",
    "access_role": "owner",
    "title": "Queues - Laravel",
    "description": "Laravel queues allow you to defer time consuming tasks.",
    "image_url": "https://laravel.com/img/og.png",
    "favicon_url": "https://laravel.com/favicon.ico",
    "site_name": "Laravel",
    "hostname": "laravel.com",
    "source_type": "WEBSITE",
    "language": "en",
    "category": null,
    "tags": [
      {
        "name": "laravel",
        "slug": "laravel",
        "source": "USER"
      },
      {
        "name": "queues",
        "slug": "queues",
        "source": "USER"
      }
    ],
    "ai": null,
    "url": "https://laravel.com/docs/queues",
    "open_url": "https://laravel.com/docs/queues",
    "security": {
      "status": "CLEAR",
      "can_open": true,
      "warning": null,
      "checked_at": "2026-11-01T19:32:03+00:00"
    },
    "owner": {
      "username": "sara",
      "name": "Sara Ali",
      "avatar_url": null,
      "profile_url": "http://localhost:3000/u/sara"
    },
    "published_at": null,
    "visibility": "PRIVATE",
    "allow_resharing": false,
    "is_favorite": false,
    "notes": "read later",
    "overrides": "{ title, description, image_url, source_type, category }",
    "processing_status": "COMPLETED",
    "processing": {
      "status": "COMPLETED",
      "security": "CLEAR",
      "extraction": "COMPLETED",
      "extraction_error_code": null,
      "ai": "DISABLED",
      "ai_enabled": false
    },
    "permissions": {
      "can_edit": true,
      "can_delete": true,
      "can_change_visibility": true,
      "can_publish": true,
      "can_share": true,
      "can_reshare": true
    },
    "created_at": "2026-11-01T19:32:03+00:00",
    "extracted": "{ title, description, image_url, canonical_url, content_type, source_type, status, error_code, extracted_at }"
  }
}
```

`access_role` is `owner`, `viewer` (shared with me: adds `shared_by`, `permissions`; never `notes`/settings) or `public`.

### 4.2 Endpoints

| Action | Request |
|---|---|
| List mine | `GET /links?q&favorite&visibility&category&tag&tags[]&source_type&security_status&collection&trashed=only|with&sort=latest|oldest|title|popular|relevance&page&per_page` |
| View | `GET /links/{id}` (owner, viewer or public) — guests use `GET /public/links/{id}` |
| Edit metadata | `PATCH /links/{id}` `{ custom_title, custom_description, custom_image_url, custom_source_type, notes, category, tags[] }` (send `null` to clear an override) |
| Cover image | `POST /links/{id}/cover` multipart `image` (JPEG/PNG/WebP/GIF ≤ 2 MB) · `DELETE /links/{id}/cover` |
| Tags | `POST /links/{id}/tags { tags[] }` · `DELETE /links/{id}/tags/{slug}` |
| Favorite | `POST /links/{id}/favorite` (toggle) or `{ favorite: bool }` |
| Visibility | `PATCH /links/{id}/visibility { visibility: "PUBLIC"|"PRIVATE" }` — PUBLIC needs verified e-mail + CLEAR (`permissions.can_publish`) |
| Resharing | `PATCH /links/{id}/sharing-settings { allow_resharing }` |
| Delete / restore | `DELETE /links/{id}` (soft; `?permanent=1` permanent) · `POST /links/{id}/restore` |
| Re-analyse | `POST /links/{id}/reanalyze` → 202 (20/hour) |
| Open safely | `GET /links/{id}/open` → `{ url, security_status, warning }`; open in a new tab with `rel="noopener noreferrer"` |

### 4.3 Security UI

`security.status`: `PENDING | UNKNOWN | CLEAR | SUSPICIOUS | MALICIOUS`. Never label a link "safe".

| Status | Publish | Share | Open (owner) | Open (viewer) | Suggested UI |
|---|---|---|---|---|---|
| PENDING | ✗ | ✗ | warned | — | "Checking…" |
| UNKNOWN | ✗ | ✗ | warned | warned | "Not verified" + rescan |
| CLEAR | ✓ | ✓ | ✓ | ✓ | none |
| SUSPICIOUS | ✗ | ✗ | warned | ✗ | warning + "Request review" |
| MALICIOUS | ✗ | ✗ | ✗ | access suspended | danger state, no open button |

Use `security.can_open`, `open_url` (null when blocked), `permissions.can_publish`, `permissions.can_share`.
Owner tools: `GET /links/{id}/security` (history, appeals, `disclaimer`), `POST …/security/rescan` (10/hour),
`POST …/security/appeal { reason (10–2000) }` (SUSPICIOUS/MALICIOUS only).

---

## 5. Collections

| Action | Request |
|---|---|
| List / create | `GET /collections` · `POST /collections { name, description?, visibility?, cover_image_url?, sort_order? }` |
| View with items | `GET /collections/{id}` → `data.items[]` (owner representation) |
| Update / delete | `PATCH /collections/{id}` · `DELETE /collections/{id}` (links are kept) |
| Add / remove links | `POST /collections/{id}/links { links: [savedLinkId…] }` → `{ added }` · `DELETE /collections/{id}/links/{savedLinkId}` |
| Reorder (drag & drop) | `PATCH /collections/{id}/reorder { links: [savedLinkId in new order] }` |
| Public page | `GET /public/collections/{id}` → `data` = public links (paginated), `meta.collection` = the collection |

Private links inside a public collection are never shown publicly.

---

## 6. Private sharing

1. Owner (or viewer when `allow_resharing` and `permissions.can_reshare`) → `POST /links/{id}/invitations { email }` (verified e-mail, CLEAR link, 30/hour).
2. Recipient gets an e-mail with `/invitations/accept?token=…` → frontend page: if signed out, sign in/register **with that e-mail** and verify it, then
   `POST /invitations/{token}/accept` → returns the link (viewer representation). Wrong account → `404 INVITATION_NOT_FOUND`.
3. In-app alternative: `GET /me/invitations` → `POST /me/invitations/{invitationId}/accept` (the e-mail token is not needed).
4. Lists: `GET /shared-with-me`, `GET /links/{id}/invitations`, `GET /links/{id}/access` (owner: whole graph with `effective`, `depth`, `parent_grant_id`; viewer: grants they issued).
5. Revoke: `DELETE /links/{id}/invitations/{invitationId}`, `DELETE /links/{id}/access/{grantId}` (owner: any; grantor: theirs; grantee: leave). Dependent grants are revoked too; independent paths survive.

```json
{
  "success": true,
  "message": "Invitation sent.",
  "data": {
    "id": "e63af904-3d1a-4d23-88ef-072b28601c5a",
    "email": "omar@example.com",
    "status": "PENDING",
    "invited_by": {
      "username": "sara",
      "name": "Sara Ali"
    },
    "via_reshare": false,
    "expires_at": "2026-11-08T19:32:03+00:00",
    "accepted_at": null,
    "revoked_at": null,
    "created_at": "2026-11-01T19:32:03+00:00"
  }
}
```

---

## 7. Public explore, profiles, search

| Page | Request |
|---|---|
| Explore | `GET /public/links?sort=latest|popular|relevance&category&tag&tags[]&source_type&language=ar|en&q&page` |
| Link page `/l/{id}` | signed in → `GET /links/{id}`; guest → `GET /public/links/{id}` (404 → "unavailable") |
| Categories / tags | `GET /public/categories` (with `links_count`) · `GET /public/tags?q=` |
| Profile `/u/{username}` | `GET /public/users/{username}`, `…/links`, `…/collections` (404 when the profile is private) |
| Public search | `GET /public/search?q=…` (`q` 2–200 chars) |
| My search | `GET /search?q&scope=own|shared|all&type=links|collections&category&tag&source_type&favorite&sort` |
| My tags | `GET /tags?q=` (autocomplete) · `GET /categories` |
| Profile settings | `GET/PATCH /me`, `PATCH /me/password`, `POST/DELETE /me/avatar`, `DELETE /me { password, confirmation: "DELETE" }`, `GET /me/sessions`, `GET /me/tokens` |
| Notifications | `GET /notifications?unread=1`, `GET /notifications/unread-count`, `PATCH /notifications/{id}/read`, `POST /notifications/read-all` |

Search is Arabic-aware (diacritics/alef/ta-marbuta folding, prefix match) and English-stemmed.

```json
{
  "success": true,
  "message": null,
  "data": {
    "username": "sara",
    "name": "Sara Ali",
    "avatar_url": null,
    "bio": null,
    "profile_url": "http://localhost:3000/u/sara",
    "joined_at": "2026-11-01",
    "stats": {
      "public_links": 1,
      "public_collections": 0
    }
  }
}
```

Public responses carry `Cache-Control: public, max-age=30` and are safe for Next.js `fetch(..., { next: { revalidate: 30 } })`.
**Never cache authenticated responses** (`cache: 'no-store'`).

### SSR and rate limits

Anonymous limits are per IP (public 60/min, search 30/min). When Next.js renders public pages on the server,
every request comes from the server's IP. Set the same secret in the backend (`FRONTEND_SERVER_KEY`) and the
Next.js server environment, and send it **only from server code**:

```ts
headers: { 'X-Frontend-Key': process.env.FRONTEND_SERVER_KEY!, 'X-Client-IP': clientIpFromRequestHeaders }
```

The backend then rate-limits by the end user's IP. Never expose this key to the browser.

---

## 8. All endpoints (80)

Middleware verified from `php artisan route:list`. "auth" = Sanctum (cookie or bearer) + active account.

| Method | Path (`/api/v1`…) | Guards |
|---|---|---|
| `GET` | `/admin/audit-logs` | auth, admin, throttle: api |
| `GET` | `/admin/categories` | auth, admin, throttle: api |
| `POST` | `/admin/categories` | auth, admin, throttle: api |
| `DELETE` | `/admin/categories/{slug}` | auth, admin, throttle: api |
| `PATCH` | `/admin/categories/{slug}` | auth, admin, throttle: api |
| `GET` | `/admin/security/appeals` | auth, admin, throttle: api |
| `POST` | `/admin/security/appeals/{appealId}/resolve` | auth, admin, throttle: api |
| `PATCH` | `/admin/security/links/{linkId}` | auth, admin, throttle: api |
| `POST` | `/auth/email/verification-notification` | auth, throttle: api + verification |
| `GET` | `/auth/email/verify/{id}/{hash}` | signed URL, throttle: verification |
| `POST` | `/auth/forgot-password` | throttle: password-reset |
| `POST` | `/auth/login` | throttle: login |
| `POST` | `/auth/logout` | auth, throttle: api |
| `GET` | `/auth/me` | auth, throttle: api |
| `POST` | `/auth/register` | throttle: register |
| `POST` | `/auth/reset-password` | throttle: password-reset |
| `GET` | `/categories` | auth, throttle: api |
| `GET` | `/collections` | auth, throttle: api |
| `POST` | `/collections` | auth, throttle: api |
| `DELETE` | `/collections/{publicId}` | auth, throttle: api |
| `GET` | `/collections/{publicId}` | auth, throttle: api |
| `PATCH` | `/collections/{publicId}` | auth, throttle: api |
| `POST` | `/collections/{publicId}/links` | auth, throttle: api |
| `DELETE` | `/collections/{publicId}/links/{savedLinkPublicId}` | auth, throttle: api |
| `PATCH` | `/collections/{publicId}/reorder` | auth, throttle: api |
| `POST` | `/invitations/{token}/accept` | auth, throttle: api + invitation-accept |
| `GET` | `/links` | auth, throttle: api |
| `POST` | `/links` | auth, throttle: api + link-create |
| `DELETE` | `/links/{publicId}` | auth, throttle: api |
| `GET` | `/links/{publicId}` | auth, throttle: api |
| `PATCH` | `/links/{publicId}` | auth, throttle: api |
| `GET` | `/links/{publicId}/access` | auth, throttle: api |
| `DELETE` | `/links/{publicId}/access/{grantId}` | auth, throttle: api |
| `DELETE` | `/links/{publicId}/cover` | auth, throttle: api |
| `POST` | `/links/{publicId}/cover` | auth, throttle: api |
| `POST` | `/links/{publicId}/favorite` | auth, throttle: api |
| `GET` | `/links/{publicId}/invitations` | auth, throttle: api |
| `POST` | `/links/{publicId}/invitations` | auth, verified email, throttle: api + invitations |
| `DELETE` | `/links/{publicId}/invitations/{invitationId}` | auth, throttle: api |
| `GET` | `/links/{publicId}/open` | auth, throttle: api |
| `GET` | `/links/{publicId}/processing-status` | auth, throttle: api |
| `POST` | `/links/{publicId}/reanalyze` | auth, throttle: api + reanalyze |
| `POST` | `/links/{publicId}/restore` | auth, throttle: api |
| `GET` | `/links/{publicId}/security` | auth, throttle: api |
| `POST` | `/links/{publicId}/security/appeal` | auth, throttle: api + appeals |
| `POST` | `/links/{publicId}/security/rescan` | auth, throttle: api + rescan |
| `PATCH` | `/links/{publicId}/sharing-settings` | auth, throttle: api |
| `POST` | `/links/{publicId}/tags` | auth, throttle: api |
| `DELETE` | `/links/{publicId}/tags/{tagSlug}` | auth, throttle: api |
| `PATCH` | `/links/{publicId}/visibility` | auth, throttle: api |
| `DELETE` | `/me` | auth, throttle: api |
| `GET` | `/me` | auth, throttle: api |
| `PATCH` | `/me` | auth, throttle: api |
| `DELETE` | `/me/avatar` | auth, throttle: api |
| `POST` | `/me/avatar` | auth, throttle: api |
| `GET` | `/me/invitations` | auth, throttle: api |
| `POST` | `/me/invitations/{invitationId}/accept` | auth, throttle: api + invitation-accept |
| `PATCH` | `/me/password` | auth, throttle: api |
| `GET` | `/me/sessions` | auth, throttle: api |
| `POST` | `/me/sessions/revoke-others` | auth, throttle: api |
| `DELETE` | `/me/sessions/{sessionId}` | auth, throttle: api |
| `GET` | `/me/tokens` | auth, throttle: api |
| `DELETE` | `/me/tokens/{tokenId}` | auth, throttle: api |
| `GET` | `/notifications` | auth, throttle: api |
| `POST` | `/notifications/read-all` | auth, throttle: api |
| `GET` | `/notifications/unread-count` | auth, throttle: api |
| `PATCH` | `/notifications/{id}/read` | auth, throttle: api |
| `GET` | `/public/categories` | throttle: public |
| `GET` | `/public/collections/{publicId}` | throttle: public |
| `GET` | `/public/links` | throttle: public |
| `GET` | `/public/links/{publicId}` | throttle: public |
| `GET` | `/public/search` | throttle: public + search |
| `GET` | `/public/tags` | throttle: public |
| `GET` | `/public/users/{username}` | throttle: public |
| `GET` | `/public/users/{username}/collections` | throttle: public |
| `GET` | `/public/users/{username}/links` | throttle: public |
| `GET` | `/search` | auth, throttle: api + search |
| `GET` | `/shared-with-me` | auth, throttle: api |
| `GET` | `/tags` | auth, throttle: api |
| `GET` | `docs/openapi.yaml` | public |

---

## 9. Recommended integration order

1. **API client & error handling** — `lib/api.ts`, `ApiError` with `code`/`errors`, locale header, 401/419/429 handling.
2. **Auth** — csrf-cookie, register, login, logout, `auth/me` bootstrap, verify-email page, forgot/reset pages, verify banner.
3. **My links** — save form (URL validation errors), list with filters/pagination, link card using display fields, processing polling, detail page, edit form (`overrides`), favorite, delete/restore, tags.
4. **Security UI** — status badges, open button via `/open`, security panel, rescan, appeal.
5. **Collections** — CRUD, add/remove, drag-and-drop reorder.
6. **Visibility & public pages** — publish toggle (`permissions.can_publish`), `/l/[id]`, explore, categories/tags, profiles, public collections, public search.
7. **Sharing** — invite dialog, accept-invitation page, pending invitations, shared-with-me, access list & revoke.
8. **Search & notifications** — global search (scope), notifications bell (poll `unread-count`).
9. **Account settings** — profile, avatar, password, sessions/tokens, delete account.
10. **Admin (optional)** — appeals review, categories, audit log.

---

## 10. Known limitations / things to plan for

- No real-time push: poll `processing-status` and `notifications/unread-count`.
- Without a threat-intelligence key links stay `UNKNOWN` (use `linklibrary:dev-mark-clear` locally; configure Google Web Risk/Safe Browsing for staging/production).
- AI is disabled until `AI_PROVIDER` + key are configured; `ai` is `null` meanwhile.
- `GET /links/{id}` requires auth; guests must use `/public/links/{id}`.
- Session listing needs `SESSION_DRIVER=database` (default).
- Avatars/covers need `php artisan storage:link` (on Windows enable Developer Mode or run the terminal as administrator).
