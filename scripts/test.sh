#!/usr/bin/env bash
# Runs the PHPUnit suite on every PHP version DSM 7.4 Web Station offers, using Docker.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -d vendor ]] || { echo "Run composer install first" >&2; exit 1; }
for v in ${PHP_VERSIONS:-8.2 8.3 8.4}; do
  echo "== PHP $v"
  docker run --rm -u "$(id -u):$(id -g)" -v "$PWD:/app" -w /app "php:$v-cli" php vendor/bin/phpunit "$@"
done
