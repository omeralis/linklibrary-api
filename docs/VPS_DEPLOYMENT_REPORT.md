> 2026-11-03 audit correction: this report records earlier deployment actions only. The later audit disproved code immutability. See REMEDIATION_REPORT.md and VPS_MANUAL_REMEDIATION.md. Local remediation is not installed; this task has not verified current production health.

# LinkLibrary VPS deployment report

Completed 2026-11-02 (Asia/Riyadh). Status: application services DEPLOYED and verified over trusted IP HTTPS. External provider setup and off-host disaster recovery remain incomplete. This report supersedes the earlier audit/access-blocked status.

## Verified application URLs

- Arabic: https://31.172.87.248:8080/ar
- English: https://31.172.87.248:8080/en
- Arabic login: https://31.172.87.248:8080/ar/login
- English login: https://31.172.87.248:8080/en/login
- Laravel health: https://31.172.87.248:8080/up
- Real public API: https://31.172.87.248:8080/api/v1/public/categories

No domain is required for the current IP certificate. No application login is exposed over public HTTP. Port 8081 is internal only. No development/test account was created in production; production users count was zero at final verification.

## Actual deployed source

| Repository | Exact deployed commit |
|---|---|
| omeralis/linklibrary-api | 79c5832b19317d057c6a8206a608bdf382b826c2 |
| omeralis/linklibrary-web | ec64c5864b9565257ad2f5c8a6d7f97ad777478f |

Both local main branches matched live GitHub main during audit. Tracked source was exported with git archive; transferred archive SHA-256 matched locally and remotely:

- API: b22326cfc13f747f2d616ea22db48e00e34a81a160ccf5f30696ecd26f95094b
- Web: c2068ab6f81e70c2d9859e5b4779d348327badd403c7e58e13d57593464e04f0

Production dependencies were installed on Linux from lockfiles. No local .env, vendor, node_modules or development build was deployed. Source histories were not rewritten, merged or pushed.

## Installed versions and locations

Ubuntu 22.04.5 LTS; PHP 8.4.27; Laravel 13.39.0 from lockfile; Sanctum 4.3.3; Composer 2.11.1; Next.js 16.4.3. Existing Node v22.23.3, PostgreSQL 14.24 and Nginx 1.18.0 retained. New isolated Certbot 5.9.0; existing system Certbot 1.21.0 retained.

Application release: /srv/linklibrary/releases/20261102-01/{api,web}; current symlink /srv/linklibrary/current. Shared data/env under /srv/linklibrary/shared. Dedicated database linklibrary_prod; owner/migrator/runtime roles separated. Runtime schema CREATE privilege was checked and denied. APP_ENV=production and APP_DEBUG=false. New random APP_KEY, DB passwords and private frontend key were generated on server without printing values.

The PHP apt transaction installed 19 new packages and upgraded zero existing packages. PHP GD and image-library packages were extracted into a private application library directory rather than replacing the libgd used by Nginx. Signature-verified repository/Composer installer used. Pre-provision Nginx/renewal/firewall configuration backup exists under /var/backups/linklibrary/pre-provision-nginx.tgz.

## Running services and exposure

| Name | Final state / purpose |
|---|---|
| linklibrary-api | active, enabled; isolated PHP-FPM Unix socket |
| linklibrary-web | active, enabled; production Next, 127.0.0.1:3100 |
| linklibrary-queue | active, enabled; security/extraction/ai/default database queues |
| linklibrary-scheduler.timer | active, enabled; schedule:run every minute |
| linklibrary-backup.timer | enabled; daily 02:10 UTC; manual backups succeeded |
| linklibrary-certificate-renew.timer | enabled; every six hours; dry-run succeeded |
| linklibrary-health.timer | enabled; five-minute checks; latest health run succeeded |

Nginx adds explicit public 31.172.87.248:8080 TLS and loopback :8080, plus loopback API :8081. Node and PostgreSQL remain loopback only. FPM uses /run/linklibrary/api.sock. UFW adds only TCP 8080. Existing 22/80/443 rules remain. Temporary auth-test TLS listener :8444 and process were stopped/removed after testing.

Code is root-owned. Runtime writable paths are scoped to required caches/storage. Shared secrets and cached API config are restricted. Exact private secret values were scanned against public Next static assets and were absent. No secrets are included in this report.

## Actual verification evidence

