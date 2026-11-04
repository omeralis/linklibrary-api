# LinkLibrary CI/CD — local remediation, deployment disabled

Updated 2026-11-03. These changes were prepared and verified locally; local commits do not activate deployment. No workflow was dispatched, settings changed, secret installed, VPS contacted, or release activated in this remediation. See [REMEDIATION_REPORT.md](REMEDIATION_REPORT.md) for blockers and exact test results.

## Execution and authorization

`ci.yml` runs on all pushes and PRs. Reusable `_test.yml` runs the existing strict/temporary **test-only** security matrix against disposable PostgreSQL. API security tests run separately as root inside disposable Linux fixtures. Production artifact builds run independently of deployment enablement.

`deploy.yml` runs on every push to `main` (merge) and on `workflow_dispatch` (rollback only needs it). A push deploys only if the repository variable `DEPLOY_ENABLED` is exactly `true`; with it unset the workflow is inert. Tests, deployment-security tests and the build run without secrets; the `production` environment (restricted to `main`) and the SSH key (`LL_SSH_USER` must be `llci`) exist only in the final deploy job. The workflow checks that `github.sha` equals origin/main. Manual rollback needs `action=rollback`, an explicit release ID and the literal `rollback linklibrary-api` (web: `linklibrary-web`). Web also needs the variable `LL_PUBLIC_ORIGIN`. Protect `main` with required reviews and CI, because merging now deploys. The one-time admin bootstrap (docs/BOOTSTRAP_DEPLOYMENT.md) and installing the updated server files must be done first, and the VPS needs more than 4.5 GB of free disk. API and web deploys queue behind each other (up to 15 minutes) instead of failing. Leave `DEPLOY_ENABLED` unset until the first release is approved.

Deployment requires successful tests, security harness (API), and artifact build. Build jobs receive no production environment or SSH secrets. The separate production-environment job downloads an opaque artifact, validates SHA256/component/commit/Ubuntu baseline (and web public origin), and only then loads SSH secrets in one restricted step. It never runs Composer/npm or code from inside the artifact. The approved checkout still supplies executable deployment tooling: review it and protect main.

Both repositories use `contents: read`, no persisted checkout credentials, immutable upstream action commits recorded in `deploy/ACTION_PINS.json`, production concurrency with cancellation disabled, and a shared server-side nonblocking flock. Repository concurrency alone does not serialize two repositories; a contending server deployment fails safely and must be retried after review.

## Artifact contract

Ubuntu 22.04 builds match glibc 2.35; web pins Node 22.23.3. Locked installs and platform checks precede packaging. Archive metadata records component/full SHA/platform/libc/public origin. Packages omit environment files, tests, deployment tooling and writable caches. API retains only `docs/openapi.yaml` from docs. Web validates required Next runtime/static files, scans static assets for a private fixture canary, and starts the extracted archive for EN/AR smoke checks. Do not build an artifact on Windows for production or transport Ubuntu 24.04 native modules blindly.

Independent web CI uses reserved `https://linklibrary.invalid`, never production secrets. The manual web build requires the reviewed HTTPS `public_origin`; the protected job compares it with production `LL_PUBLIC_ORIGIN`. Only NEXT_PUBLIC values belong in client code. Server private keys are not needed for building. Native library and actual VPS runtime compatibility must still be checked in staging before enabling deployment.

## Restricted SSH

Production secrets: `LL_SSH_PRIVATE_KEY`, `LL_SSH_KNOWN_HOSTS` (independently verified host key). Production variables: `LL_SSH_HOST`, `LL_SSH_PORT`, `LL_SSH_USER=llci`; web also `LL_PUBLIC_ORIGIN`. Do not install or change these in this task. Dedicated forced-command ll-ingest accepts only exact upload/deploy/status/explicit rollback grammar. No arbitrary shell, scp, forwarding, PTY or general sudo. See sudoers and server source. SSH uses pinned known hosts, strict checking and no host-key updates.

Temporary key/known-host files live under RUNNER_TEMP. Shell EXIT/INT/TERM/HUP cleanup plus an `if: always()` job backstop remove them after success/failure. SIGKILL/runner destruction cannot execute traps; ephemeral runners and destruction of their temporary disk remain part of the trust model. Artifacts never contain keys.

## Manual GitHub UI prerequisites — BOTH repositories

1. Settings → Rules → Rulesets → New branch ruleset, target `main`, active enforcement. Require a pull request, at least one independent approval, dismiss stale approvals, require approval of the most recent reviewable push and resolve conversations. Restrict bypass (including administrators); do not add a broad bypass actor.
2. Require status checks and an up-to-date branch. After CI has actually run, select the exact contexts GitHub reports, rather than inventing contexts: API reusable `test / test (strict policy)`, `test / test (temporary policy)`, `build`, `deployment-security`; web reusable `test / test`, `build`. If GitHub displays a workflow prefix, select that actual context. No run has been created by this task to populate them.
3. Block force pushes and deletion; prohibit direct main pushes through the PR requirement. Keep merge history policies consistent with repository practice; this task does not rewrite history.
4. Settings → Environments → production: selected deployment branch `main` only, required independent reviewers, prevent self-review and disable administrator bypass where supported. Confirm both deploy and rollback jobs name this environment.
5. If the repository plan does not support reviewer protection, keep deployment disabled until an equivalent independently approved governance control is designed. The boolean variable is not a substitute for reviewers/protected main.
6. Record screenshots/exported rule IDs and an actual confirmation of enforcement before claiming protection active. Secret rotation/configuration, enabling deployment and first dispatch each require separately approved work. None happened here.

## First approved deployment / rollback

Use [VPS_MANUAL_REMEDIATION.md](VPS_MANUAL_REMEDIATION.md) first. An unmanifested/mutable current release is deliberately rejected before migration. Review forward-only schema compatibility and fresh backups; retain original/candidate evidence. Only after local regression blockers, staging, root helper installation, source provenance repair and GitHub protections are verified should an administrator authorize an explicit full-SHA manual deployment. Monitor the root-only transaction journal and mandatory readiness gates. Post-deployment checks include trusted TLS, DB identity, four units, public API, both locales, session/CSRF behavior and unchanged existing sites.

Explicit workflow rollback restores an immutable, schema-compatible release pointer and restarts only LinkLibrary API/web/queue. It does not revert schema. Emergency journal recovery and metadata restoration are documented separately; never delete the journal to bypass an unfinished transaction.
