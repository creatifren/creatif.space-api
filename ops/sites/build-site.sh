#!/bin/bash
set -e
NAME=$1; ALIAS=$2; REPO=$3; SITE=/www/wwwroot/$4; B=/root/build-$NAME
export PATH=/www/server/nodejs/v24.18.0/bin:$PATH COREPACK_ENABLE_DOWNLOAD_PROMPT=0
rm -rf $B; git clone -q --depth 1 git@$ALIAS:creatifren/$REPO.git $B
cp $SITE/.env* $B/ 2>/dev/null || true
cd $B; git log --oneline -1 | cat
if [ -f pnpm-lock.yaml ]; then corepack pnpm install --frozen-lockfile; corepack pnpm run build; else npm ci; npm run build; fi
echo BUILD_OK $NAME
