# LinkLibrary VPS deployment runbook

Updated 2026-11-02 (Asia/Riyadh). Application deployed and verified over trusted IP HTTPS. Provider-dependent features remain limited; see VPS_DEPLOYMENT_REPORT.md.

## Access and architecture

- Arabic: https://31.172.87.248:8080/ar
- English: https://31.172.87.248:8080/en
- Login: https://31.172.87.248:8080/en/login (or /ar/login)
- API health: https://31.172.87.248:8080/up
- API prefix: same HTTPS origin, /api/v1; CSRF bootstrap /sanctum/csrf-cookie.
- SSH: existing omerali-vps alias, deploy account. Administrative sudo -n is available. Never put passwords in commands/chat.

| Component | Actual configuration |
|---|---|
| Host | srv602086347, 31.172.87.248, Ubuntu 22.04.5 |
| Release | /srv/linklibrary/releases/20261102-01/{api,web} |
| Current | /srv/linklibrary/current -> releases/20261102-01 |
| Persistent data | /srv/linklibrary/shared/storage |
| Environment | /srv/linklibrary/shared/api.env and web.env, root:linklibrary 0640 |
| Runtime account | linklibrary, system account with nologin shell |
| Database | linklibrary_prod, existing PostgreSQL 14 cluster |
| DB roles | linklibrary_owner NOLOGIN; linklibrary_migrator scoped login with owner role; linklibrary_app runtime DML only |
| Public TLS | Nginx on 31.172.87.248:8080, also loopback :8080 for checks |
| Frontend runtime | 127.0.0.1:3100, systemd linklibrary-web |
| Internal API | 127.0.0.1:8081, Nginx -> /run/linklibrary/api.sock |
| PHP-FPM | systemd linklibrary-api, custom pool/config /srv/linklibrary/tools/fpm.conf |
| Queue | linklibrary-queue, database queue |
| Scheduler | linklibrary-scheduler.service + timer, once per minute |
| Backup | linklibrary-backup.service + timer, 02:10 UTC daily |
| Health | linklibrary-health.service + timer, every five minutes |
| TLS renewal | linklibrary-certificate-renew.service + timer, every six hours with up to five-minute jitter |

Nginx site files in sites-available and linked from sites-enabled: z-linklibrary-internal, z-linklibrary-ip-challenge, z-linklibrary-https. Only a new IP-specific HTTP host handles ACME challenges on port 80; all other requests to that host return 404. It does not become the default server. Existing omerali.dev and api.omerali.dev files are unchanged. Existing PM2 processes and PostgreSQL database omerali_strapi remain untouched.

UFW adds only TCP 8080 for trusted HTTPS. No public HTTP application, public :8081, public :3100 or public PostgreSQL listener. Node binds loopback; PHP uses a Unix socket. The certificate is for IPv4 only; no IPv6 LinkLibrary listener is configured.

## Installed tools and isolation

PHP 8.4.27 with pgsql, intl, mbstring, xml, curl, zip, bcmath and opcache was installed from the signed Ondrej PHP Jammy repository. The apt transaction upgraded no existing package. Composer 2.11.1 is /srv/linklibrary/tools/composer.phar, installed after verifying installer SHA-384 against the official signature.

GD needs a libgd version newer than the library mapped by existing Nginx. To avoid replacing that live dependency, signed apt packages for php8.4-gd and its image-library dependencies were downloaded and extracted under /srv/linklibrary/tools/gd-root. They are NOT installed over system libraries. /srv/linklibrary/tools/php wraps PHP with this isolated LD_LIBRARY_PATH and the GD extension. Use this wrapper for ALL LinkLibrary artisan/composer operations. FPM has the same private library path and extension. Future PHP security updates must also refresh/recheck this isolated GD bundle; do not upgrade system libgd implicitly.

Node remains 22.23.3, PostgreSQL remains 14.24, Nginx remains 1.18.0. No unrelated runtime upgrade or restart occurred. The newly installed unused default php8.4-fpm unit is disabled; linklibrary-api is the dedicated FPM service.

Code is root-owned and runtime-readable. Only Laravel shared storage, bootstrap/cache and Next .next/cache have the required runtime write access. bootstrap/cache files are mode 0640 because cached configuration contains secrets. Shared environment files are never publicly served. APP_KEY, database password and private frontend key were generated on the server; no development env file was copied.

## Production environment names

API: APP_ENV=production, APP_DEBUG=false, APP_NAME, APP_KEY, APP_URL=https://31.172.87.248:8080, FRONTEND_URL with the same value, FRONTEND_SERVER_KEY, DB_CONNECTION=pgsql, DB_HOST=127.0.0.1, DB_PORT=5432, DB_DATABASE=linklibrary_prod, DB_USERNAME=linklibrary_app, DB_PASSWORD. Runtime cannot CREATE in public schema and has no superuser/createdb/createrole privilege. Database CONNECT and public schema privileges were restricted for this database only. Existing cluster/database policies were not changed.

