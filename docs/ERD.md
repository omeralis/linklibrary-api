# Database (PostgreSQL)

```mermaid
erDiagram
    users ||--o{ saved_links : owns
    users ||--o{ collections : owns
    links ||--o{ saved_links : "saved as"
    links ||--o{ link_security_scans : scanned
    links ||--o{ link_extraction_jobs : extracted
    links ||--o{ link_ai_analyses : analysed
    links ||--o{ link_security_appeals : appealed
    saved_links ||--o{ saved_link_tags : tagged
    tags ||--o{ saved_link_tags : ""
    categories ||--o{ saved_links : "category / ai_category"
    categories ||--o{ categories : parent
    collections ||--o{ collection_items : contains
    saved_links ||--o{ collection_items : "member of"
    saved_links ||--o{ link_access_grants : "shared via"
    link_access_grants ||--o{ link_access_grants : "parent of"
    users ||--o{ link_access_grants : "grantor / grantee"
    saved_links ||--o{ link_invitations : invites
    link_invitations ||--o| link_access_grants : "accepted as"
    saved_links ||--o{ link_views : viewed
    users ||--o{ audit_logs : acts
    users ||--o{ notifications : receives

    users {
        bigint id PK
        uuid public_id UK
        string username UK
        string email UK
        timestamptz email_verified_at
        string locale
        string theme
        bool profile_public
        string status
        string role
    }
    links {
        bigint id PK
        char64 url_hash UK
        text normalized_url
        text original_url
        string hostname
        string source_type
        text title
        text description
        jsonb metadata
        string extraction_status
        int extraction_version
        string security_status
        timestamptz security_expires_at
        string ai_status
        int ai_version
    }
    saved_links {
        bigint id PK
        uuid public_id UK
        bigint user_id FK
        bigint link_id FK
        string custom_title
        text notes
        string visibility
        bool allow_resharing
        bool is_favorite
        bigint category_id FK
        bigint ai_category_id FK
        tsvector search_public
        tsvector search_private
        timestamptz deleted_at
    }
    link_access_grants {
        bigint id PK
        uuid public_id UK
        bigint saved_link_id FK
        bigint grantor_user_id FK
        bigint grantee_user_id FK
        bigint parent_grant_id FK
        int depth
        string status
    }
    link_invitations {
        bigint id PK
        uuid public_id UK
        bigint saved_link_id FK
        bigint inviter_user_id FK
        bigint inviter_grant_id FK
        string email
        char64 token_hash UK
        string status
        timestamptz expires_at
    }
    collection_items {
        bigint id PK
        bigint collection_id FK
        bigint saved_link_id FK
        bigint user_id
        int position
    }
```

Laravel framework tables are also present: `password_reset_tokens`, `sessions`, `cache`, `cache_locks`,
`jobs`, `job_batches`, `failed_jobs`, `personal_access_tokens`, `migrations`.

## Key decisions

| Decision | Reason |
|---|---|
| `links` (canonical) vs `saved_links` (per user) | One fetch/scan/AI analysis per URL; all private data isolated per user |
| `UNIQUE (user_id, link_id)` on saved_links (including soft-deleted rows) | Concurrency-safe dedup; re-saving restores the deleted row as PRIVATE |
| `UNIQUE (url_hash)` + `INSERT … ON CONFLICT DO NOTHING` | Race-free canonical link creation |
| Composite FKs `collection_items (collection_id, user_id) → collections (id, user_id)` and `(saved_link_id, user_id) → saved_links (id, user_id)` | The database itself forbids putting another user's link into a collection |
| Grants as explicit edges with `parent_grant_id` | Explicit lineage → well-defined cascading revocation and independent paths |
| Partial unique index on ACTIVE grant edges; partial unique index on PENDING invitations | No duplicate active edges / pending invitations, history preserved |
| `CHECK` constraints on all enum-like columns, lowercase e-mail, username format, `revoked_at` consistency, no self-grants | Invalid states cannot be stored even by bugs |
| UUIDv4 `public_id` everywhere exposed; bigint internal keys | Non-enumerable identifiers; compact joins |
| `timestamptz` | Unambiguous times across time zones |
| JSONB only for flexible provider data (`links.metadata`, scan `provider_results`, AI tags, audit metadata) | Relational data stays relational |
| Two tsvector columns + GIN indexes | Private notes can never leak through public/viewer search |
| Partial indexes for the public feed (`published_at`, `popularity_score WHERE visibility='PUBLIC' AND deleted_at IS NULL`) | Fast discovery pages |
| Soft deletes only on `saved_links` | Restore support; grants/invitations are revoked on delete regardless |
| `link_views (saved_link_id, viewer_hash, viewed_on)` unique | One view per viewer per day (abuse-resistant popularity) |

## Delete behaviour

- Deleting a user cascades to their saved links, collections, grants (as grantor/grantee), invitations,
  appeals and views. Before deletion every grant/invitation involving the user is revoked through the
  service so dependants are revoked and audited; notifications are removed; audit rows keep `actor = NULL`.
- Canonical `links` are `RESTRICT`ed while any saved link references them and pruned by the scheduler 30 days
  after the last reference disappears.
