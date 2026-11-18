#!/usr/bin/env bash
# Local test for cs-deploy.sh: two fake repos, fake deploy commands, a stub curl. No network, no root.
#   bash ops/autodeploy/test/cs-deploy.test.sh
set -euo pipefail
HERE="$(cd "$(dirname "$0")/.." && pwd)"
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
pass=0; fail=0
ok() { echo "ok   $1"; pass=$((pass + 1)); }
no() { echo "FAIL $1"; fail=$((fail + 1)); }
git_q() { git -c user.email=t@t -c user.name=t -c init.defaultBranch=main "$@" -q; }

mk_repo() { # name
  git_q init "$T/src-$1"
  echo 1 > "$T/src-$1/a.txt"; git -C "$T/src-$1" add .; git_q -C "$T/src-$1" commit -m init
  git_q clone --bare "$T/src-$1" "$T/$1.git"
  git -C "$T/src-$1" remote add origin "$T/$1.git"
}
commit() { # name file msg
  mkdir -p "$(dirname "$T/src-$1/$2")"; date +%s%N > "$T/src-$1/$2"
  git -C "$T/src-$1" add .; git_q -C "$T/src-$1" commit -m "$3"; git_q -C "$T/src-$1" push origin main
  :
}
head_of() { git --git-dir="$T/$1.git" rev-parse main; }

mk_repo api; mk_repo app
# Stub curl: answers 200 unless $T/smoke-fail exists.
cat > "$T/curl" <<EOF
#!/usr/bin/env bash
[[ -e "$T/smoke-fail" ]] && echo 500 || echo 200
EOF
chmod +x "$T/curl"

cat > "$T/env" <<EOF
SITES="api app"
STATE_DIR=$T/state
LOG_DIR=$T/logs
PAUSE_FILE=$T/paused
LOCK_FILE=$T/lock
CURL=$T/curl
SMOKE_TRIES=1
SMOKE_SLEEP=0
API_URL=$T/api.git
API_DEPLOY_CMD="echo deployed >> $T/api.deploys"
API_SMOKE="https://x/up=200"
API_MIGRATION_PATHS="database/migrations"
APP_URL=$T/app.git
APP_DEPLOY_CMD="test ! -e $T/app-break && echo deployed >> $T/app.deploys"
APP_SMOKE="https://x/login=200"
APP_ROLLBACK_CMD="echo rolledback >> $T/app.rollbacks"
EOF
run() { CS_DEPLOY_CONFIG="$T/env" bash "$HERE/cs-deploy.sh" > /dev/null 2>&1 || true; }
count() { [[ -f "$1" ]] && wc -l < "$1" | tr -d ' ' || echo 0; }
state() { grep -o "\"site\":\"$1\",\"state\":\"[a-z-]*\"" "$T/state/status.json" | sed 's/.*state":"//; s/"//'; }

# 1. First run, no live file: deploys both.
run
[[ "$(count "$T/api.deploys")" == 1 && "$(count "$T/app.deploys")" == 1 ]] && ok "first run deploys both" || no "first run deploys both"
[[ "$(cat "$T/state/api.live")" == "$(head_of api)" ]] && ok "live sha recorded" || no "live sha recorded"

# 2. Nothing new: no deploy.
run
[[ "$(count "$T/api.deploys")" == 1 ]] && ok "same head does nothing" || no "same head does nothing"
[[ "$(state api)" == live ]] && ok "status live" || no "status live ($(state api))"

# 3. New commit deploys.
commit api app/x.php "feat"; run
[[ "$(count "$T/api.deploys")" == 2 ]] && ok "new head deploys" || no "new head deploys"

# 4. Pause file blocks.
commit app src/y.ts "change"; touch "$T/paused"; run
[[ "$(count "$T/app.deploys")" == 1 && "$(state app)" == paused ]] && ok "pause blocks" || no "pause blocks"
grep -q '"paused":true' "$T/state/status.json" && ok "status shows paused" || no "status shows paused"
rm "$T/paused"; run
[[ "$(count "$T/app.deploys")" == 2 ]] && ok "unpause deploys" || no "unpause deploys"

# 5. Migration without [migrate-ok] is held; with it, deploys.
commit api database/migrations/2026_x.php "add column"; run
[[ "$(count "$T/api.deploys")" == 2 && "$(state api)" == held ]] && ok "migration held" || no "migration held ($(state api))"
commit api app/z.php "follow-up [migrate-ok]"; run
[[ "$(count "$T/api.deploys")" == 3 ]] && ok "[migrate-ok] deploys" || no "[migrate-ok] deploys"

# 6. .deploy-hold holds.
commit app .deploy-hold "hold"; run
[[ "$(count "$T/app.deploys")" == 2 && "$(state app)" == held ]] && ok ".deploy-hold holds" || no ".deploy-hold holds"
git -C "$T/src-app" rm -q .deploy-hold; git_q -C "$T/src-app" commit -m unhold; git_q -C "$T/src-app" push origin main; run
[[ "$(count "$T/app.deploys")" == 3 ]] && ok "removing hold deploys" || no "removing hold deploys"

# 7. Smoke fail: rollback, failed, not retried; live unchanged.
before="$(cat "$T/state/app.live")"
commit app src/bad.ts "bad"; touch "$T/smoke-fail"; run
[[ "$(count "$T/app.rollbacks")" == 1 && "$(state app)" == failed ]] && ok "smoke fail rolls back" || no "smoke fail rolls back"
[[ "$(cat "$T/state/app.live")" == "$before" ]] && ok "live unchanged after fail" || no "live unchanged after fail"
rm "$T/smoke-fail"; run
[[ "$(count "$T/app.deploys")" == 4 && "$(state app)" == failed ]] && ok "failed head not retried" || no "failed head not retried"
commit app src/fix.ts "fix"; run
[[ "$(count "$T/app.deploys")" == 5 && "$(state app)" == live ]] && ok "next commit deploys" || no "next commit deploys"

# 8. Deploy command fails: failed + rollback.
commit app src/q.ts "q"; touch "$T/app-break"; run
[[ "$(state app)" == failed && "$(count "$T/app.rollbacks")" == 2 ]] && ok "deploy cmd fail -> failed + rollback" || no "deploy cmd fail"
rm "$T/app-break"

# 9. Status JSON is valid.
python3 -c "import json,sys; json.load(open('$T/state/status.json'))" && ok "status.json valid JSON" || no "status.json valid JSON"

echo "$pass passed, $fail failed"
[[ $fail -eq 0 ]]
