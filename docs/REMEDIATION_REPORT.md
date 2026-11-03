# CI/CD Security Hardening & Deployment Readiness Remediation

Date: 2026-11-03 (Asia/Riyadh). Scope: local source, fixtures, workflows and documentation only.

## A. Executive summary

**Decision: READY FOR MANUAL VPS REMEDIATION**, subject to the staging, provenance and administrator prerequisites below. Final local pre-commit review passed. The E2E failure was an outdated expectation retained after translation commit `7c828b6`; the user explicitly approved the exact one-line assertion correction. The fixed journey passes 10/10 and the complete mock suite passes 22 tests with one optional screenshot test skipped. Application behavior and security policies are unchanged. This is not production deployment readiness or permission to change the VPS.

No VPS connection, production file/permission/service/database/certificate mutation, GitHub settings/secrets mutation, workflow dispatch, push, deployment/rollback or enabling DEPLOY_ENABLED occurred. No production security flag was changed. Existing omerali.dev, api.omerali.dev, Strapi and PM2 were untouched. Official upstream action refs/downloads were verified read-only.

Baseline: both main branches were clean, API `4125b6fb966e4341988e986d0db70dae9b389ad5`, web `1a06f3876cb779ad18e39d53d249d5cba6711b7a`. No pre-existing uncommitted work existed to overwrite. AGENTS.md was observed; referenced RTK.md was absent. The previous readiness audit and actual original scripts/workflows were reviewed before implementing fixes.

## B. Findings matrix

Status below describes **local source remediation**, not installation or enforcement in production. Detailed dependencies/root causes and manual requirements: [REMEDIATION_MATRIX.md](REMEDIATION_MATRIX.md).

| Finding / original severity | Verified cause | Implemented files / correction | Evidence | Status |
|---|---|---|---|---|
| Immutable releases / CRITICAL | Blind reuse of mutable live source; unsafe root recursive operations | deploy/server/ll-release + ll_release.py, admin/repair_permissions.py; whole-tree immutable manifest validation, descriptor-based copying/deletion, archive snapshot/digest validation, strict writable boundaries | Linux ownership/links/mounts/races/retention/write-denial tests | FIXED locally; production correction blocked on authentic reference and approved maintenance |
| Incomplete rollback / HIGH | Restart/finalization outside recovery scope | ll_release.py + admin/recover_release.py; journaled single recovery on all postactivation errors/signals, explicit power-loss recovery | Restart, mandatory gates, finalization, SIGTERM, failed recovery, unfinished journal tests | FIXED locally |
| DB/API readiness / HIGH | Critical health failures treated as warnings | ll-readiness.php + ll_release.py; private booleans for DB identity/security, mandatory four units/API/TLS/locales, warnings separate | PHP readiness positive/negative tests (including unexpected extra CORS/Sanctum origins) plus actual command-adapter negative gates | FIXED locally |
| Governance / HIGH | Automatic push deployment path; no main/reviewer protection | Both ci.yml/_test.yml/deploy.yml; manual full-SHA/literal authorization, independent builds without secrets, protected download/SSH job, least privilege/flock | PHP 2 governance tests (52 assertions), web 3 governance tests included in 89 | PARTIALLY FIXED; GitHub protection/review settings intentionally not applied |
| OpenAPI / MEDIUM | docs deleted from API artifact though route needs spec | package_release.py, build-api.sh, OpenApiPublicationTest.php; keep only docs/openapi.yaml | Packaging and full API regression/contract suite | FIXED locally |
| Mutable actions / MEDIUM | Action major tags mutable | Both workflow sets and ACTION_PINS.json; upstream-verified commit pins, version comments | Official git ls-remote refs; governance checks all pins | FIXED locally |
| Rollback SSH cleanup / LOW | Cleanup skipped when SSH fails | Both ssh-operation.sh/cleanup-ssh.sh and deploy.yml always backstop | Mock failed-SSH cleanup + workflow assertions | FIXED locally |
| Production web build / MEDIUM | Build hidden behind disabled deploy; Ubuntu24/native mismatch | Independent build, Ubuntu22.04/Node22.23.3, build-web.sh, shared packager/verifier | Windows and Ubuntu22 production build, 89 Linux unit tests, package and extracted EN/AR runtime smoke | PARTIALLY FIXED pending actual VPS/native runtime validation |

