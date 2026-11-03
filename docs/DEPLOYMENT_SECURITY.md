# Deployment security design and limits

Local remediation 2026-11-03; not installed in production. The earlier claim of immutable production code was disproved by the audit. The audited active release is not a trusted clone source merely because it exists.

## Root trust boundary

Install the fixed ll-release wrapper and isolated Python module only in root-controlled tools. The wrapper clears interpreter/library injection variables and uses `/usr/bin/python3 -I`; no release code is imported by root. Production ROOT is fixed; fixture root injection is an import-only test interface.

Every immutable source inode is validated before copying: root owner, no group/other writes, regular single-linked files, safe symlinks, no unexpected mount/device/special inode and exact root-protected manifest hashes. Code directories 0755, code files 0644/0755, root controlled; runtime only reads/executes them. Shared env is root:linklibrary 0640. Four exact shared-state symlinks are allowed. Only Laravel bootstrap/cache and Next .next/cache (2770) plus shared storage are runtime writable. Cache boundaries are checked but contents are neither trusted nor copied by root. Child commands use umask 0027 so cached secrets are not world-readable.

Root snapshots an incoming archive through a single nofollow FD into private staging, hashes that snapshot and validates all archive members before manual extraction. Traversal, duplicate entries, symlink chains/escapes/children, special files, hard links, privileged bits, environment/cache entries and size limits are rejected. Immutable bytes are copied from validated descriptors and rehashed; ownership/modes are normalized. Descriptor-relative cleanup never follows symlinks or crosses a device/mount boundary. No recursive chown/chmod on live mutable source is used.

Manifest provenance is not a magic signature: its trust derives from a reviewed artifact/source reference and root-controlled tree. An administrator must not generate a trusted reference from unverified live mutable bytes. Repair refuses mismatched bytes; missing authentic reference requires a separately approved bootstrap reconstruction from a reviewed API/web pair. Root compromise, hostile kernel or a second hostile root administrator are outside this boundary.

## Transaction and recovery

The journal records original/candidate, operation/component/commit/digest, activated, restart attempted, completed, recovery-required, credential state, failure type and timestamp. Any failure after activation, including restart, mandatory readiness, signals and finalization, attempts one recovery to the validated original. Recovery restarts only LinkLibrary services and repeats mandatory checks. Failed recovery remains a failed command with preserved root-only evidence; it never prints success. Failed candidates are retained for diagnosis. Retention preserves original/current and recent releases and validates before descriptor-safe deletion.

Python exception/finally handling implements EXIT-equivalent cleanup; INT/TERM/HUP raise into it. Child process groups terminate before credential cleanup. During recovery subsequent catchable signals are ignored to avoid recursive rollback. SIGKILL, power loss or kernel failure cannot execute cleanup; an unfinished journal blocks further deployments and needs explicit manual recovery. There is no database down migration. Password reset retries and expiry limit the temporary migrator login; possible credential state is recorded before setting it. Passwords travel through stdin/environment, never argv/log output. PostgreSQL/pgaudit policy must be reviewed separately so statement auditing cannot capture ALTER ROLE secrets; do not disable global production auditing as a workaround.

## Mandatory readiness vs warnings

Four units must be active: linklibrary-api, linklibrary-web, linklibrary-queue, linklibrary-scheduler.timer. Root-installed private CLI PHP readiness checks actual PostgreSQL connection/database/runtime role plus production/debug/key, secure database sessions, same HTTPS origin/CORS/Sanctum, database queue/cache and strict sharing policy. It returns booleans only. TLS-verified `/up`, JSON-success public categories, and lang-correct EN/AR pages must pass. These failures trigger recovery. Existing public `/up` is unchanged; it never exposes DB/config secrets.

Historical failed jobs, old backlog, scheduler freshness and certificate approaching expiry remain operational warnings through health.sh; current TLS verification and required units are still mandatory. No security provider, SMTP or AI provider is enabled by this work, and UNKNOWN links stay restricted.

## Remaining trust and deployment gates

Protect main and production reviewers before enabling CI deployment. Separate build and SSH jobs reduce credential exposure but do not make malicious approved tooling safe. Validate actual PHP wrapper/private GD, Node 22 runtime/native dependencies, PostgreSQL 14 grants/SET ROLE behavior, certificate trust/renewal, filesystem mounts/ACLs and service users in approved staging. Portable PHP/fixture PostgreSQL tests do not establish those production facts. Backup storage remains server-local according to the historical audit; off-host disaster recovery is not established here.
