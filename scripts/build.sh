#!/usr/bin/env bash
# Builds a deployable copy of the app. DSM has no Composer or Node, so everything is built here.
#   scripts/build.sh            -> build/synology-uploader/ and build/synology-uploader.zip
# Composer and npm run natively if installed, otherwise in Docker (composer:2 / node:22).
set -euo pipefail
cd "$(dirname "$0")/.."

ROOT="$PWD"
OUT="$ROOT/build/synology-uploader"


run_npm() {
  if command -v npm >/dev/null 2>&1; then
    (cd "$ROOT/frontend" && npm "$@")
  else
    docker run --rm -u "$(id -u):$(id -g)" -v "$ROOT:/app" -w /app/frontend -e npm_config_cache=/tmp/npm node:22 npm "$@"
  fi
}

echo "==> Building frontend"
run_npm ci
run_npm run build

echo "==> Assembling $OUT"
rm -rf "$OUT" "$ROOT/build/synology-uploader.zip"
mkdir -p "$OUT/config" "$OUT/var"  # var/ must be writable by the http user on the NAS
cp -r public src templates bin "$OUT/"
cp composer.json composer.lock LICENSE README.md "$OUT/"
cp config/config.example.php "$OUT/config/"

echo "==> Installing production PHP dependencies"
if command -v composer >/dev/null 2>&1; then
  (cd "$OUT" && composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-progress)
else
  docker run --rm -u "$(id -u):$(id -g)" -v "$OUT:/app" -w /app -e COMPOSER_HOME=/tmp/composer composer:2 \
    install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-progress
fi
rm -f "$OUT/composer.json" "$OUT/composer.lock"

if command -v zip >/dev/null 2>&1; then
  (cd "$ROOT/build" && zip -qr synology-uploader.zip synology-uploader)
  echo "==> Done: build/synology-uploader.zip"
else
  echo "==> Done: build/synology-uploader/ (install 'zip' to also get an archive)"
fi