Cookies/cache/queues/storage: SESSION_DRIVER=database, SESSION_COOKIE=linklibrary_session, SESSION_SECURE_COOKIE=true, SESSION_HTTP_ONLY=true, SESSION_SAME_SITE=lax, no SESSION_DOMAIN (host-only IP cookie), SANCTUM_STATEFUL_DOMAINS=31.172.87.248:8080, CORS_ALLOWED_ORIGINS=https://31.172.87.248:8080, TRUSTED_PROXIES=127.0.0.1, CACHE_STORE=database, CACHE_PREFIX=linklibrary_prod, QUEUE_CONNECTION=database, DB_QUEUE_RETRY_AFTER=180, FILESYSTEM_DISK=local, UPLOADS_DISK=public, LOG_CHANNEL=daily, LOG_LEVEL=warning, LOG_DAILY_DAYS=14.

Web: NEXT_PUBLIC_API_URL=https://31.172.87.248:8080, NEXT_PUBLIC_SITE_URL with the same origin, API_INTERNAL_URL=http://127.0.0.1:8081, FRONTEND_SERVER_KEY matching API, NODE_ENV=production, NODE_OPTIONS=--max-old-space-size=384. Private key has no NEXT_PUBLIC prefix. Nginx replaces forwarding headers and removes untrusted X-Client-IP/X-Frontend-Key from external requests.

Pending providers: GOOGLE_WEB_RISK_API_KEY (or appropriately licensed GOOGLE_SAFE_BROWSING_API_KEY) and SECURITY_PROVIDERS; AI_PROVIDER with OPENAI_API_KEY/ANTHROPIC_API_KEY and selected model; SMTP MAIL_HOST, MAIL_PORT, MAIL_SCHEME, MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_ADDRESS, MAIL_FROM_NAME and MAIL_MAILER=smtp. Current MAIL_MAILER=array discards mail without logging message bodies; no verification/reset/invitation emails are delivered. AI_PROVIDER=null and SECURITY_PROVIDERS=heuristic. UNKNOWN links remain blocked from publication/sharing. No development bypass is enabled. Local storage is used; object storage is optional.

Update secrets through a protected server-side editor/provisioner. Never dump env/config or run phpinfo. After API env changes refresh config cache under controlled deployment permissions and restart only LinkLibrary workers/FPM. Frontend public variable changes require a new build.

## Services and operational checks

```bash
sudo systemctl status linklibrary-api linklibrary-web linklibrary-queue
sudo systemctl list-timers 'linklibrary*'
sudo systemctl start linklibrary-health
sudo nginx -t
cd /srv/linklibrary/current/api
sudo -u linklibrary /srv/linklibrary/tools/php artisan migrate:status
sudo -u linklibrary /srv/linklibrary/tools/php artisan linklibrary:doctor --network
sudo journalctl -u linklibrary-web -u linklibrary-queue --since '10 minutes ago'
```

Queue command: wrapper artisan queue:work database --queue=security,extraction,ai,default --sleep=3 --tries=3 --timeout=120 --memory=192 --max-time=3600. Retry interval 180 exceeds maximum job timeout 120. systemd restarts the worker and permits a 150-second graceful stop. Scheduler timer invokes schedule:run only; no duplicate cron or schedule:work. Heartbeat stored in database cache. All production services/timers are enabled for reboot; no reboot was performed.

FPM max_children=2, ondemand, MemoryMax=384M. Frontend MemoryMax=512M and heap 384 MiB. Queue MemoryMax=256M; scheduler 192M. Builds use a separate transient unit with 900M RAM, 512M swap, one CPU and low I/O priority; never run npm dev. The server has 2 CPUs and 2 GB RAM; validate capacity before increasing concurrency. Build-time tests use test-specific origins; production build uses actual HTTPS origin and real API.

