#!/usr/bin/env bash
# cs-deploy.sh — headless auto-deploy for Creatif Space (Workflow v4, 18 Nov 2026).
#
# A systemd timer runs this every 2 minutes as root. For every site in SITES it:
#   1. reads the main head with `git ls-remote` (the VPS deploy keys / public URL; nothing inbound);
#   2. does nothing if that head is already live, already failed, or deploys are paused;
#   3. holds the head if it changes a migration path and the head commit message lacks [migrate-ok];
#   4. holds the head if the repo has a .deploy-hold file at that commit;
#   5. runs the site's existing deploy command (the same scripts used by hand in aaPanel);
#   6. curls the smoke list; PASS -> head becomes live; FAIL -> runs the rollback command (if any)
#      and remembers the head as failed, so it is not retried until a new commit lands;
#   7. writes a status JSON (no secrets) that the API vhost serves at /deploy-status.json.
#
# Rule in the repos: only code that passed ./scripts/check.sh reaches main. main == deployable.
# Pause everything:      touch /root/cs-deploy.paused        (or a .deploy-hold file in a repo)
# Retry a failed head:   rm /var/lib/cs-deploy/<site>.failed
# Config:                /etc/cs-deploy.env (see cs-deploy.env.example)
set -uo pipefail

CONFIG="${CS_DEPLOY_CONFIG:-/etc/cs-deploy.env}"
# shellcheck disable=SC1090
[[ -r "$CONFIG" ]] && source "$CONFIG"

STATE_DIR="${STATE_DIR:-/var/lib/cs-deploy}"
LOG_DIR="${LOG_DIR:-/var/log/cs-deploy}"
STATUS_FILE="${STATUS_FILE:-$STATE_DIR/status.json}"
PAUSE_FILE="${PAUSE_FILE:-/root/cs-deploy.paused}"
LOCK_FILE="${LOCK_FILE:-/run/cs-deploy.lock}"
DEPLOY_TIMEOUT="${DEPLOY_TIMEOUT:-1500}"
SMOKE_TRIES="${SMOKE_TRIES:-5}"
SMOKE_SLEEP="${SMOKE_SLEEP:-6}"
LOG_KEEP_DAYS="${LOG_KEEP_DAYS:-14}"
CLEAN_PATH="${CLEAN_PATH:-/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin}"
GIT="${GIT:-git}"
CURL="${CURL:-curl}"
DRY_RUN="${DRY_RUN:-0}"

mkdir -p "$STATE_DIR" "$LOG_DIR"
exec 9>"$LOCK_FILE"
flock -n 9 || exit 0   # a deploy is still running: the next tick picks up

now() { date -u +%Y-%m-%dT%H:%M:%SZ; }
log() { echo "$(now) $*"; }
json_str() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g' | tr -d '\n\r'; }

