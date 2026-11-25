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

## IndexNow (live 19 Nov 2026, Rizal yes)
After a live marketing deploy, `d-site.sh mkt` pings new/changed `content/blog/*.md` as
`/blog/<slug>` (`node scripts/indexnow-submit.mjs <paths>`, env from `.env.local`). Hashes of the
last pinged set live in `/root/indexnow-mkt.last` (seeded 19 Nov with 10 posts after a one-off
`--all` of 81 URLs). Errors print "indexnow: skipped" and never fail the deploy.
Previous server copy: `/root/d-site.sh.bak-20261119`.

## Keep old chunks (QA-01, live 25 Nov 2026, Rizal yes)
After the rsync into the non-live colour, `d-site.sh` copies the live colour's `.next/static/` in with
`--ignore-existing` (hashed names, so nothing is overwritten). A page or tab opened before the switch can
still load its lazy chunks instead of 404. One generation is kept. Previous copy: `/root/d-site.sh.bak-20261125`.

## Cloudflare purge after a mkt deploy (25 Nov 2026, Rizal "B")
The cache rule "Marketing HTML cache (QA, Rizal OK 11 Nov)" keeps marketing HTML 2 h at the edge (the Free plan
minimum). After the blue/green switch, `d-site.sh mkt` purges the zone when `/etc/cs-cf-purge.env` exists
(root, mode 600, written by Rizal — never printed): `CF_ZONE_ID=<zone id>` and `CF_API_TOKEN=<token with
Zone › Cache Purge › Purge on creatif.space only>`. Output says `cf-purge: done | failed | skipped`; it never fails
the deploy. Old chunks stay served for one build (see "Keep old chunks"), so a stale page never 404s its JS.
