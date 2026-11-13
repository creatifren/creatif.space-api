#!/usr/bin/env bash
#
# Deploy the API (and its MCP server) to staging.
#
#   ops/staging/deploy-staging-api.sh [git ref]      default: main
#
# Run on the server, as the user that owns the site, from anywhere.
# Expects the checkout at $APP_DIR with a filled-in .env (from
# ops/staging/.env.staging.example). Refuses anything that is not
# APP_ENV=staging with CREEM_TEST_MODE=true, so it can never touch
# production. The supervisor programs are staging-* (see supervisor/).
#
set -euo pipefail

APP_DIR="${APP_DIR:-/www/wwwroot/staging-api}"
REF="${1:-main}"
PHP="${PHP:-php}"
COMPOSER="${COMPOSER:-composer}"
NPM="${NPM:-npm}"
SUPERVISORCTL="${SUPERVISORCTL:-supervisorctl}"

say() { printf '\n==> %s\n' "$*"; }
die() { printf 'deploy-staging-api: %s\n' "$*" >&2; exit 1; }
env_of() { grep -E "^$1=" .env | tail -n1 | cut -d= -f2- | tr -d '"'; }

cd "$APP_DIR" || die "no checkout at $APP_DIR"
[ -f .env ] || die ".env missing: copy ops/staging/.env.staging.example to $APP_DIR/.env and fill it in"
[ "$(env_of APP_ENV)" = "staging" ] || die "refusing: .env is not APP_ENV=staging"
[ "$(env_of CREEM_TEST_MODE)" = "true" ] || die "refusing: staging must run with CREEM_TEST_MODE=true"
[ -n "$(env_of APP_KEY)" ] || die "APP_KEY is empty: run '$PHP artisan key:generate' once (see README)"

say "Maintenance mode"
"$PHP" artisan down --retry=15 || true
# Whatever happens below, the site comes back up.
trap '"$PHP" artisan up || true' EXIT

say "Code: $REF"
git fetch --prune origin "$REF"
git reset --hard FETCH_HEAD
RELEASE="$(git rev-parse --short HEAD)"

say "PHP dependencies"
"$COMPOSER" install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

say "OAuth keys (first deploy only)"
if [ ! -f storage/oauth-private.key ] && [ -z "$(env_of PASSPORT_PRIVATE_KEY)" ]; then
    "$PHP" artisan passport:keys --no-interaction
fi

say "Database"
"$PHP" artisan migrate --force --no-interaction

say "Caches"
"$PHP" artisan optimize:clear
"$PHP" artisan optimize
"$PHP" artisan storage:link 2>/dev/null || true

if [ -f mcp/package.json ]; then
    say "MCP server build"
    (cd mcp && "$NPM" ci --no-audit --no-fund && "$NPM" run build)
fi

say "Workers"
"$PHP" artisan queue:restart
if command -v "$SUPERVISORCTL" >/dev/null 2>&1; then
    for program in staging-api-scheduler staging-mcp; do
        "$SUPERVISORCTL" restart "$program" || echo "  (could not restart $program: restart it by hand)"
    done
else
    echo "  supervisorctl not found: restart staging-api-scheduler and staging-mcp by hand"
fi

"$PHP" artisan up
trap - EXIT

say "Health"
APP_URL="$(env_of APP_URL)"
if command -v curl >/dev/null 2>&1 && [ -n "$APP_URL" ]; then
    curl -fsS "$APP_URL/api/v1/health" && echo || echo "  health check failed: see storage/logs"
fi

say "Deployed $RELEASE to staging"
