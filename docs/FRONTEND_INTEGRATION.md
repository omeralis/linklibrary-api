# Next.js integration (App Router, native fetch)

## Recommended topology

Serve the frontend and API on the **same registrable domain** so Sanctum can use first-party cookies:

| | Example production | Local |
|---|---|---|
| Frontend | `https://app.example.com` | `http://localhost:3000` |
| API | `https://api.example.com` | `http://localhost:8000` |

Backend `.env` for that production example:

```dotenv
APP_URL=https://api.example.com
FRONTEND_URL=https://app.example.com
SANCTUM_STATEFUL_DOMAINS=app.example.com
CORS_ALLOWED_ORIGINS=https://app.example.com
SESSION_DOMAIN=.example.com        # cookie shared by app. and api.
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax              # same-site subdomains → lax works; use "none" only for cross-site (requires Secure)
SESSION_HTTP_ONLY=true
SESSION_ENCRYPT=true
TRUSTED_PROXIES=*                  # or your load balancer CIDRs, so HTTPS/IP detection is correct
```

Cross-site deployments (different registrable domains) would need `SameSite=None; Secure` cookies and are
subject to third-party-cookie blocking in browsers — prefer same-site, or proxy `/api` through Next.js
(rewrites), or use bearer tokens from a server-side BFF.

## Cookie (SPA) flow from the browser

```ts
const API = process.env.NEXT_PUBLIC_API_URL; // https://api.example.com

function xsrf(): string {
  const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
  return m ? decodeURIComponent(m[1]) : '';
}

export async function api(path: string, init: RequestInit = {}) {
  const method = (init.method ?? 'GET').toUpperCase();
  if (method !== 'GET' && !xsrf()) {
    await fetch(`${API}/sanctum/csrf-cookie`, { credentials: 'include' });
  }
  const res = await fetch(`${API}/api/v1${path}`, {
    ...init,
    credentials: 'include',                       // send/receive the session cookie
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'Accept-Language': 'ar',                    // or 'en' (the signed-in user's saved locale wins)
      ...(method !== 'GET' ? { 'X-XSRF-TOKEN': xsrf() } : {}),
      ...init.headers,
    },
  });
  const body = await res.json();
  if (!body.success) throw Object.assign(new Error(body.message), { status: res.status, code: body.code, errors: body.errors });
  return body;
}

// login
await api('/auth/login', { method: 'POST', body: JSON.stringify({ email, password, remember: true }) });
```

A `419 CSRF_TOKEN_MISMATCH` means the XSRF cookie expired: call `/sanctum/csrf-cookie` again and retry once.

## Server components / route handlers

Server-side `fetch` from Next.js is not a browser: either forward the incoming cookies
(`headers: { cookie: cookies().toString(), referer: FRONTEND_URL }`) **and** an `Origin`/`Referer` on a
stateful domain, or use a bearer token stored server-side (POST `/auth/login` with `device_name` from a
non-stateful origin returns `data.token`). Never cache authenticated responses in the Next.js data cache:
use `cache: 'no-store'`. Public discovery endpoints (`/public/*`) are safe to cache with `revalidate`.

## Links in e-mails

The backend builds frontend URLs from `FRONTEND_URL` + `FRONTEND_PATH_*`:

| Page | Default path | Query | Frontend should call |
|---|---|---|---|
| Verify e-mail | `/verify-email` | `id, hash, expires, signature` | `GET /api/v1/auth/email/verify/{id}/{hash}?expires=…&signature=…` |
| Reset password | `/reset-password` | `token, email` | `POST /api/v1/auth/reset-password` |
| Accept invitation | `/invitations/accept` | `token` | `POST /api/v1/invitations/{token}/accept` (user must be signed in with the invited, verified e-mail) |
| Saved link | `/l/{publicId}` | | `GET /api/v1/links/{publicId}` (signed in) or `GET /api/v1/public/links/{publicId}` |
| Collection | `/c/{publicId}` | | `GET /api/v1/public/collections/{publicId}` |
| Profile | `/u/{username}` | | `GET /api/v1/public/users/{username}` |

## Response conventions

```json
{ "success": true, "message": "Link saved successfully.", "data": { … }, "meta": { "pagination": { "current_page": 1, "per_page": 20, "has_more": true, "total": 42, "last_page": 3 } } }
{ "success": false, "message": "You are not authorized to access this resource.", "errors": {}, "code": "FORBIDDEN" }
```

Error codes are stable and machine-readable (e.g. `VALIDATION_ERROR`, `UNAUTHENTICATED`, `FORBIDDEN`,
`NOT_FOUND`, `RATE_LIMITED` (+`Retry-After`), `CSRF_TOKEN_MISMATCH`, `EMAIL_NOT_VERIFIED`, `ACCOUNT_SUSPENDED`,
`INVALID_URL`, `URL_NOT_ALLOWED`, `LINK_NOT_PUBLISHABLE`, `LINK_NOT_SHAREABLE`, `LINK_SECURITY_BLOCKED`,
`ALREADY_SHARED`, `GRANT_CYCLE`, `INVITATION_EXPIRED`, `INVITATION_NOT_PENDING`, `INVITATION_NOT_FOUND`).
Messages are localized (`ar`/`en`).

Processing is asynchronous: after `POST /links`, poll `GET /links/{id}/processing-status` (e.g. every 2–5 s
while `status` is `PENDING`/`PROCESSING`) or refresh when the user returns.