Additional separation: production credentials never enter Composer/npm build jobs. The production job validates the opaque downloaded artifact without extracting/importing app code, then loads SSH credentials only in its SSH step. Review trusted tooling on main remains required.

## C. Backend results

Tests used portable official PHP 8.4.27 and a dedicated local PostgreSQL 16 container, bound only to 127.0.0.1:54150, database linklibrary_test and fixture-only ll_test role. Actual test database/role were checked before destructive fixtures. No production credentials/data/services were used. After both final suites completed, the exact disposable PostgreSQL container was identity-checked and removed; other containers/services were left intact.

| Check | Result |
|---|---|
| Full strict suite | 262 tests, 1,417 assertions, 1 skipped; no failures |
| Full temporary-policy suite | 262 tests, 1,417 assertions, 1 skipped; no failures |
| Skip | Existing ConcurrentRevocationTest requires pcntl; Windows PHP has no pcntl. Linux CI must execute it; not claimed verified locally. |
| Laravel Pint | Full repository `--test` passed |
| PHPStan | Full configured app analysis passed |
| Composer production dependency audit | No security vulnerability advisories |
| OpenAPI/private readiness tests | API and frontend specs have identical SHA256 ea652ee94495a68dcaeb2f72d483fe6e210280d8091fc276f9dfdcefdc983609. Included in both full suite runs; no public /up changes |
| Workflow governance after final job split | 2 tests / 52 assertions passed |

Temporary sharing-policy flags were used only in the disposable test process as the existing CI matrix requires. They were never applied to production. New tests cover private readiness invalid configuration, wrong database/role and secret-safe exception handling. No pre-existing assertions were weakened.

## D. Frontend results

| Check | Result |
|---|---|
| i18n | 1,092 keys per locale; 785 static references; passed |
| TypeScript/ESLint/Vitest | Final npm run check passed; 9 files, 89 tests |
| Production dependency audit | No vulnerabilities |
| Windows production build | Passed, 47 generated pages, reserved public origin, loopback unavailable API fixture; canary absent from browser static assets |
| Isolated mock browser E2E | Before fix: 16 passed, 1 failed, 1 skipped, 5 did not run. After approved correction: 22 passed, 1 optional screenshot test skipped, no failures (23 total); journey-only: 10 passed. |
| Failing E2E | Existing e2e/mock/journeys.spec.ts:116 expects text matching `couldn't be verified by a security provider`; actual UI says `This destination has not been security-verified, so it stays private…` and `This link can't be published or shared yet…`. Disabled publish/invite assertions passed; message lookup failed. Git history confirms the expected message was removed in commit 7c828b6 while this assertion still dated from cc8987a. The user approved replacing only this assertion with exact `sharing-restriction` text verification. The application, translations, existing disabled-control assertions and security flags remain unchanged. Trace after the fix confirms PRIVATE/UNKNOWN, can_publish=false, can_share=false, and disabled publish/recipient/Invite/resharing controls. The entire serial journey now completes. |
| Browser setup | Bundled Playwright Chromium was absent. A temporary config outside both repositories selected installed Chrome and existing isolated mocks; existing Playwright config/e2e/src were unchanged. |
| Ubuntu22.04/Node22.23.3/glibc2.35 | PASS locally: npm ci, TypeScript, ESLint, 89 unit tests, production build, validated archive (253,007,766 bytes), private-canary scan, and extracted runtime EN/AR GETs. Working-tree fixture metadata used an all-zero SHA, never published/deployed. Final local validation receipt exit code 0;  Actual VPS runtime: NOT VERIFIED. |

The mock browser run used its local API fixture, never the production database. Runtime native cache permissions initially prevented a sandboxed Windows repeat; the same local check passed with user access, without dependency/source changes. Initial Linux installation attempts hit DNS/registry idle timeout errors. A test-process-only DNS lookup override resolved registry.npmjs.org to independently resolved IPv4 addresses, preserving HTTPS and lockfile integrity; no system DNS/network policy changed. A subsequent full Linux build/package/runtime run passed. The override was removed from NODE_OPTIONS before check/build and was not packaged.

## E. Isolated security harness

Final run: **44 tests passed** using WSL Ubuntu22.04, root-owned fresh /tmp fixture trees, dropped runtime UID and mocked service/DB command adapter. Production CLI remains fixed to /srv/linklibrary; tests import an isolated root. No real service stop/restart/SQL migration occurs in this harness. Packaging: 3 tests passed. An outside-tree sentinel is checked after every security fixture.

