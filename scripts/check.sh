#!/usr/bin/env bash
# ./scripts/check.sh — the gate before main (Workflow v4, 18 Nov 2026). main is deployed automatically
# by the VPS timer (ops/autodeploy), so nothing reaches main without a PASS here.
#
# This repo is public, so GitHub Actions minutes are free: the PHP suite (PHPStan + Pest, and MySQL on
# main) stays in CI on the pull request. Locally this runs what changed: the MCP server (mcp/), the
# auto-deploy scripts (ops/autodeploy/) and, when PHP >= 8.4 is present, `composer ci:check`.
# Merge only when this says PASS **and** the PR's CI is green. Migrations: merge commit message needs
# [migrate-ok] (Rizal's yes), or the VPS holds the deploy.
set -uo pipefail
cd "$(dirname "$0")/.."
LOGS=.check-logs; rm -rf "$LOGS"; mkdir -p "$LOGS"
fails=0; t0=$(date +%s)

step() { # name cmd...
  local name="$1"; shift; local s=$(date +%s)
  if "$@" > "$LOGS/$name.log" 2>&1; then echo "PASS $name ($(( $(date +%s) - s ))s)"
  else echo "FAIL $name ($(( $(date +%s) - s ))s) — tail $LOGS/$name.log:"; tail -15 "$LOGS/$name.log" | sed 's/^/   /'; fails=$((fails + 1)); fi
}

git fetch -q --depth=50 origin main 2>/dev/null || true
base="$(git merge-base HEAD origin/main 2>/dev/null || echo origin/main)"
changed="$( { git diff --name-only "$base"; git diff --name-only; git ls-files --others --exclude-standard; } | sort -u)"
echo "changed: $(echo "$changed" | grep -c .) files vs origin/main"

if echo "$changed" | grep -q '^mcp/'; then
  ( cd mcp && { [[ -d node_modules ]] || npm ci --silent; } )
  step mcp-lint bash -c 'cd mcp && npm run -s lint'
  step mcp-typecheck bash -c 'cd mcp && npm run -s typecheck'
  step mcp-test bash -c 'cd mcp && npm test --silent'
fi
if echo "$changed" | grep -q '^ops/autodeploy/'; then
  step autodeploy-test bash ops/autodeploy/test/cs-deploy.test.sh
  step autodeploy-syntax bash -n ops/autodeploy/install.sh
fi
step public-emails bash scripts/check-public-emails.sh

php_changed="$(echo "$changed" | grep -Ev '^(mcp/|docs/|ops/)|\.md$' || true)"
if [[ -n "$php_changed" ]]; then
  if php -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);' 2>/dev/null; then
    { [[ -d vendor ]] || composer install -q --no-interaction; }
    step php-ci composer ci:check
  else
    echo "WAIT php: PHP < 8.4 here — the PR's CI runs PHPStan + Pest (free, public repo). Merge only when it is green."
  fi
fi
if echo "$changed" | grep -q '^database/migrations/'; then
  echo "NOTE migration: needs Rizal's yes; merge commit message must contain [migrate-ok]."
fi

echo "----"
if [[ $fails -eq 0 ]]; then echo "CHECK PASS ($(( $(date +%s) - t0 ))s)"; exit 0
else echo "CHECK FAIL: $fails step(s) — do not merge"; exit 1; fi
