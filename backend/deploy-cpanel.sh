#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"

if [ ! -f .env ]; then
  printf 'Missing backend/.env. Keep the production environment file in place before deploying.\n' >&2
  exit 1
fi
"$PHP_BIN" -r 'if (PHP_VERSION_ID < 80300) { fwrite(STDERR, "PHP 8.3 or newer is required.\n"); exit(1); }'

if [ ! -f public/spa.html ]; then
  printf 'Frontend build is missing from this checkout.\n' >&2
  exit 1
fi

maintenance_started=0
cleanup() {
  result=$?
  if [ "$maintenance_started" -eq 1 ]; then
    "$PHP_BIN" artisan up || true
  fi
  if [ "$result" -ne 0 ]; then
    printf '\nUpdate stopped. Review the error above before retrying.\n' >&2
  fi
  exit "$result"
}
trap cleanup EXIT

"$PHP_BIN" artisan down --retry=60
maintenance_started=1
"$COMPOSER_BIN" install --no-dev --prefer-dist --optimize-autoloader --no-interaction
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan migrate --force --no-interaction
if [ ! -e public/storage ]; then
  "$PHP_BIN" artisan storage:link --no-interaction
fi
"$PHP_BIN" artisan optimize
"$PHP_BIN" artisan queue:restart
"$PHP_BIN" artisan up
maintenance_started=0
printf '\nApplication updated. Checking production readiness...\n'
if ! "$PHP_BIN" artisan hotel:check-readiness; then
  printf '\nDeployment completed, but the checks above need attention before launch.\n'
  exit 2
fi
printf '\nReadiness checks passed. Verify a guest booking, email delivery, and staff check-in/check-out on the live site.\n'
