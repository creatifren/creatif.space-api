#!/usr/bin/env bash
# MCP contract smoke: the built MCP server (real auth) in front of the real
# Laravel API on a fresh, seeded sqlite database, then mcp/contract/*.test.ts
# (tools/list shape #78, error envelope #76). No mocks, no external service.
#
# Needs: composer install done, `npm ci` in mcp/. Run from the repo root:
#   scripts/mcp-contract-smoke.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

API_PORT="${API_PORT:-8077}"
MCP_PORT="${MCP_PORT:-3077}"
SECRET="contract-smoke-$(date +%s)-$RANDOM"
WORK="$(mktemp -d)"
DB="$WORK/contract.sqlite"
touch "$DB"

# The API's own environment for this run: a throwaway sqlite file, no mail,
# no queue workers, the introspection secret shared with the MCP server.
export APP_ENV=testing APP_DEBUG=false APP_URL="http://127.0.0.1:$API_PORT"
export DB_CONNECTION=sqlite DB_DATABASE="$DB"
unset DB_URL
export CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array BROADCAST_CONNECTION=log
export MCP_INTROSPECT_SECRET="$SECRET"
[ -f .env ] || cp .env.example .env
grep -q '^APP_KEY=base64' .env || php artisan key:generate --force --no-interaction >/dev/null

pids=()
cleanup() {
  for p in "${pids[@]}"; do kill "$p" 2>/dev/null || true; done
  rm -rf "$WORK"
}
trap cleanup EXIT

echo "→ migrate + seed ($DB)"
php artisan migrate:fresh --seed --force --no-interaction >/dev/null
KEYS="$(php scripts/mcp-contract-seed.php)"

echo "→ API on :$API_PORT"
php -S "127.0.0.1:$API_PORT" -t public public/index.php >"$WORK/api.log" 2>&1 &
pids+=($!)

echo "→ MCP on :$MCP_PORT"
( cd mcp && [ -f dist/index.js ] || npm run build >/dev/null )
PORT="$MCP_PORT" HOST=127.0.0.1 MCP_AUTH_MODE=real MCP_INTROSPECT_SECRET="$SECRET" \
  V1_API_URL="http://127.0.0.1:$API_PORT/api/v1" MCP_RESOURCE_URL="http://127.0.0.1:$MCP_PORT/mcp" \
  MCP_AUTHORIZATION_SERVER="http://127.0.0.1:$API_PORT" POSTHOG_KEY= \
  node mcp/dist/index.js >"$WORK/mcp.log" 2>&1 &
pids+=($!)

wait_for() {
  for _ in $(seq 1 60); do curl -fsS -o /dev/null "$1" 2>/dev/null && return 0; sleep 0.5; done
  echo "✗ $1 did not come up"; cat "$WORK/api.log" "$WORK/mcp.log"; return 1
}
wait_for "http://127.0.0.1:$API_PORT/up"
wait_for "http://127.0.0.1:$MCP_PORT/.well-known/oauth-protected-resource"

echo "→ contract tests"
status=0
( cd mcp && MCP_CONTRACT_URL="http://127.0.0.1:$MCP_PORT/mcp" \
    MCP_CONTRACT_KEY_FULL="$(node -pe 'JSON.parse(process.argv[1]).full' "$KEYS")" \
    MCP_CONTRACT_KEY_READ="$(node -pe 'JSON.parse(process.argv[1]).read' "$KEYS")" \
    npx vitest run -c vitest.contract.config.ts ) || status=$?
if [ "$status" -ne 0 ]; then
  echo "--- API log (tail) ---"; tail -n 60 "$WORK/api.log"
  echo "--- MCP log (tail) ---"; tail -n 60 "$WORK/mcp.log"
fi
exit "$status"
