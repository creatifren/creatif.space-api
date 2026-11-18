# Auto-deploy (VPS timer)

Workflow v4 (18 Nov 2026): **main is always deployable.** Code reaches `main` only after
`./scripts/check.sh` passed in the Builder chat. A systemd timer on the VPS checks the three repos every
2 minutes and deploys a new `main` head with the existing scripts. No GitHub Actions minutes, nothing
inbound, no aaPanel tab.

| Site | Repo | Deploy command (unchanged scripts) | Smoke |
|---|---|---|---|
| `api` | creatif.space-api (public) | `/root/deploy-api.sh` + `/root/deploy-csmcp.sh` | api `/up`, `/api/v1/plans` 200, `/api/v1/inbox/summary` 401, mcp `/health` 200 |
| `app` | creatif.space (`git@gh-app:`) | `/root/d-site.sh app` (foreground; d-app.sh only backgrounds it) | app `/login`, `/post` 200 |
| `mkt` | creatifspace (`git@gh-mkt:`) | `/root/d-site.sh mkt` | `/`, `/pricing` 200 |

## Install (Rizal, once, aaPanel terminal, ~5 min)

```bash
curl -fsSL https://raw.githubusercontent.com/creatifren/creatif.space-api/main/ops/autodeploy/install.sh | bash
```

Ends with `INSTALL_OK`. `PREFLIGHT_FAIL` means nothing was enabled: paste the output to the Builder chat.
Installing deploys nothing: today's heads are marked live.

## Watch (from anywhere, no secrets)

```bash
curl -s https://api.creatif.space/deploy-status.json
```

Per site: `live` · `deploying` · `held` · `paused` · `failed` · `error`, with the sha and a short detail.

## Controls (all from the Builder chat, through git)

| Want | Do |
|---|---|
| Ship | merge to `main` (after `./scripts/check.sh`) |
| Ship a DB migration | Rizal says yes → the merge commit message carries `[migrate-ok]`. Without it the head is **held** (later heads too, until one carries the tag) |
| Hold one repo | commit a `.deploy-hold` file on `main`; delete it to resume |
| Roll back | `git revert` the bad commit on `main` → the timer deploys the revert |
| A failed head | not retried; the next commit on `main` deploys. Force a retry: `rm /var/lib/cs-deploy/<site>.failed` |
| Stop everything (server) | `touch /root/cs-deploy.paused` · resume `rm /root/cs-deploy.paused` |
| Uninstall | `curl -fsSL …/install.sh \| bash -s -- --uninstall` |

## Files

| File | |
|---|---|
| `cs-deploy.sh` | the loop (installed to `/opt/cs-deploy/`) |
| `cs-deploy.env.example` | settings → `/etc/cs-deploy.env` (no secrets) |
| `cs-deploy.service` / `.timer` | systemd units, every 2 min, one run at a time (`flock`) |
| `install.sh` | backup → files → preflight → mark live → status page → timer |
| `test/cs-deploy.test.sh` | local test with fake repos: `bash ops/autodeploy/test/cs-deploy.test.sh` |

Logs: `/var/log/cs-deploy/<site>-<UTC time>.log` (14 days). State: `/var/lib/cs-deploy/`.
The deploy scripts must run in the foreground; the installer refuses scripts that `nohup`/`&` their work.
