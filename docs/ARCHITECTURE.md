# Architecture

## Layers

```
routes/api.php ──► Middleware (Sanctum, active, verified, throttle, SetLocale, SecurityHeaders)
                 ──► Controllers (app/Http/Controllers/Api/V1)      thin: validate → call service → resource
                 ──► Form Requests (app/Http/Requests)               validation + normalisation
                 ──► Services (app/Services/**)                      business rules, transactions
                 ──► Models (app/Models) + Enums (app/Enums)          persistence, casts, scopes
                 ──► API Resources (app/Http/Resources)              context-aware JSON (owner / viewer / public)
Jobs (app/Jobs) ─► Services                                          asynchronous pipeline + scheduled maintenance
Notifications (app/Notifications)                                    mail + database
```

| Service | Responsibility |
|---|---|
| `Url\UrlNormalizer` | Strict HTTP(S) validation & canonicalisation, dedup hash |
| `Http\SafeHttpClient` (+ `IpAddressPolicy`, `DnsResolver`, `CurlTransport`) | The only outbound client for user URLs (SSRF-safe) |
| `Security\ReputationService` (+ providers) | Threat-intel aggregation (never CLEAR on failure) |
| `Security\SecurityScanService` | Scan orchestration, verdict application, unsafe side-effects |
| `Security\SecurityPolicy` | What each status permits (fetch / publish / share / open) |
| `Extraction\ExtractionService` (+ extractors, parsers, `RobotsPolicy`) | Metadata pipeline |
| `Ai\AiService` (+ `AiProvider` adapters, `PromptBuilder`, `AiOutputValidator`) | Optional enrichment |
| `Links\LinkService`, `Links\TagService`, `Links\CollectionService` | Saved-link lifecycle, tags, collections |
| `Sharing\AccessGraphService` | Grant graph, effective access, cascading revocation |
| `Sharing\InvitationService` | E-mail invitations |
| `Sharing\LinkAccessResolver` | Resolves owner / viewer / public context (404 otherwise) |
| `Search\LinkQueryService`, `Search\SearchIndexer`, `Search\SearchQuery` | Listing, filtering, FTS |
| `Discovery\DiscoveryCache`, `Discovery\ViewRecorder` | Public cache, abuse-resistant views |
| `Account\AccountService` | Registration, profile, password, deletion |
| `Audit\AuditLogger` | Security audit trail |

## Data model in one paragraph

A canonical `links` row exists once per normalized URL (dedup by SHA-256 hash) and holds only source-level
data: extracted metadata, security status and AI analysis pointers. Each user's copy is a `saved_links` row
holding everything private: overrides, notes, visibility, resharing flag, favorite, category, tags and
collection membership. One user can hold at most one saved instance per canonical link (unique constraint;
re-saving a deleted link restores it as PRIVATE). See [ERD.md](ERD.md).

## Processing pipeline

```
POST /links ─► validate + normalize + local safety checks (sync, no network)
            ─► INSERT … ON CONFLICT links (canonical) ; saved_links (private)
            ─► ScanLinkSecurityJob            queue: security
                   └─ verdict applied ─► (if policy permits fetching) ExtractLinkMetadataJob   queue: extraction
                                              └─ COMPLETED/PARTIAL ─► AnalyzeLinkWithAiJob     queue: ai
```

- **Security first.** Extraction is only queued after the scan, and only if `SecurityPolicy::mayFetch()`
  allows it (CLEAR; UNKNOWN when `SECURITY_FETCH_WHEN_UNKNOWN=true`; never SUSPICIOUS/MALICIOUS).
- **Versioned, idempotent jobs.** `links.extraction_version` / `links.ai_version` are bumped on re-analysis;
  jobs carry the version and every write is `UPDATE … WHERE extraction_version = :v`, so outdated jobs are
  no-ops. Jobs are `ShouldBeUnique` per (link, version).
- **Retries.** Retryable failures (timeouts, 5xx, 429, DNS, robots.txt unavailable) are released with backoff
  60s → 300s → 900s; after the last attempt the link is marked FAILED and recent savers are notified.
  Non-retryable failures (404, blocked IP, robots disallow, login/paywall 401/403/451) end immediately.
- **Manual metadata wins.** Jobs only write to `links.*` and AI-sourced tags/category; user overrides live on
  `saved_links` and are resolved at read time: `user override > extracted > AI suggestion > fallback`.
- **Redirects.** If extraction ends on a different host, that URL is checked against threat intel too
  (`trigger = REDIRECT`); an unsafe result escalates the link.
