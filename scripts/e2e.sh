#!/usr/bin/env bash
# Runs the end-to-end tests: mock OIDC provider + app on PHP's built-in server (in Docker),
# then the Node tus-js-client test suite against them.
# Usage: scripts/e2e.sh [php-version]   (default 8.2)
# Set E2E_KEEP=1 to leave the servers running afterwards, for manual testing in a browser at
# http://127.0.0.1:18080/
set -euo pipefail
cd "$(dirname "$0")/.."

PHP_VERSION="${1:-8.2}"
E2E_ROOT="${E2E_ROOT:-/tmp/synoup-e2e}"
NAME="synoup-e2e"

rm -rf "$E2E_ROOT" && mkdir -p "$E2E_ROOT/dest"
[ -d vendor ] || { echo "Run composer install first" >&2; exit 1; }
[ -f public/assets/app.js ] || { echo "Run the frontend build first (cd frontend && npm ci && npm run build)" >&2; exit 1; }
[ -d tests/e2e/node_modules ] || (cd tests/e2e && npm ci)

docker rm -f "$NAME" >/dev/null 2>&1 || true
docker run -d --name "$NAME" --network host -u "$(id -u):$(id -g)" \
  -v "$PWD:/app:ro" -v "$E2E_ROOT:$E2E_ROOT" -w /app \
  -e SYNO_UPLOADER_CONFIG=/app/tests/e2e/config.php -e E2E_ROOT="$E2E_ROOT" -e PHP_CLI_SERVER_WORKERS=4 \
  "php:${PHP_VERSION}-cli" sh -c '
    php -S 127.0.0.1:18081 tests/e2e/mock-oidc/router.php >/dev/null 2>&1 &
    exec php -d display_errors=0 -S 127.0.0.1:18080 -t public tests/e2e/app-router.php' >/dev/null

cleanup() { [ "${E2E_KEEP:-0}" = 1 ] || docker rm -f "$NAME" >/dev/null 2>&1 || true; }
trap cleanup EXIT

for _ in $(seq 1 50); do
  curl -fs http://127.0.0.1:18081/.well-known/openid-configuration >/dev/null 2>&1 \
    && curl -s -o /dev/null http://127.0.0.1:18080/ && break
  sleep 0.2
done

(cd tests/e2e && E2E_DEST="$E2E_ROOT/dest" node --test)
echo "App log:"; cat "$E2E_ROOT/var/log/app.log" 2>/dev/null | tail -n 20 || true
