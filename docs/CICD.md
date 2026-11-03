# LinkLibrary CI/CD (prepared, DISABLED)

Status: workflows are committed-ready but inert. Tests run on every push to `main`; the `deploy`/`rollback` jobs run only when the
repository variable `DEPLOY_ENABLED` is exactly `true`. Nothing here has been run against the real VPS or GitHub Actions yet.

## Why production was not updating
Neither repository had `.github/workflows`. The first release was provisioned by one-off scripts (`deployment/*.py`, untracked,
hard-coded release `20261102-01`). A push to GitHub therefore never reached the server.

## Flow (reuses releases/, shared env, backup unit, systemd units, atomic `current` symlink)
1. GitHub runner: tests (`_test.yml`) -> build tracked source only -> `release.tgz` + sha256.
2. Upload over SSH with a dedicated key. The key can run only `/srv/linklibrary/tools/ll-ingest` (forced command).
3. `ll-release` (root-owned, run through a single sudo rule): copies the package into a root-only staging dir and checks its
   sha256 against the digest CI computed; validates the archive structure (no absolute paths, `..`, hard links, devices, setuid,
   escaping symlinks, size caps); copies `current` to a new release dir and replaces only the deployed component; links shared
   env/storage; (api) takes a backup and runs forward-only migrations as `linklibrary_migrator` with a temporary password;
   rebuilds config/route/view caches for the new path (always, because caches contain absolute paths); hardens ownership;
   switches `current` atomically; restarts only `linklibrary-api`, `linklibrary-web`, `linklibrary-queue`; smoke-tests `/up`,
   `/en`, `/ar` and `health.sh`. All builds (composer, `npm ci`, `next build`) happen on the GitHub runner, never on the 2 GB VPS.
4. Smoke failure => automatic switch back to the previous release. Manual rollback: workflow_dispatch -> action `rollback`.
   Database migrations are never rolled back; keep migrations backward compatible.

Never touched: Nginx, PM2 / omerali.*, other databases, unrelated units. Releases keep 3 CI builds + current + previous + original.

## Security model (summary)
- PR code never sees secrets: `ci.yml` runs on `pull_request`/non-main pushes with `contents: read` and no secrets; deploy jobs exist only
  on `push` to `main` or manual dispatch, require `DEPLOY_ENABLED=true`, run in environment `production`. Restrict that environment to the
  `main` branch (Settings -> Environments -> Deployment branches) and enable branch protection on `main`.
- The SSH key can only run `ll-ingest`; `ll-ingest` can only upload to `incoming/` and sudo one root-owned script; `ll-release`
  re-validates everything and never reads the CI-writable file after verifying a root-owned copy.
- Residual risk (cannot be removed by design): whoever can push to `main` and approve the environment can ship code that runs as
  `linklibrary` and migrates `linklibrary_prod`. Use branch protection, required reviewers and a hardware-backed GitHub account.
- Rollback never reverts the database: write migrations expand/contract (additive first) so the previous release keeps working.
- Actions are pinned to major version tags, not commit SHAs (SHAs could not be verified offline): pin them or enable Dependabot before enabling deploy.

## GitHub configuration (both repos)
Environment `production` (add a required reviewer if you want a manual gate).
- Secrets: `LL_SSH_PRIVATE_KEY` (dedicated ed25519 private key), `LL_SSH_KNOWN_HOSTS` (output of `ssh-keyscan -t ed25519 HOST`, verified against the server fingerprint).
- Variables: `LL_SSH_HOST`, `LL_SSH_PORT` (optional), `LL_SSH_USER` (default `llci`), `DEPLOY_ENABLED` (leave unset until ready);
  web repo only: `LL_PUBLIC_ORIGIN` (`https://31.172.87.248:8080`), optional `LL_CONTACT_EMAIL`.
No VPS password, API key or app secret is stored in GitHub; app secrets stay in `/srv/linklibrary/shared/*.env`.

## One-time server setup (administrator, from the existing `omerali-vps` session; review each command first)
```
sudo adduser --disabled-password --gecos "" llci                    # NOT in sudo/adm/docker groups
sudo install -d -m 700 -o llci -g llci /home/llci/.ssh
sudo install -d -m 755 -o llci -g llci /srv/linklibrary/incoming
sudo install -m 0755 -o root -g root deploy/server/ll-ingest  /srv/linklibrary/tools/ll-ingest
sudo install -m 0750 -o root -g root deploy/server/ll-release /srv/linklibrary/tools/ll-release
sudo install -m 0440 deploy/server/sudoers-linklibrary-deploy /etc/sudoers.d/linklibrary-deploy
sudo visudo -cf /etc/sudoers.d/linklibrary-deploy
ssh-keygen -t ed25519 -N "" -C github-actions-linklibrary -f ./ll_deploy   # on a trusted machine; delete the private file after saving it as the GitHub secret
# /home/llci/.ssh/authorized_keys (one line):
restrict,command="/srv/linklibrary/tools/ll-ingest",no-pty <contents of ll_deploy.pub>
```
Prerequisite checks before enabling: `/srv/linklibrary/shared/release-prev` is created on first deploy; `linklibrary_migrator` can
`ALTER ROLE ... PASSWORD` only by postgres (the script uses `runuser -u postgres`); the existing release must have `api/.env` and
`api/storage` as symlinks to `shared/` (as provisioned).

## Server preflight (read-only, before the setup commands)
```
sudo sshd -T | grep -i -E '^(allowusers|allowgroups|denyusers|passwordauthentication|authorizedkeysfile)'   # if AllowUsers/AllowGroups is set, llci must be added or login fails
id llci 2>&1                                   # must NOT exist yet
ls -ld /srv/linklibrary /srv/linklibrary/tools /srv/linklibrary/shared /srv/linklibrary/releases
readlink -f /srv/linklibrary/current           # expected: /srv/linklibrary/releases/20261102-01
ls -l /srv/linklibrary/current/api/.env /srv/linklibrary/current/api/storage /srv/linklibrary/current/web/.env.production   # symlinks into shared/
sudo -u postgres psql -Atc "select rolname,rolcanlogin,rolconfig from pg_roles where rolname like 'linklibrary%'"   # migrator: role=linklibrary_owner
df -h /srv /var/backups; free -m
```

## GitHub settings (cannot be verified from code)
- Settings -> Environments -> `production`: Deployment branches = Selected branches -> `main` only; add yourself as required reviewer; put the two SSH secrets
  in the ENVIRONMENT (not repository) secrets, so no other branch or workflow can read them.
- Branch protection on `main`: require PR + passing `CI`, block force-push and deletion.
- Actions -> General: Workflow permissions = Read repository contents; do not allow Actions to approve PRs.

## Activation checklist
1. Review and commit these files with normal commits (no history rewrite) and push to `main`; confirm the CI jobs pass.
2. Do the server setup above; test `ssh -i key llci@HOST status` (must only print the release list) and `ssh ... "id"` (must be denied).
3. Add the secrets/variables, then run `Deploy API` / `Deploy Web` with `DEPLOY_ENABLED` still unset (tests only).
4. Set `SECURITY_SCANNING_COMING_SOON=true` and `SECURITY_ALLOW_UNVERIFIED_SHARING=true` in `shared/api.env`; keep `AI_PROVIDER=null`.
5. Set `DEPLOY_ENABLED=true`; run the workflow by hand once and verify the live site in Arabic and English.
6. Rehearse a rollback with the manual `rollback` action.
