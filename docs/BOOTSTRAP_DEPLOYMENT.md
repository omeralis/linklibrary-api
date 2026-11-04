# One-time first trusted paired deployment

This administrative path is deliberately separate from llci. No sudoers, GitHub settings, Nginx, PM2, PostgreSQL configuration, or automatic-deployment changes are required. Do not add bootstrap to ll-ingest or ll-release. Application sources are fixed to API `8f43fe9cc6ef1a2327400ef4726aea7e59254bfb` and web `71994b4f55a0fcc8c3fa92bd083a8eedb33058b3`; public origin is `https://31.172.87.248:8080`.

## Artifacts and trust

Build fallback and candidate separately from exact Git archives and locked dependencies. Do not copy vendor, node_modules, generated output or application files from the live release or between the two builds. Use Ubuntu 22.04/glibc 2.35, PHP 8.4 with required extensions, and Node 22.23.3. Use the reviewed packaging fix excluding all API storage, not the original packager that includes storage scaffolding. Record its SHA256 separately: application source SHA and deployment-tool version are distinct.

For each of four archives record component, exact commit, artifact SHA256, build identity, source archive digest, lockfile digest, build runtime and successful checks in a `.tgz.build.json` file. `plan.json` pins these record digests, artifacts, distinct release IDs and production origin. Review the evidence outside the VPS before approving the plan digest. A self-declared artifact commit or `verified` field alone is not provenance; root's approval of independent source/build evidence is the trust boundary. Bootstrap snapshots only root-controlled regular, non-hard-linked input files and rejects package traversal, secrets/env files, caches, shared state, wrong metadata/origin, missing runtime files and incompatible platform.

The fallback is an independent build of the SAME approved source pair, not the legacy application and not a duplicate artifact. This supports recovery from deployment/restart/build-output failures. It does not undo a deterministic application defect present in both builds. Do not claim semantic version rollback or guaranteed availability after an underlying service/host failure. If either independently built pair fails private API/frontend/database preflight, there is no approved fallback and activation must stop.

## Installation boundary

Install root:root 0644: `ll_release.py`, `ll-readiness.php`, `ll-bootstrap-check.php`.
Install root:root 0750: `ll-release`, `bootstrap_release.py`, `recover_release.py`.
Install root:root 0755: `ll-ingest`.
Reuse and verify existing root-controlled `tools/php` and `health.sh`; never overwrite them. Python 3.10+ and Node 22 are required. Do not install the included reference sudoers or run permission-repair helpers. Keep the old wrapper backup as tool-byte recovery only, never as trusted application recovery.

Use an interactive authorized root session. Verify independently recorded tool/artifact archive hashes, unpack into NEW root-only directories, check `deploy/SERVER_SHA256SUMS`, inspect all tools-directory ancestors, and preserve old tools outside releases before replacement. Coordinate no concurrent administrative deployment and hold BOTH legacy `/run/ll-release.lock` and hardened `/srv/linklibrary/.ll-release.lock` during tool replacement. Stage dependencies before replacing the wrapper/ingest using atomic same-directory renames. Verify installed bytes/modes, then release installation locks before prepare (which acquires the hardened lock itself). Exact commands and actual bundle hashes are in the generated external `deployment/bootstrap-build/VPS_COMMANDS.md`; production installation/activation were not executed by this implementation task.

## Prepare (does not switch or migrate)

```bash
python3 -I -B /srv/linklibrary/tools/bootstrap_release.py prepare \
  --plan /root/linklibrary-bootstrap-input/plan.json \
  --plan-sha256 APPROVED_PLAN_SHA256
sha256sum /srv/linklibrary/.ll-bootstrap.json
```

Prepare requires current still points to `20261102-01`, no previous bootstrap ledger and no transaction journal. It never inventories/clones/seals/chmods that legacy tree. It builds and seals BOTH new root-owned release trees from independently verified archives, creates only the two new runtime cache boundaries, and links the existing shared env/storage unchanged. It does not delete existing releases, uploads, backups or failed diagnostic candidates.

Each tree must pass caches, exact private production security configuration, actual database/user identity, zero pending migrations, schema fingerprint, loopback API `/up` and categories JSON, and loopback frontend EN/AR checks. Preview processes are temporary process groups under linklibrary, bound only to ports 3196/3197, sequentially, and cleaned up on failure/signals. No persistent services or configs are added. The schema gate runs its SQL inside a read-only transaction and never executes migration files.

Prepare is one-time and fail-closed. Failure preserves ledger/staging evidence and blocks blind retry. It may leave new unpublished releases; preserve and review them with an administrator rather than deleting state or blessing legacy bytes.

## Activate (separate explicit command, only after successful prepare)

Read/review the root-only prepared ledger, preserve its SHA256 outside the VPS and confirm the fallback preflight/schema evidence. Keep LinkLibrary-specific deployment/DB-maintenance operations serialized. No migration is permitted in this bootstrap.

```bash
python3 -I -B /srv/linklibrary/tools/bootstrap_release.py activate \
  --ledger-sha256 APPROVED_PREPARED_LEDGER_SHA256 \
  --confirm-independent-builds-and-no-migrations
```

Activation rechecks both manifests, shared env trust, private health and unchanged database schema. It requires a fresh existing LinkLibrary backup service success before changing current. A root-fsynced transaction journal records the TRUSTED fallback as recovery original and records legacy separately as historical evidence. Current is atomically replaced only then. Only linklibrary-api, linklibrary-web and linklibrary-queue are restarted; scheduler.timer is verified, not reconfigured. Mandatory post-switch checks include all four units, private security/DB identity, trusted TLS /up, categories success JSON, and EN/AR locale HTML. Failure/signals after activation attempt recovery to trusted fallback and repeat health checks; recovery failure is fatal and preserved, never reported as success.

## SIGKILL/power-loss or failed automatic recovery

Do not delete journal/ledger, rerun bootstrap, invoke down migrations or restore the legacy pointer. Verify the journal hash and trusted fallback manifest, confirm no schema changes occurred, then use the existing root recovery helper:

```bash
sha256sum /srv/linklibrary/.ll-transaction.json
python3 -I -B /srv/linklibrary/tools/recover_release.py \
  --original 20261104-090001-api-8f43fe9 \
  --journal-sha256 APPROVED_JOURNAL_SHA256 \
  --confirm-forward-schema-compatible
```

For bootstrap journals, recovery does not alter the migrator password because bootstrap never sets it. It also runs the private read-only schema gate and requires an exact match with the journal's schema fingerprint before switching. If schema changed or fallback integrity/health fails, STOP and escalate; do not claim safe rollback. Database archive restoration is a separate explicitly reviewed procedure, not an automatic action here.

After successful bootstrap or recovery the hardened normal release engine can use the trusted current. Subsequent manual GitHub workflows need the reviewed packaging fix committed/approved before they build deployable API packages. No automatic events or deployment flags are enabled by this change.