| Required scenario | Outcome / evidence |
|---|---|
| 1 Writable code | PASS; rejected before backup |
| 2 Writable release directory | PASS; app-owned/group-writable parents refused |
| 3 Unsafe symlinks | PASS; source escape and incoming link refused |
| 4 Symlink chains | PASS; source/archive chains refused |
| 5 Path traversal | PASS; archive traversal refused |
| 6 Malicious archive entries | PASS; device/FIFO/hardlink/escape refused |
| 7 Duplicate entries | PASS; archive rejected |
| 8 Wrong SHA256 | PASS; fails before activation |
| 9 Replacement races | PASS; before-open mismatch refused, after-snapshot replacement cannot alter release |
| 10 Backup failure | PASS; no activation |
| 11 Migration failure | PASS; original current preserved |
| 12 Credential cleanup | PASS; failure/signal/reset paths exercised |
| 13 Cache failure | PASS; no activation |
| 14 Restart failure | PASS; original restored/rechecked |
| 15 Mandatory DB failure | PASS; private probe and actual command adapter failures gate deployment |
| 16 API failure | PASS; /up and public API success required |
| 17 Frontend failure | PASS; EN/AR response/language gates |
| 18 Recovery failure | PASS; nonzero, diagnostics preserved, no success |
| 19 SIGTERM during migration | PASS; credential cleanup, no activation |
| 20 SIGTERM after activation | PASS; single recovery |
| 21 Retention | PASS; no link-following/original deletion |
| 22 Root immutability | PASS; actual dropped UID cannot write code, can write legitimate cache |
| 23 Restricted SSH grammar | PASS; valid and invalid exact command cases |
| 24 Sudoers compatibility | PASS; real visudo validation and wrapper/parser contract |
| 25 Outside-tree safety | PASS; sentinel unchanged after every case |
| Successful API and web deployment | PASS; web preserves API/skips migration; API executes correct gates |
| Finalization failure / journal blocking | PASS; recovery and explicit admin requirement |
| Admin correction / restoration | PASS; trusted reference required, mismatch no mutation, caches/shared excluded |
| Manual journal recovery | PASS; approved digest/original required, preserved evidence |
| Secret file creation / SSH cleanup | PASS; child umask 0640 and failed-SSH temporary-file cleanup |

These validate filesystem/state-machine logic, not PostgreSQL14 grants, real systemd or a real power failure. No SIGKILL/power-loss cleanup claim is made.

## F. GitHub Actions readiness

No workflow run was initiated and no repository protection state was re-queried or changed. The historical audit's missing protections remain outstanding until an administrator confirms enforcement. Follow exact UI steps in [CICD.md](CICD.md) for both repositories: main PR/review/checks, block force-push/deletion, main-only production, independent required reviewers/no bypass. If required-reviewer protection is unsupported, keep disabled pending an equivalent approved control. DEPLOY_ENABLED stays absent. Native Ubuntu22 CI and the pcntl case must pass on an actual reviewed run before enabling production. Pins are verified upstream commit hashes, including the peeled setup-php tag commit; no invented pins.

## G. VPS manual remediation

See [VPS_MANUAL_REMEDIATION.md](VPS_MANUAL_REMEDIATION.md) for prerequisites, root-only evidence/config backups, exact install commands, expected hashes in deploy/SERVER_SHA256SUMS, stopped-writers plan/apply/restore and explicit journal recovery. Nothing was installed. The mutable audited release must not be trusted or automatically sealed. Authentic matching reference unavailable => STOP and separately review reconstruction from a trusted API/web artifact pair. Preserve APP_KEY/shared storage; no broad chown/chmod or down migrations. The procedure deliberately requires administrator maintenance approval and does not touch unrelated infrastructure.

## H. Git review and full file list

The pre-commit baseline HEADs are recorded above. The user authorized separate normal local commits after final review; commit IDs are returned in the task completion message (a report cannot embed its own commit hash). No push, history rewrite, author change or hook bypass is authorized. The earlier E2E commit blocker is resolved with explicit assertion-change approval. Final diff checks passed in both repositories; credential signature scan found no matches, Python/Bash syntax and server checksum registry verify. Existing app/config/routes and frontend src/Playwright config are unchanged. The only existing E2E change is the approved one-line assertion correction. Full changed/new file inventory follows; paths are relative to each listed repository.

