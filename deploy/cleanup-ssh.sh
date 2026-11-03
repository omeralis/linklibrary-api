#!/bin/bash
# Always-run backstop for a killed SSH step; only this job's marker and known filenames are removed.
set -euo pipefail
base=${RUNNER_TEMP:-${TMPDIR:-/tmp}}
marker="$base/ll-ssh-dir"
[ -f "$marker" ] && [ ! -L "$marker" ] || exit 0
IFS= read -r directory < "$marker"
case "$directory" in "$base"/ll-ssh.*) ;; *) echo 'Unsafe cleanup marker' >&2; exit 1;; esac
[ -d "$directory" ] && [ ! -L "$directory" ] || exit 1
rm -f -- "$directory/key" "$directory/known_hosts"
rmdir -- "$directory"
rm -f -- "$marker"
