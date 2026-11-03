#!/bin/bash
set -euo pipefail
stage=$(mktemp -d)
trap 'rm -rf -- "$stage"' EXIT
mkdir "$stage/api"
git archive HEAD | tar -x -C "$stage/api"
(
  cd "$stage/api"
  composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts
  mkdir -p resources/views bootstrap/cache
  APP_ENV=production APP_DEBUG=false APP_KEY="base64:$(openssl rand -base64 32)" php artisan package:discover --no-interaction
  composer check-platform-reqs --no-dev
)
python3 deploy/package_release.py api "$stage/api" "$PWD/release.tgz" --commit "$(git rev-parse HEAD)"