### API

```text
 M .gitattributes
 M .github/workflows/_test.yml
 M .github/workflows/ci.yml
 M .github/workflows/deploy.yml
 M deploy/server/ll-ingest
 M deploy/server/ll-release
 M deploy/server/sudoers-linklibrary-deploy
 M docs/CICD.md
 M docs/VPS_DEPLOYMENT.md
 M docs/VPS_DEPLOYMENT_REPORT.md
?? deploy/ACTION_PINS.json
?? deploy/SERVER_SHA256SUMS
?? deploy/admin/recover_release.py
?? deploy/admin/repair_permissions.py
?? deploy/build-api.sh
?? deploy/cleanup-ssh.sh
?? deploy/package_release.py
?? deploy/server/ll-readiness.php
?? deploy/server/ll_release.py
?? deploy/ssh-operation.sh
?? deploy/tests/Dockerfile.php
?? deploy/tests/test_packaging.py
?? deploy/tests/test_release.py
?? deploy/verify-artifact.py
?? docs/DEPLOYMENT_SECURITY.md
?? docs/REMEDIATION_MATRIX.md
?? docs/REMEDIATION_REPORT.md
?? docs/VPS_MANUAL_REMEDIATION.md
?? tests/Feature/Api/OpenApiPublicationTest.php
?? tests/Unit/Deployment/DeploymentReadinessTest.php
?? tests/Unit/Deployment/WorkflowGovernanceTest.php
```

### Web

```text
 M .gitattributes
 M .github/workflows/_test.yml
 M .github/workflows/ci.yml
 M .github/workflows/deploy.yml
 M docs/CICD.md
 M e2e/mock/journeys.spec.ts
?? deploy/ACTION_PINS.json
?? deploy/build-web.sh
?? deploy/cleanup-ssh.sh
?? deploy/package_release.py
?? deploy/ssh-operation.sh
?? deploy/verify-artifact.py
?? tests/unit/deployment-workflows.test.ts
```

## I. Remaining risks and evidence boundaries

- Production filesystem immutability/provenance is not corrected until approved installation and authentic-reference repair/reconstruction. Existing live paths/ACLs/mounts and runtime identities need administrator validation.
- Real PostgreSQL14 migration role inheritance, auditing/password exposure controls, password cleanup and backup restore require staging rehearsal. The harness mocks these commands; Windows integration used PostgreSQL16.
- Actual VPS Node/native modules, PHP wrapper/private GD, service memory, TLS validity/renewal, secure auth/session/CSRF and restart/recovery must be verified after separately approved work. No current health attestation is made.
- Catchable signal recovery cannot handle SIGKILL/power loss. The journal/manual recovery path is necessary. Forward migrations can make old code incompatible; no automatic schema rollback.
- E2E mismatch is resolved. The existing pcntl concurrency test remains skipped on Windows and must run in Linux CI before any production activation; no complete production integration claim is made.
- No provider configuration, SMTP activation, external monitoring or off-host disaster-recovery storage was changed. UNKNOWN links remain restricted.
- Local tools/downloads/diagnostics under parent deployment/local-tools are not part of either repository or deployment artifact. Official portable PHP/Node downloads were hash verified. No production env was packaged. Local final receipts: parent deployment/local-tools/php-final-false.log, php-final-true.log, security-final.log, linux-web-final.log and linux-web-final.status (0). Test-only Dockerfile.php is an optional local fixture; its image build was cancelled after download stalled, not claimed successful.

## J. Final decision

**READY FOR MANUAL VPS REMEDIATION.** Local source review and essential checks pass; the approved assertion fix passes the journey and complete affected mock E2E suite. Pre-commit rechecks: 44 Linux security tests, 3 packaging tests, 7 PHP deployment tests / 81 assertions, full Pint, script syntax, checksum verification and diff checks pass. Frontend check: 89 tests plus TypeScript/ESLint/i18n passed; backend strict and temporary full suites: 262 tests / 1,417 assertions each, one pcntl skip each. Affected backend security/sharing tests: 41 tests / 406 assertions, one pcntl skip, no failures. No unresolved local critical test failures were found. Separate local commits are authorized; no push or deployment. GitHub protections, authentic-reference VPS repair/reconstruction, Linux pcntl/staging and real runtime checks remain mandatory prerequisites for separately approved production work.
