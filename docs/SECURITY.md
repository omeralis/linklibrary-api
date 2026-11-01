# Security design

## 1. URL policy (`App\Services\Url\UrlNormalizer`)

| Rule | Behaviour |
|---|---|
| Schemes | `http`, `https` only (`javascript:`, `file:`, `data:`, `ftp:`, `gopher:` … rejected) |
| Credentials | Any `userinfo@` in the authority is rejected |
| Characters | C0/C1 control characters, invisible format characters (bidi overrides, zero-width) and backslashes rejected |
| Hosts | IDN converted to ASCII (UTS #46, bidi/contextj checks), lowercased, trailing dot removed; labels validated; single-label hosts rejected |
| Numeric hosts | Only canonical dotted-quad IPv4 / bracketed IPv6. Decimal (`2130706433`), octal (`0177.0.0.1`), hex (`0x7f…`), short (`127.1`) forms and IPv6 zone ids rejected |
| Ports | 1–65535; default ports removed. Fetching additionally restricted to `LINK_FETCH_ALLOWED_PORTS` (80, 443, 8080, 8443) |
| Path | Dot segments resolved; percent-encoding canonicalised (uppercase hex, unreserved decoded, stray `%` escaped, non-ASCII encoded) |
| Query | Preserved verbatim and in order — tracking parameters are **not** stripped (they can change the resource) |
| Fragment | Dropped from the normalized form unless it is a client-side route (`#!…`, `#/…`); kept in `original_url` |
| Length | 2048 bytes max (configurable) |
| Dedup | SHA-256 of the normalized URL (`links.url_hash`, unique) |

Saving a link also performs **synchronous local checks without network access**: internal hostnames
(`localhost`, `*.local`, `*.internal`, metadata hostnames, configured blocklist), non-public IP literals and
disallowed ports are refused with `422 URL_NOT_ALLOWED`. Manually supplied image URLs go through the same checks.

## 2. SSRF protection (`App\Services\Http\SafeHttpClient`)

Every outbound request to a user-influenced URL (page fetch, robots.txt, oEmbed, GitHub API) goes through
this client. For **every hop** (initial request and each redirect):

1. Scheme must be http/https; port must be default or allow-listed; hostname must not be internal.
2. The hostname is resolved; **every** A/AAAA answer must be a public unicast address
   (`IpAddressPolicy`). Blocked: `0/8, 10/8, 100.64/10, 127/8, 169.254/16` (incl. cloud metadata),
   `172.16/12, 192.0.0/24, 192.0.2/24, 192.88.99/24, 192.168/16, 198.18/15, 198.51.100/24, 203.0.113/24,
   224/4, 240/4`, and IPv6 `::/128, ::1, ::ffff:0:0/96, 64:ff9b::/96, 100::/64, 2001::/23, 2001:db8::/32,
   2002::/16, fc00::/7 (incl. fd00:ec2::254), fe80::/10, fec0::/10, ff00::/8`; only `2000::/3` is allowed.
   A mixed answer set (public + private) is treated as hostile.
3. **DNS pinning:** the connection is forced to the validated address with `CURLOPT_RESOLVE`; cURL never
   performs its own lookup, so a rebinding answer cannot redirect the connection. After the request the
   actual peer (`CURLINFO_PRIMARY_IP`) must equal the pinned address (`IP_MISMATCH` otherwise).
4. Redirects are **not** followed by cURL. They are followed manually (max 5), each destination is
   re-normalized and re-validated from step 1; HTTPS → HTTP downgrades are refused.
5. Proxies from the environment are disabled (`CURLOPT_PROXY=''`, `NOPROXY=*`). Protocols restricted to
   HTTP/HTTPS (`CURLOPT_PROTOCOLS`), HTTP/1.1 only (no protocol upgrade), TLS verification on.
6. Limits: connect timeout 5 s, total timeout 15 s, response body 2 MiB (5 MiB for PDFs) **measured after
   decompression** in the write callback (decompression bombs are aborted), `CURLOPT_MAXFILESIZE`.
7. Error messages/diagnostics never contain query strings, bodies or credentials.

Covered by tests: `SafeHttpClientTest` (private/internal targets, rebinding, redirect to private IP or bad
scheme, redirect limits, downgrades, oversize, timeouts) and `CurlTransportTest` (real cURL against a local
fixture server: size limit, gzip bomb, timeout, no redirect following).

### Remaining infrastructure-level controls (recommended)

- Run workers in a network segment with **egress filtering** that also denies RFC 1918 / link-local /
  metadata ranges (defence in depth against bugs or library changes).
- On cloud VMs require IMDSv2 / metadata hop-limit 1 (or block 169.254.169.254 at the host firewall).
- If an egress proxy is mandatory, set `LINK_FETCH_PROXY`; the proxy then performs DNS resolution, so it
  **must** enforce the same IP policy (e.g. Smokescreen). IP pinning cannot be guaranteed through a proxy.
- Keep the worker user unprivileged; no cloud credentials in the worker environment.

## 3. Security statuses and policy (`App\Services\Security\SecurityPolicy`)

| Effective status | Fetch metadata | Publish | New sharing | Owner opens | Viewer opens | Viewer access |
|---|---|---|---|---|---|---|
| `PENDING` | no (scan first) | no | no | yes, warned | — | — |
| `UNKNOWN` | configurable (default yes) | no | no | yes, warned | yes, warned | yes |
| `CLEAR` (unexpired) | yes | yes | yes | yes | yes | yes |
| `SUSPICIOUS` | no | no | no | yes, warned | no | yes, warned |
| `MALICIOUS` | no | no | no | **no** | **no** | **suspended** |

- A CLEAR verdict expires after `SECURITY_CLEAR_TTL_DAYS` (default 7) and is then treated as UNKNOWN until
  rescanned; the scheduler rescans links nearing expiry hourly.
- Unsafe or expired links vanish from discovery immediately (eligibility is evaluated in SQL per query and
  the public cache is invalidated on every status change). Owners are notified; MALICIOUS also revokes
  pending invitations and suspends viewer access.
- The API never claims a link is "safe"; responses carry `security.status`, `security.warning` and the
  security endpoint returns a disclaimer.

## 4. Threat intelligence (`App\Services\Security\*Provider`)

- `safe_browsing` — Google Safe Browsing Lookup API v4. Its terms restrict it to non-commercial use.
- `web_risk` — Google Web Risk `uris:search` (commercial counterpart; paid beyond the free tier).
- `heuristic` — local, **non-authoritative** signals (configured blocklist → MALICIOUS; IP-literal hosts,
  excessive subdomains, mixed-script IDN homographs → SUSPICIOUS). It can never vouch for a URL.

Aggregation: any MALICIOUS → MALICIOUS; else any SUSPICIOUS → SUSPICIOUS; else CLEAR only if at least one
authoritative provider answered CLEAR without error; otherwise **UNKNOWN**. A failed, unconfigured or
rate-limited provider therefore never results in CLEAR. AI signals can only escalate to SUSPICIOUS.
Verdicts are applied in scan order under a row lock (older scans never overwrite newer ones); manual review
decisions take precedence over provider results until their expiry.

Scan history (`link_security_scans`), rescan requests (rate-limited, deduplicated), periodic rescans and
appeals (`link_security_appeals`, admin review) are all recorded and audited.

## 5. Authentication & accounts

- Sanctum SPA cookies (session regenerated on login, `HttpOnly`, `SameSite=Lax`, `Secure` in production,
  CSRF via `XSRF-TOKEN`) or bearer tokens (prefixed `ll_`, 30-day expiry, pruned daily).
- Passwords: bcrypt; production policy min 10, mixed case, numbers, `uncompromised()` (HIBP k-anonymity).
- Login does constant work for unknown accounts and returns the same error; password-reset requests always
  return the same 202 response. Failed logins are audited.
- Sensitive operations require the current password: e-mail change, password change, account deletion
  (+ typed confirmation), "sign out other sessions". Password change/reset revokes other sessions and tokens.
- Suspended accounts are refused on login and on every authenticated request; their content disappears from
  discovery and from viewers.
- E-mail verification uses relative signed URLs (60 min); verification is required to publish, share,
  create public collections and accept invitations.

## 6. Rate limits (per user when authenticated, otherwise per IP)

| Limiter | Limit |
|---|---|
| login | 5/min per e-mail+IP, 20/min per IP |
| register | 10/hour per IP |
| password-reset | 5/min per IP, 5/hour per e-mail |
| verification | 6/min |
| api (authenticated) | 120/min |
| public | 60/min per IP |
| search | 30/min |
| link-create | 30/min, 1000/day |
| invitations | 30/hour; invitation-accept 10/min |
| rescan 10/hour · reanalyze 20/hour · appeals 10/day | |

## 7. Data protection

- Mass assignment: models only allow content fields; ownership, visibility, roles, status, verification and
  security fields are set explicitly by services.
- Private resources return **404** to unauthorised callers (no enumeration). Public identifiers are random
  UUIDv4 and never grant access by themselves.
- Invitation tokens: 256-bit random, only SHA-256 stored, plaintext only in the e-mail.
- Audit log: actor, action, target, safe metadata, HMAC of the client IP; never secrets, tokens, notes or
  full URLs with query strings. Not exposed publicly (admin only).
- Uploads: raster images only (no SVG), size/dimension limits, decoded and **re-encoded** to WebP with GD
  (strips metadata and polyglot payloads), random file names.
- Responses: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`,
  restrictive CSP, HSTS on HTTPS, `Cache-Control: no-store, private` for authenticated responses.
- Production errors never include stack traces (`APP_DEBUG=false`).
- CORS: credentials allowed only for `CORS_ALLOWED_ORIGINS` (never `*`).
