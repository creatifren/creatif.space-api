# ops/sites — app + marketing deploy scripts (copy of the server)

The VPS runs these from `/root/` (`MKT_DEPLOY_CMD` / `APP_DEPLOY_CMD` in `/etc/cs-deploy.env`).
This folder is a **copy** for history and review, taken from the server on 19 Nov 2026.
Change the server copy and this copy together (aaPanel terminal, Rizal's OK).

| File | Server path | What |
|---|---|---|
| `d-site.sh app\|mkt` | `/root/d-site.sh` | build, rsync into the non-live colour (keeps `.user.ini`), blue/green switch |
| `build-site.sh` | `/root/build-site.sh` | fresh `--depth 1` clone via the deploy-key alias, copy `.env*`, install + build |

The `.user.ini` fix = `--exclude=.user.ini` on rsync + `! -name .user.ini` on chown (aaPanel's
immutable `.user.ini` made both fail).

## Pending (needs Rizal's OK to change the server copy)
IndexNow per marketing deploy: after the blue/green switch, ping new/changed `content/blog/*.md`
as `/blog/<slug>` with `node scripts/indexnow-submit.mjs <paths>` (env from `.env.local`),
tracking hashes in `/root/indexnow-mkt.last`; never fail the deploy.
