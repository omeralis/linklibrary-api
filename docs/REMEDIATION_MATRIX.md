# Local remediation matrix

Baseline 2026-11-03: API `4125b6fb966e4341988e986d0db70dae9b389ad5`, web `1a06f3876cb779ad18e39d53d249d5cba6711b7a`; both main branches clean. RTK.md is not present in the workspace. No VPS connection or GitHub settings mutation is authorized for this task.

| Finding | Severity / root cause | Files / proposed fix | Required evidence | Manual production requirement |
|---|---|---|---|---|
| Mutable release source | CRITICAL; trusted clone of writable tree | ll-release + ll_release.py; immutable manifest, descriptor-based validation/copy, omit runtime state, reject unsafe ownership/mounts/links | Linux ownership, traversal, archive/race/retention tests | Reviewed bootstrap seal/reconstruction, install helpers; no automatic correction |
| Incomplete recovery | HIGH; restart outside rollback handler | Release state machine; single recovery path for errors/signals/finalization | Restart/smoke/finalization/signal/recovery-failure tests | Install new helpers; rehearse in staging |
| Weak readiness | HIGH; all health failures treated as warnings | Private CLI readiness + mandatory service/API/locale gates, operational warning separate | Positive/negative gate tests | Install private PHP helper; verify runtime config without printing secrets |
| Deployment governance | HIGH; push trigger plus missing rules | Manual exact-SHA workflow, literal authorization, production environment, tests/build prerequisites | Workflow contract tests | Protect main, required reviewers, no bypass; leave DEPLOY_ENABLED absent |
| Missing OpenAPI | MEDIUM; deletes docs wholesale | Packaging allowlist preserves only docs/openapi.yaml | Archive regression + endpoint contract | None beyond future approved artifact |
| Mutable actions | MEDIUM; major tags | Verified upstream commit pins, documented versions | Official upstream git ref/tag verification + workflow tests | None; review Dependabot updates |
| Rollback credentials | LOW; cleanup after fallible command | Separate always-cleanup step and shell EXIT cleanup | Positive/negative workflow contract tests | None |
| Unverified production build | MEDIUM; build inside disabled deploy job | Independent build, Ubuntu 22.04-compatible runner, artifact validation | Build, package and runtime smoke | Actual VPS compatibility remains a manual check |

Dependencies: filesystem safety precedes all root execution; private readiness and recovery precede activation; independent build precedes controlled deployment. Production policy flags and application behavior remain unchanged. The sole existing assertion correction was explicitly approved after Git history and runtime evidence proved its message obsolete. Final outcomes and limitations are recorded in REMEDIATION_REPORT.md after execution.