| Check | Result |
|---|---|
| Administrative access | sudo -n true returned 0; root sudo scope confirmed |
| Existing Nginx config | nginx -t PASS before and after changes |
| Existing site files | SHA-256 matches pre-provision copies for both original site files |
| Existing websites | Both followed redirects to HTTPS 200 before and after deployment; TLS validation succeeded |
| Existing PM2 | omerali-api and omerali-web online, restart counters remained zero |
| Existing Strapi DB | No mutations or deployment commands targeted omerali_strapi |
| Migrations | All 10 forward migrations ran on verified linklibrary_prod / owner role; none pending |
| Composer platform | All production requirements passed |
| Composer audit | No production dependency advisories found |
| npm production audit | 0 vulnerabilities |
| Backend suite on VPS | 231 tests, 1243 assertions PASS; separate OS user and linklibrary_deploy_test |
| Frontend suite on VPS | 8 files / 81 tests PASS |
| Frontend checks | i18n 1083 keys per locale, TypeScript and ESLint PASS |
| Production build on VPS | npm ci and Next production build PASS, 47 pages; actual HTTPS production URLs |
| Laravel doctor --network | 0 failures, 2 expected warnings (mail and reputation provider); outbound HTTPS 200 |
| Scheduler | Fresh database-cache heartbeat observed across multiple minutes |
| Queue | Real RecalculatePopularityJob dispatched to database queue, worker reported RUNNING then DONE in 24 ms; queue and failed_jobs counts zero at final check |
| Storage | Private disk write/read/delete probe PASS; public storage symlink present |
| Backup | Custom DB archive + storage tarball + encrypted environment archive created; manual backup runs succeeded |
| Restore | Real restore into isolated linklibrary_restore_verify PASS: 26 public tables, 10 migrations, zero users matched source snapshot |
| TLS issuance | Lets Encrypt staging then trusted production IP certificate issued; IP SAN verified |
| TLS expiry | 2026-11-09 04:46:32 UTC / 07:46:32 Riyadh; automated renewal configured |
| TLS renewal | certbot renew --dry-run succeeded |
| Secure cookie/CSRF live checks | Secure host-only cookies, HttpOnly session, SameSite=Lax; absent CSRF rejected with 419; private unauthenticated API rejected with 401 |
| Successful auth flow | Trusted-TLS session login, authenticated /auth/me, logout, then 401 PASS in separate DB with random test fixture and APP_ENV=production; temporary listener removed |
| Production browser auth integration | Real browser login form reached Laravel over trusted HTTPS, invalid credentials returned 422, secure cookies confirmed; no production account created |
| Sensitive paths | /.env, /api/.env, /storage/.env denied 403; /index.php 404 |
| External browser smoke | /en and /ar returned HTTPS 200 at widths 1440 and 390; lang/RTL-LTR, no horizontal overflow, no page errors PASS |
| Real public API | External /api/v1/public/categories returned success:true with empty data (no production seeder ran) |
| Production accounts/jobs final | users=0, failed_jobs=0, pending jobs=0 |
| Persistence | Services/timers enabled; reboot intentionally not performed |
| Resource snapshot after build | About 666 MiB RAM used / 1049 MiB available; swap 203 MiB used of 2047 MiB; 2 CPUs |

Screenshots: local deployment/screenshots/{ar,en}-{390,1440}.png, visually inspected Arabic mobile and English desktop. Automated browser tests intentionally used no production fixture. The full backend security suite covered SSRF, private authorization, rate limiting, UNKNOWN publication/share enforcement and authentication using isolated fixtures. Real external provider verdicts were NOT tested without credentials.

## Issues encountered and resolved

- Administrative access initially unavailable; owner configured it, sudo -n then succeeded. Running PostgreSQL commands as postgres directly was outside the passwordless root rule; authorized root runuser was used instead.
- Two source transfers were interrupted; retransferred and checked both archive hashes before completion.
- Git archives omit empty resources/views; created that directory and view cache succeeded.
- FPM could not open /proc/self/fd/2 as an error log; switched to scoped rotated file, then service stabilized. Initial restart counter reflects setup failures; final unit active and health checks passed.
- Frontend unit tests initially inherited production React mode/origin and failed expectations. Reran tests with NODE_ENV=test and expected fixture origins; all 81 passed. Actual production build retained real HTTPS API/site URLs.
- nginx -t passed a loopback-to-wildcard port change, but reload could not bind overlapping socket. Kept the loopback listener and added the explicit public IP listener; reload succeeded without Nginx restart. Actual sockets and external responses verified afterward.
- Health check immediately after a deliberate Next restart ran before its listener was ready; subsequent health checks and browser smoke passed.
- Some external SSH/HTTP connection attempts timed out transiently; later external curl and full browser smoke passed. No unrelated firewall/network tuning was made.

## Remaining issues / required next steps

1. Configure SMTP credentials and a legitimate sender. Current array mailer discards mail without logging bodies. Email verification, password reset and emailed invitations are unavailable. No production user was created or marked verified to bypass this limitation.
2. Configure an authoritative reputation provider. Current heuristic-only policy cannot mark links CLEAR, so UNKNOWN links cannot be published/shared. No development clear-marking or insecure workaround was used.
3. Configure AI provider/model/key if AI enrichment is wanted. It is currently disabled.
4. Supply an encrypted off-host backup destination and separate recovery-key custody. Current backups/verified restore protect local recovery, not loss of the VPS.
5. Supply an approved external alert channel if proactive outage notification is wanted. Current monitoring records failures locally in systemd/journald.
6. Add a dedicated domain when ready; use the migration procedure in VPS_DEPLOYMENT.md. IP certificate renewal is already enabled and needs ongoing monitoring. Review Certbot/Python compatibility before upgrading the isolated tool to the next major release.
7. Perform capacity/load testing before increasing concurrency; responsive smoke is not a sustained load test. No reboot test was performed to preserve existing production uptime.

Application infrastructure and secure access are running and verified. Provider-dependent email, link clearing/publication/sharing and AI features are not claimed fully operational.