- **Processing status** shown to clients is derived (`SavedLinkResource::processingStatus`) from the three
  pipeline states instead of being stored redundantly.

## Access graph (private sharing)

Tables: `link_invitations` (pending e-mail invitations, hashed tokens) and `link_access_grants` (edges).

- A **root grant** (`parent_grant_id = NULL`) must be issued by the owner.
- A **derived grant** records the exact parent grant through which the grantor held access; its grantor
  must equal the parent's grantee. `depth = parent.depth + 1`.
- **Effective access**: the owner, or anyone holding at least one grant whose whole ancestor chain is
  ACTIVE, consistent, and rooted at the current owner. Computed by a recursive CTE walking *up* from the
  user's grants (`AccessGraphService::validGrantIdsForGrantee`) — bounded by depth (64).
- **Revocation** marks the grant and all ACTIVE descendants REVOKED in one recursive `UPDATE … RETURNING`,
  revokes pending invitations issued through any revoked grant, notifies affected users and audits each
  grant. Independent paths are untouched, so a user keeps access if another valid path exists.
- **Strict lineage:** a derived grant depends only on the parent it was created through. If that parent is
  revoked, the derived grant is revoked even if its grantor still has access via another path; the grantor
  can re-share explicitly. This prevents access from silently re-attaching to a path the revoking party did
  not authorise.
- **Cycles** are impossible: the grantee may not be the owner or anyone on the parent chain (`GRANT_CYCLE`).
- **Concurrency:** every graph mutation runs in a transaction holding `SELECT … FOR UPDATE` on the saved
  link row, so concurrent invites/accepts/revocations on one link serialize. Verified by a multi-process test.
- **Settings:** disabling resharing blocks new downstream invitations and acceptances, but existing valid
  grants remain. Visibility changes never touch grant history; going back to PRIVATE restores exactly the
  grants that are still valid. Deleting a link revokes everything; restoring does not resurrect access.
- **Security interplay:** sharing requires an unexpired CLEAR verdict at invite *and* accept time; a
  MALICIOUS verdict suspends viewer access and revokes pending invitations.

## Visibility & response shaping

`LinkAccessResolver` decides the caller's role for a saved link — `owner`, `viewer` (valid grant) or
`public` (PUBLIC + eligible) — otherwise 404 (identical to non-existent ids). `SavedLinkResource` renders
by role: viewers never receive notes or owner settings; public responses never include private fields,
and owner identity only appears when the owner's profile is public.

"Publicly eligible" (`SavedLink::scopePubliclyEligible`) = PUBLIC ∧ not deleted ∧ canonical link CLEAR with
unexpired verdict ∧ owner account ACTIVE. It is evaluated in SQL on every discovery/search query, so a link
disappears from public results the moment its verdict changes or expires.

## Search

Two `tsvector` columns per saved link, maintained by `SearchIndexer`:

- `search_public` — title, description, tags, category names (A/B/C weights), AI summary, site, author, host
- `search_private` — notes (owner only)

Each text is indexed with `simple` (exact tokens + prefix search), `english` (stemming) and `arabic`
(Snowball) configurations after Arabic orthographic folding (`ArabicNormalizer`: diacritics/tatweel removed,
أ/إ/آ→ا, ى→ي, ة→ه, Arabic-Indic digits → ASCII). Queries are sanitised to letters/digits (max 12 terms),
matched against `search_public` for everyone and additionally `search_private` only for the owner.
GIN indexes back both columns. Limitation: Snowball Arabic stemming is light; the `simple` + prefix match
compensates for most practical queries.

## Caching

- `DiscoveryCache` caches **anonymous public** responses only, keyed by a global version that is bumped on
  any visibility, security, deletion, profile-visibility or taxonomy change. TTL 60 s by default.
- Authenticated responses are never cached server-side and are sent with `Cache-Control: no-store, private`.
- Authorization decisions are never cached.
- `robots.txt` rules are cached per origin (24 h); failures are not cached.

## Popularity

`link_views` stores one row per (link, HMAC(viewer), day); owners' own views are ignored. Every 30 minutes
`popularity_score = unique viewer-days (30-day window) + 3 × other active accounts that saved the same URL`.

## Notifications

Database + mail: invitation received (mail contains the token; DB copy never does), invitation accepted,
access revoked, security warning (SUSPICIOUS/MALICIOUS), processing failed, password changed, e-mail changed
(sent to the old address). Mail is localized with the recipient's saved locale.