Health checks verify service states, API/frontend responses, trusted TLS, certificate validity beyond two days, scheduler freshness and queue backlog/failures. Failures appear in systemd/journald; external notifications are NOT configured. Nginx new logs are covered by existing /var/log/nginx/*.log rotation; LinkLibrary FPM logs have /etc/logrotate.d/linklibrary; Laravel uses daily logs with 14-day retention. Existing global journald settings were not changed.

## TLS and renewal

Certbot 5.9.0 runs in isolated /srv/linklibrary/tools/acme virtualenv; existing system Certbot 1.21.0 and its certificates/timer remain unchanged. Certificate material: /srv/linklibrary/acme/config/live/31.172.87.248. ACME webroot: /srv/linklibrary/acme-webroot. Shortlived certificate expires 2026-11-09 04:46:32 UTC (07:46:32 Riyadh); automated renewal is essential. Renewal dry-run succeeded.

```bash
sudo systemctl start linklibrary-certificate-renew
sudo /srv/linklibrary/tools/acme/bin/certbot renew --dry-run --non-interactive \
  --config-dir /srv/linklibrary/acme/config \
  --work-dir /srv/linklibrary/acme/work --logs-dir /srv/linklibrary/acme/logs
```

Renewal deploy hook runs nginx -t then reload; it never stops Nginx. Python 3.10 deprecation is announced for the next Certbot major version, so the isolated environment is constrained to Certbot <6 until compatibility is reviewed. Reference: https://letsencrypt.org/2026/03/11/shorter-certs-certbot . Do not use standalone validation that stops the existing web server.

## Backups and restore

Root-only /var/backups/linklibrary stores custom-format pg_dump archives, shared-storage tarballs and AES-256-CBC/PBKDF2 encrypted env archives. Key: /srv/linklibrary/shared/backup.key, mode 0600. Backup credentials: shared/backup.pgpass, root-only. A verified backup is created before retention; retain seven latest daily snapshots plus one per week for four recent weeks. The pre-provision configuration archive is pre-provision-nginx.tgz; before-migration.dump contains the initially empty new database.

```bash
sudo systemctl start linklibrary-backup
sudo journalctl -u linklibrary-backup -n 20
```

Archive listing and a real restore into linklibrary_restore_verify were verified (26 public tables, 10 migrations, zero users at snapshot). Never restore into an existing production database. For recovery: stop only LinkLibrary writers, preserve a fresh current snapshot, create a NEW isolated restore database, restore with pg_restore --exit-on-error --no-owner --no-privileges under an approved scoped role, reapply intended runtime grants, verify rows and application integrity, then switch LinkLibrary DB settings after review. Preserve APP_KEY/storage. No automatic migration rollback.

Backups remain on the VPS; off-host encrypted storage and separate recovery-key custody are still needed for server-loss protection. Do not claim off-host recovery readiness. The verification databases are retained, have restricted CONNECT, and are not public. The test OS account has no access to production env or cached secrets. Temporary TLS verification process/listener :8444 was removed.

## Safe future releases and rollback

1. Review clean Git histories and exact commit hashes; never force-push/overwrite remote history. Export only tracked reviewed source. Exclude development env, fixtures/archives, vendor/node_modules and git metadata.
2. Create a new directory under releases and build there with npm ci and locked Composer dependencies; never mutate current code in place. Temporarily give build account access only to new release. Fresh releases link preserved shared storage/env. Create resources/views if absent (Git does not retain empty dirs).
3. Run checks using isolated test credentials and *_test DB; never run destructive tests on production. Run Composer platform/audit and npm production audit. Confirm actual Linux build with intended public URLs.
4. Take/verify backup. Review migration up methods and assert current_database/current_user. Generate temporary migrator credentials safely; migrate forward only with linklibrary_migrator/owner role, never runtime superuser. Remove temporary credential file after use. No seeders, migrate:fresh, db:wipe or dev-mark-clear.
5. Cache API config/routes/views for new release under correct env; harden code ownership, cached-secret permissions and runtime cache write paths. Use atomic current symlink switch. Gracefully restart only LinkLibrary FPM/web/queue; scheduler has one timer. Verify nginx config before any reload and verify actual listener after it.
6. Smoke test trusted TLS, DB, queues, scheduler, storage, auth, both languages and existing sites. Keep three successful releases when available; only one initial release currently exists.

Rollback to a prior schema-compatible release by pausing only LinkLibrary writers, atomically switching current to that prior release and restarting only new-app services. Shared env/key/storage persist. Do not run down migrations automatically. On the initial release there is no prior application release; disabling only the LinkLibrary hosts/services is the fallback while preserving its database/backups. Never restore the whole old Nginx archive over current production configuration.

A reload from loopback-only :8080 to wildcard :8080 could not bind the old socket; this was resolved without restart by keeping loopback and adding an explicit 31.172.87.248:8080 listener. Always check ss after reload; nginx -t alone does not prove a new listener bound.

## Future domain migration

Choose owned hostnames and configure DNS without altering existing domains. Add isolated named Nginx virtual hosts and a valid new certificate/renewal. Prefer the same-origin API routing already used. Update APP_URL, FRONTEND_URL, NEXT_PUBLIC_API_URL, NEXT_PUBLIC_SITE_URL, exact SANCTUM_STATEFUL_DOMAINS and CORS_ALLOWED_ORIGINS. Keep host-only cookies for same-origin layout; use a shared SESSION_DOMAIN only if required by an approved same-site subdomain topology. Keep Secure/HttpOnly/SameSite and CSRF. Rebuild frontend, refresh Laravel config cache, restart only LinkLibrary services, test fresh cookies/login/logout and both locales, then retire IP access if desired. No application reinstall or database replacement is required.
