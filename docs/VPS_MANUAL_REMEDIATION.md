# Manual VPS remediation — NOT EXECUTED

Prepared 2026-11-03. This file is a future administrator procedure, not permission to execute it. This task makes no VPS/GitHub changes. Do not run this automatically from CI. Keep DEPLOY_ENABLED absent. Confirm the passing regression evidence in REMEDIATION_REPORT.md and rehearse in an isolated Ubuntu 22.04 staging clone before scheduling an explicitly approved LinkLibrary-only maintenance window.

## Prerequisites and trust

Require administrative access, Python 3.10+, reviewed full API/web SHAs, independently verified artifact digests, enough disk for source clone + expanded artifact + >4GiB backup/headroom, and a tested database/storage restore. Preserve shared env/APP_KEY, permissions/ACLs, systemd files, forced-command authorized_keys, sudoers and tools in a root-only backup outside releases. Never print env or cached config. Do not modify Nginx, global PostgreSQL, PM2, Strapi, certificates or existing omerali.dev/api.omerali.dev services.

The active audited release `20261102-01` is mutable and has no trusted immutable manifest. Obtain an authentic manifest from independently reviewed/reconstructed approved artifacts, including exact runtime layout/links and hashes, not from the live release being repaired. `entries` uses the ll_release Trees.inventory schema; `commits` records both full SHAs. Validate its digest through an independent channel. An incomplete reference or any byte mismatch is a STOP. No authentic matching reference means separately review reconstruction of a new bootstrap API/web pair; this procedure deliberately does not bless mutable bytes or automate that reconstruction.

Before changing permissions: identify all runtime UID and linklibrary-group members, stop their LinkLibrary writers and block new ingress for this maintenance window using an approved LinkLibrary-specific mechanism. Stop only LinkLibrary units/timers; never global services. Include linklibrary health/backup/certificate jobs if they write the tree, and exclude concurrent llci uploads/deployments. The repair tool refuses active runtime/group-member processes. Preserve backup/restore evidence before stopping units and retain their exact previous active/enabled states. Ensure shared storage/cache boundaries are real directories and group-correct, no unexpected mounts/ACL write grants, and all ancestors of /srv/linklibrary/tools/releases/shared are root controlled. Unexpected structures mean STOP, not broad chmod/chown.

## Linux VPS administration — only after separate approval

The following assumes a **root-owned reviewed checkout** at `/root/linklibrary-remediation-src` and a root-only existing `/root/linklibrary-remediation-evidence` directory. Set its actual reviewed path; never copy tooling directly out of an app-writable release. Record reviewed checkout commit and permissions. No commands below were executed on a VPS in this task.

```bash
set -euo pipefail
umask 077
cd /root/linklibrary-remediation-src
sha256sum -c deploy/SERVER_SHA256SUMS
# Back up existing files/configuration into a root-only evidence directory first.
# Inspect every ancestor before installation; STOP on links, writable ancestors or mounts.
namei -l /srv/linklibrary/tools /srv/linklibrary/releases /srv/linklibrary/shared
install -o root -g root -m 0644 deploy/server/ll_release.py /srv/linklibrary/tools/ll_release.py
install -o root -g root -m 0644 deploy/server/ll-readiness.php /srv/linklibrary/tools/ll-readiness.php
install -o root -g root -m 0750 deploy/admin/repair_permissions.py /srv/linklibrary/tools/repair_permissions.py
install -o root -g root -m 0750 deploy/admin/recover_release.py /srv/linklibrary/tools/recover_release.py
install -o root -g root -m 0750 deploy/server/ll-release /srv/linklibrary/tools/ll-release
install -o root -g root -m 0755 deploy/server/ll-ingest /srv/linklibrary/tools/ll-ingest
visudo -cf deploy/server/sudoers-linklibrary-deploy
install -o root -g root -m 0440 deploy/server/sudoers-linklibrary-deploy /etc/sudoers.d/linklibrary-deploy
visudo -c
```

Review existing tools/php and health.sh, nofollow regular/root ownership and dependencies; they are reused, not overwritten here. The PHP wrapper must keep the existing isolated GD path and umask behavior. Check expected installed script bytes against SERVER_SHA256SUMS (map source names to installed names); retain that file with evidence. Do not run the deploy wrapper to test installation against the live release.

Record state first and perform the approved LinkLibrary-only stop in Linux (review these exact units against the approved inventory; a missing/unexpected unit is a STOP):

```bash
systemctl show -p Id -p ActiveState -p UnitFileState 'linklibrary*' > /root/linklibrary-remediation-evidence/units-before.txt
systemctl stop linklibrary-scheduler.timer linklibrary-health.timer linklibrary-backup.timer linklibrary-certificate-renew.timer
systemctl stop linklibrary-api linklibrary-web linklibrary-queue linklibrary-scheduler.service linklibrary-health.service linklibrary-backup.service linklibrary-certificate-renew.service
```