# One status entry per site, kept in $STATE_DIR/<site>.status.json, merged into $STATUS_FILE.
set_status() { # site state sha detail
  local site="$1" state="$2" sha="$3" detail="$4"
  printf '{"site":"%s","state":"%s","sha":"%s","live":"%s","at":"%s","detail":"%s"}\n' \
    "$site" "$state" "$sha" "$(cat "$STATE_DIR/$site.live" 2>/dev/null)" "$(now)" "$(json_str "$detail")" \
    > "$STATE_DIR/$site.status.json"
}
write_status() {
  local tmp paused=false
  [[ -e "$PAUSE_FILE" ]] && paused=true
  tmp="$(mktemp "$STATE_DIR/.status.XXXX")"
  {
    printf '{"updated":"%s","paused":%s,"sites":[' "$(now)" "$paused"
    local first=1 f
    for f in "$STATE_DIR"/*.status.json; do
      [[ -e "$f" ]] || continue
      [[ $first -eq 1 ]] || printf ','
      first=0
      tr -d '\n' < "$f"
    done
    printf ']}\n'
  } > "$tmp"
  chmod 644 "$tmp"
  mv -f "$tmp" "$STATUS_FILE"
}

# Shallow cache of each repo, used only to read the head commit (message, .deploy-hold, changed paths).
cache_fetch() { # site url sha live
  local site="$1" url="$2" sha="$3" live="$4" dir="$STATE_DIR/cache/$site.git"
  [[ -d "$dir" ]] || "$GIT" init -q --bare "$dir"
  "$GIT" -C "$dir" fetch -q --depth=50 "$url" "+refs/heads/main:refs/heads/main" || return 1
  "$GIT" -C "$dir" cat-file -e "$sha^{commit}" 2>/dev/null || return 1
  [[ -n "$live" ]] && ! "$GIT" -C "$dir" cat-file -e "$live^{commit}" 2>/dev/null \
    && "$GIT" -C "$dir" fetch -q --depth=500 "$url" "+refs/heads/main:refs/heads/main"
  return 0
}

smoke() { # "url=code url=code ..."
  local item url want got try
  for item in $1; do
    url="${item%=*}"; want="${item##*=}"
    for ((try = 1; try <= SMOKE_TRIES; try++)); do
      got="$("$CURL" -s -o /dev/null -w '%{http_code}' --max-time 15 "$url" || echo 000)"
      [[ "$got" == "$want" ]] && break
      sleep "$SMOKE_SLEEP"
    done
    if [[ "$got" != "$want" ]]; then echo "$url=$got (want $want)"; return 1; fi
  done
  return 0
}

run_clean() { # cmd logfile
  timeout "$DEPLOY_TIMEOUT" env -i HOME=/root PATH="$CLEAN_PATH" ${EXTRA_ENV:-} bash -c "$1" >> "$2" 2>&1
}

deploy_site() { # site
  local site="$1" up; up="$(echo "$site" | tr 'a-z-' 'A-Z_')"
  local url_var="${up}_URL" cmd_var="${up}_DEPLOY_CMD" smoke_var="${up}_SMOKE" rb_var="${up}_ROLLBACK_CMD" mig_var="${up}_MIGRATION_PATHS"
  local url="${!url_var:-}" cmd="${!cmd_var:-}" smokes="${!smoke_var:-}" rollback="${!rb_var:-}" migpaths="${!mig_var:-}"
  [[ -n "$url" && -n "$cmd" ]] || { log "$site: not configured"; return; }

  local head live failed
  head="$("$GIT" ls-remote "$url" refs/heads/main 2>/dev/null | awk '{print $1}')"
  if [[ -z "$head" ]]; then set_status "$site" error "" "ls-remote failed"; return; fi
  live="$(cat "$STATE_DIR/$site.live" 2>/dev/null || true)"
  failed="$(cat "$STATE_DIR/$site.failed" 2>/dev/null || true)"

  if [[ "$head" == "$live" ]]; then set_status "$site" live "$head" "up to date"; return; fi
  if [[ "$head" == "$failed" ]]; then set_status "$site" failed "$head" "deploy failed earlier; push a fix or rm $STATE_DIR/$site.failed"; return; fi
  if [[ -e "$PAUSE_FILE" ]]; then set_status "$site" paused "$head" "pause file present"; return; fi

  if ! cache_fetch "$site" "$url" "$head" "$live"; then set_status "$site" error "$head" "fetch failed"; return; fi
  local cache="$STATE_DIR/cache/$site.git" msg
  msg="$("$GIT" -C "$cache" log -1 --format=%B "$head")"
  if "$GIT" -C "$cache" cat-file -e "$head:.deploy-hold" 2>/dev/null; then
    set_status "$site" held "$head" ".deploy-hold in repo"; return
  fi
  if [[ -n "$migpaths" && -n "$live" ]] && "$GIT" -C "$cache" cat-file -e "$live^{commit}" 2>/dev/null; then
    # shellcheck disable=SC2086
    if [[ -n "$("$GIT" -C "$cache" diff --name-only "$live" "$head" -- $migpaths)" ]] && [[ "$msg" != *"[migrate-ok]"* ]]; then
      set_status "$site" held "$head" "migration without [migrate-ok] in the head commit message"; return
    fi
  fi

  local logf="$LOG_DIR/$site-$(date -u +%Y%m%d-%H%M%S).log"
  log "$site: deploy $live -> $head" | tee -a "$logf"
  set_status "$site" deploying "$head" "log $(basename "$logf")"; write_status
  if [[ "$DRY_RUN" == 1 ]]; then echo "DRY_RUN: $cmd" >> "$logf"; set_status "$site" dry-run "$head" "would deploy"; return; fi

  local rc=0
  run_clean "$cmd" "$logf" || rc=$?
  if [[ $rc -ne 0 ]]; then
    echo "$head" > "$STATE_DIR/$site.failed"
    set_status "$site" failed "$head" "deploy command failed (exit $rc), log $(basename "$logf")"
    [[ -n "$rollback" ]] && run_clean "$rollback" "$logf"
    return
  fi
  local bad
  if bad="$(smoke "$smokes")"; then
    echo "$head" > "$STATE_DIR/$site.live"; rm -f "$STATE_DIR/$site.failed"
    set_status "$site" live "$head" "deployed, smoke PASS"
    log "$site: LIVE $head" >> "$logf"
  else
    echo "$head" > "$STATE_DIR/$site.failed"
    log "$site: SMOKE FAIL $bad" >> "$logf"
    if [[ -n "$rollback" ]]; then
      run_clean "$rollback" "$logf" && log "$site: rolled back" >> "$logf"
      set_status "$site" failed "$head" "smoke FAIL ($bad), rolled back, log $(basename "$logf")"
    else
      set_status "$site" failed "$head" "smoke FAIL ($bad), no auto-rollback: git revert on main, log $(basename "$logf")"
    fi
  fi
}

for site in ${SITES:-}; do deploy_site "$site"; done
write_status
find "$LOG_DIR" -name '*.log' -mtime +"$LOG_KEEP_DAYS" -delete 2>/dev/null || true
