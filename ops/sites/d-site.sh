#!/bin/bash
# Deploy app or mkt under supervisor (14 Nov 2026, QA): build as root, copy into the NON-live colour, then ops/bluegreen.sh --no-build.
set -euo pipefail
S=$1; case $S in app) A=gh-app; R=creatif.space; SITE=app.creatif.space; GP=3101;; mkt) A=gh-mkt; R=creatifspace; SITE=creatif.space; GP=3100;; *) echo 'usage: d-site.sh app|mkt'; exit 1;; esac
LIVE=$(grep -oE '127.0.0.1:[0-9]+' /www/server/nginx/conf/creatif-upstreams/$S.conf | cut -d: -f2)
if [ "$LIVE" = "$GP" ]; then T=blue; D=/www/wwwroot/$SITE; else T=green; D=/www/wwwroot/$SITE-green; fi
echo "$(date -u +%T) live port $LIVE -> target $T ($D)"
bash /root/build-site.sh $S $A $R $SITE
rsync -a --delete --exclude=.git/ --exclude=.user.ini /root/build-$S/ $D/
find "$D" ! -name .user.ini -exec chown -h www:www {} +
/opt/creatif-ops/bluegreen.sh $S --to $T --no-build