If an approved job/backup is in progress, let it finish under administrator control rather than blindly interrupt it. Confirm no group-member writer or pending deployment can start; do not stop global PostgreSQL, Nginx, PM2 or Strapi. Restore only the recorded prior unit states after validation; do not enable previously disabled units.

After explicitly stopping the identified LinkLibrary writers, acquire/hold the release flock in this maintenance shell. Substitute independently approved digest values below (64 lowercase hex), not placeholders. `plan` records all immutable inode metadata without changing code; `apply` repeats validation and writes a different pre-change snapshot. Both exclude shared/cache contents. Snapshot paths must be new root-only files.

```bash
exec 9>/srv/linklibrary/.ll-release.lock
flock -n 9 || exit 1
REF=/root/linklibrary-remediation-evidence/trusted-reference.json
REF_SHA=REPLACE_WITH_INDEPENDENTLY_APPROVED_SHA256
python3 -I /srv/linklibrary/tools/repair_permissions.py plan \
  --release-id 20261102-01 --reference "$REF" --reference-sha256 "$REF_SHA" \
  --snapshot /root/linklibrary-remediation-evidence/permission-plan.json --confirm-writers-stopped
# Review plan offline: only expected code paths/modes/owners, caches/shared excluded.
python3 -I /srv/linklibrary/tools/repair_permissions.py apply \
  --release-id 20261102-01 --reference "$REF" --reference-sha256 "$REF_SHA" \
  --snapshot /root/linklibrary-remediation-evidence/permissions-before-apply.json --confirm-writers-stopped
sha256sum /root/linklibrary-remediation-evidence/permissions-before-apply.json
```

Apply normalizes root-controlled 0755 dirs and 0644/0755 code and seals only already reference-matched bytes. It does not start services or alter cache/storage permissions. After root-only manifest/inventory review, restore the previously recorded LinkLibrary service/timer states and run private readiness as linklibrary using tools/php. Verify systemd users, actual linklibrary_prod/linklibrary_app identity, strict config, TLS `/up`, categories JSON and EN/AR; inspect logs without printing secrets. Separately test cache writes and runtime code-write denial. Check existing production site responses without changing them. Release the lock when maintenance verification is complete. Leave deployment disabled if any gate fails.

## Restore permission metadata after partial/failed correction

Keep writers stopped and the maintenance lock held. Preserve failed evidence. Use the recorded **pre-apply** snapshot and independently recorded hash; never the plan if intervening metadata changed. Restore refuses different bytes/unsafe paths. The required --snapshot argument is unused in restore mode. It restores old owners/modes only, not application bytes; shared/cache data remain excluded.

```bash
SNAP=/root/linklibrary-remediation-evidence/permissions-before-apply.json
SNAP_SHA=REPLACE_WITH_RECORDED_SNAPSHOT_SHA256
python3 -I /srv/linklibrary/tools/repair_permissions.py restore \
  --release-id 20261102-01 --reference "$SNAP" --reference-sha256 "$SNAP_SHA" \
  --snapshot "$SNAP" --confirm-writers-stopped
```

Restoring old mutable modes reintroduces the original weakness: do not resume deployments or describe it as a security fix. Restore backed-up helper/sudoers bytes under root review if necessary, verify visudo, and investigate. Do not overwrite the whole server configuration or restart unrelated services.

## Interrupted deployment / failed automatic recovery

Keep CI disabled. Read the root-only `.ll-transaction.json`; preserve its digest/evidence and failed candidate. Verify original/candidate manifests, backups and forward-migration compatibility before selecting the journal's original. Do not delete the journal, rerun blindly, edit current by hand, or invoke down migrations. Do not hold a separate flock when using this recovery tool: it acquires the same lock itself.

```bash
sha256sum /srv/linklibrary/.ll-transaction.json
python3 -I /srv/linklibrary/tools/recover_release.py \
  --original APPROVED_ORIGINAL_RELEASE_ID \
  --journal-sha256 APPROVED_JOURNAL_SHA256 \
  --confirm-forward-schema-compatible
```

The tool requires matching journal/original, validates immutable original, preserves evidence, resets migrator password, atomically switches, restarts only LinkLibrary and repeats readiness. Nonzero means recovery failed; keep deployment disabled and investigate under administrator control. If migrations are incompatible, restore a verified backup to a **new isolated database** and review an application/DB recovery plan; no automatic destructive schema restore is supplied. SIGKILL/power-loss cases require this manual process.

## First release checklist

Confirm regression tests including sharing journey and pcntl concurrency; production reviewers/main rules effective; scripts installed/hash-verified; current trusted manifest; app cannot write code; writable caches secure; fresh verified backup/off-host plan; migrator role and auditing; PHP/Node/native baseline and systemd memory; trusted HTTPS/renewal; exact public origin/secure cookies; strict sharing policy; no production test data; both locale/API checks; existing site baseline. Only then separately authorize enabling/dispatching a reviewed full-SHA manual deployment. Nothing in this document enables authentication on untrusted HTTPS.
