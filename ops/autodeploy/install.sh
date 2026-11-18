#!/usr/bin/env bash
# Install (or remove) the Creatif Space auto-deploy timer. Run as root on the VPS, once:
#
#   curl -fsSL https://raw.githubusercontent.com/creatifren/creatif.space-api/main/ops/autodeploy/install.sh | bash
#
# Options (after `bash -s --`):  --ref <git ref>   install the files from that ref (default main)
#                                --uninstall      stop the timer and remove units + nginx snippet (state kept)
# What it does: backs up, copies the script to /opt/cs-deploy, writes /etc/cs-deploy.env if missing,
# checks the deploy scripts run in the foreground and that git can read every repo, marks today's main
# heads as live (installing deploys nothing), serves /deploy-status.json on api.creatif.space, enables the
# timer. Nothing touches Loyal Digicard.
set -euo pipefail

REF=main; UNINSTALL=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --ref) REF="$2"; shift ;;
    --uninstall) UNINSTALL=1 ;;
    *) echo "unknown option $1"; exit 1 ;;
  esac
  shift
done

[[ $EUID -eq 0 ]] || { echo "Run as root."; exit 1; }
RAW="https://raw.githubusercontent.com/creatifren/creatif.space-api/$REF/ops/autodeploy"
NGX_EXT=/www/server/panel/vhost/nginx/extension/api.creatif.space
NGX_CONF="$NGX_EXT/cs-deploy-status.conf"
NGINX_BIN="$(command -v nginx || echo /www/server/nginx/sbin/nginx)"
TS="$(date -u +%Y%m%d-%H%M%S)"

if [[ $UNINSTALL -eq 1 ]]; then
  systemctl disable --now cs-deploy.timer 2>/dev/null || true
  rm -f /etc/systemd/system/cs-deploy.service /etc/systemd/system/cs-deploy.timer
  systemctl daemon-reload
  if [[ -f "$NGX_CONF" ]]; then rm -f "$NGX_CONF"; "$NGINX_BIN" -t && "$NGINX_BIN" -s reload; fi
  echo "UNINSTALL_OK (state in /var/lib/cs-deploy and /etc/cs-deploy.env kept)"
  exit 0
fi

echo "== 1/7 backup"
B="/root/backups/cs-deploy-$TS"; mkdir -p "$B"
cp -a /etc/cs-deploy.env "$B/" 2>/dev/null || true
cp -a /opt/cs-deploy "$B/" 2>/dev/null || true
[[ -d "$NGX_EXT" ]] && cp -a "$NGX_EXT" "$B/nginx-extension" || true
echo "BACKUP_OK $B"

echo "== 2/7 files from $REF"
mkdir -p /opt/cs-deploy
for f in cs-deploy.sh cs-deploy.service cs-deploy.timer cs-deploy.env.example; do
  curl -fsSL "$RAW/$f" -o "/opt/cs-deploy/$f"
done
chmod 755 /opt/cs-deploy/cs-deploy.sh
if [[ ! -f /etc/cs-deploy.env ]]; then
  cp /opt/cs-deploy/cs-deploy.env.example /etc/cs-deploy.env
  chmod 600 /etc/cs-deploy.env
  echo "wrote /etc/cs-deploy.env"
fi
echo "FILES_OK"

echo "== 3/7 preflight"
# shellcheck disable=SC1091
source /etc/cs-deploy.env
fail=0
for site in $SITES; do
  up="$(echo "$site" | tr 'a-z-' 'A-Z_')"; url_var="${up}_URL"; cmd_var="${up}_DEPLOY_CMD"
  for script in $(grep -oE '/root/[A-Za-z0-9_.-]+\.sh' <<< "${!cmd_var}"); do
    if [[ ! -f "$script" ]]; then echo "MISSING $script ($site)"; fail=1; continue; fi
    if grep -nE '^[^#]*(nohup|disown|setsid)|&[[:space:]]*$' "$script" >/dev/null; then
      echo "BACKGROUND $script ($site): it backgrounds its work, so the smoke test would run too early:"
      grep -nE '^[^#]*(nohup|disown|setsid)|&[[:space:]]*$' "$script" | head -5
      fail=1
    fi
  done
  head="$(HOME=/root git ls-remote "${!url_var}" refs/heads/main 2>/dev/null | awk '{print $1}')"
  if [[ -z "$head" ]]; then echo "NO_ACCESS ${!url_var} ($site)"; fail=1; else echo "OK $site main=$head"; fi
done
if [[ $fail -ne 0 ]]; then
  echo "PREFLIGHT_FAIL: timer NOT enabled. Send this output to the Builder chat."
  exit 2
fi
echo "PREFLIGHT_OK"

echo "== 4/7 mark today's heads as live (install deploys nothing)"
mkdir -p /var/lib/cs-deploy /var/log/cs-deploy
chmod 755 /var/lib/cs-deploy
for site in $SITES; do
  up="$(echo "$site" | tr 'a-z-' 'A-Z_')"; url_var="${up}_URL"
  if [[ ! -s "/var/lib/cs-deploy/$site.live" ]]; then
    HOME=/root git ls-remote "${!url_var}" refs/heads/main | awk '{print $1}' > "/var/lib/cs-deploy/$site.live"
  fi
  echo "$site live=$(cat "/var/lib/cs-deploy/$site.live")"
done

echo "== 5/7 status page"
if [[ -d "$NGX_EXT" ]]; then
  cat > "$NGX_CONF" <<'EOF'
# cs-deploy status (no secrets). Added by ops/autodeploy/install.sh.
location = /deploy-status.json {
    alias /var/lib/cs-deploy/status.json;
    default_type application/json;
    add_header Cache-Control "no-store" always;
}
EOF
  if "$NGINX_BIN" -t 2>/dev/null; then "$NGINX_BIN" -s reload; echo "STATUS_PAGE_OK https://api.creatif.space/deploy-status.json"
  else rm -f "$NGX_CONF"; echo "STATUS_PAGE_SKIPPED (nginx -t failed, snippet removed)"; fi
else
  echo "STATUS_PAGE_SKIPPED (no $NGX_EXT)"
fi

echo "== 6/7 timer"
cp /opt/cs-deploy/cs-deploy.service /opt/cs-deploy/cs-deploy.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now cs-deploy.timer
echo "TIMER_OK"

echo "== 7/7 first run"
systemctl start cs-deploy.service || true
cat /var/lib/cs-deploy/status.json 2>/dev/null; echo
echo "INSTALL_OK"
