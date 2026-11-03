#!/bin/bash
# Called only by a manually authorized production job; environment secrets exist only for this step.
set -euo pipefail
: "${KEY:?SSH key required}" "${KNOWN:?Pinned known_hosts required}" "${HOST:?SSH host required}" "${SSH_USER:?SSH user required}"
PORT=${PORT:-22}
[[ "$HOST" =~ ^[A-Za-z0-9.-]{1,253}$ && "$PORT" =~ ^[0-9]{1,5}$ && "$SSH_USER" = llci ]] || { echo 'Invalid SSH variables' >&2; exit 1; }
[ "$PORT" -ge 1 ] && [ "$PORT" -le 65535 ] || exit 1
base=${RUNNER_TEMP:-${TMPDIR:-/tmp}}
ssh_dir=$(mktemp -d "$base/ll-ssh.XXXXXX")
printf '%s\n' "$ssh_dir" > "$base/ll-ssh-dir"
cleanup() { rm -f -- "$ssh_dir/key" "$ssh_dir/known_hosts"; rmdir -- "$ssh_dir"; rm -f -- "$base/ll-ssh-dir"; }
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP
printf '%s\n' "$KEY" > "$ssh_dir/key"; chmod 600 "$ssh_dir/key"
printf '%s\n' "$KNOWN" > "$ssh_dir/known_hosts"; chmod 600 "$ssh_dir/known_hosts"
unset KEY KNOWN
ssh_args=(-i "$ssh_dir/key" -p "$PORT" -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o UpdateHostKeys=no -o BatchMode=yes -o UserKnownHostsFile="$ssh_dir/known_hosts")
case "${ACTION:-}" in
 deploy)
  [[ "${COMPONENT:-}" =~ ^(api|web)$ && "${SHA:-}" =~ ^[0-9a-f]{40}$ ]] || exit 1
  artifact_dir=${ARTIFACT_DIR:-$PWD}
  [ -d "$artifact_dir" ] && [ ! -L "$artifact_dir" ] || exit 1
  digest=$(cat "$artifact_dir/release.sha256"); [[ "$digest" =~ ^[0-9a-f]{64}$ ]] || exit 1
  [ "$(sha256sum "$artifact_dir/release.tgz" | cut -d' ' -f1)" = "$digest" ] || { echo 'Local artifact changed' >&2; exit 1; }
  ssh "${ssh_args[@]}" "$SSH_USER@$HOST" "upload $COMPONENT $SHA $digest" < "$artifact_dir/release.tgz"
  ssh "${ssh_args[@]}" "$SSH_USER@$HOST" "deploy $COMPONENT $SHA $digest" ;;
 rollback)
  [[ "${RID:-}" =~ ^([0-9]{8}-[0-9]{6}-(api|web)-[0-9a-f]{7}|[0-9]{8}-[0-9]{2})$ ]] || exit 1
  ssh "${ssh_args[@]}" "$SSH_USER@$HOST" "rollback $RID" ;;
 *) echo 'Explicit deploy or rollback required' >&2; exit 1;;
esac
