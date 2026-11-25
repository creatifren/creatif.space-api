#!/bin/bash
# Deploy app or mkt under supervisor (14 Nov 2026, QA): build as root, copy into the NON-live colour, then ops/bluegreen.sh --no-build.
set -euo pipefail
S=$1; case $S in app) A=gh-app; R=creatif.space; SITE=app.creatif.space; GP=3101;; mkt) A=gh-mkt; R=creatifspace; SITE=creatif.space; GP=3100;; *) echo 'usage: d-site.sh app|mkt'; exit 1;; esac
LIVE=$(grep -oE '127.0.0.1:[0-9]+' /www/server/nginx/conf/creatif-upstreams/$S.conf | cut -d: -f2)
if [ "$LIVE" = "$GP" ]; then T=blue; D=/www/wwwroot/$SITE; else T=green; D=/www/wwwroot/$SITE-green; fi
echo "$(date -u +%T) live port $LIVE -> target $T ($D)"
bash /root/build-site.sh $S $A $R $SITE
rsync -a --delete --exclude=.git/ --exclude=.user.ini /root/build-$S/ $D/
# QA-01 (25 Nov 2026): keep the previous build's hashed chunks so tabs/pages opened before the switch still load (no 404 chunks).
if [ "$T" = blue ]; then OLD=/www/wwwroot/$SITE-green; else OLD=/www/wwwroot/$SITE; fi; if [ -d "$OLD/.next/static" ]; then rsync -a --ignore-existing "$OLD/.next/static/" "$D/.next/static/"; fi
find "$D" ! -name .user.ini -exec chown -h www:www {} +
/opt/creatif-ops/bluegreen.sh $S --to $T --no-build
# IndexNow (19 Nov 2026): after a live mkt deploy, ping new/changed blog posts (Bing/Yandex/Naver). Never fails the deploy.
if [ "$S" = mkt ]; then
  ( cd /root/build-mkt && export PATH=/www/server/nodejs/v24.18.0/bin:$PATH
    NOW=$(cd content/blog && md5sum *.md | sort)
    P=$(comm -13 <(sort /root/indexnow-mkt.last 2>/dev/null) <(echo "$NOW") | awk '{sub(/\.md$/,"",$2); print "/blog/"$2}')
    if [ -n "$P" ]; then set -a; . ./.env.local; set +a; node scripts/indexnow-submit.mjs $P && echo "$NOW" > /root/indexnow-mkt.last; else echo "indexnow: no blog changes"; fi
  ) || echo "indexnow: skipped (error)"
fi
