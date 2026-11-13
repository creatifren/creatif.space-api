# Staging (Creem TEST mode)

A copy of the API for QA, with Creem in test mode and nothing reaching real
people or real accounts: mail is written to the log, no social posting key,
no AI key, no analytics. Every response carries
`X-Robots-Tag: noindex, nofollow`.

| What | Where |
| --- | --- |
| API checkout | `/www/wwwroot/staging-api` |
| API settings | `/www/wwwroot/staging-api/.env`, from [`.env.staging.example`](.env.staging.example) |
| MCP server | `/www/wwwroot/staging-api/mcp` (built by the deploy script), settings in `mcp/.env` |
| Supervisor programs | `staging-api-worker`, `staging-api-scheduler`, `staging-mcp` ([`supervisor/`](supervisor)) |
| Deploy | [`deploy-staging-api.sh`](deploy-staging-api.sh) |

The web app (`staging-app`) is deployed from the frontend repository, not
this one: there is no `deploy-staging-app.sh` here. Point its API URL at the
staging API host.

Host names below (`staging-api.creatif.space`, `staging-app.creatif.space`,
`staging-mcp.creatif.space`) are placeholders: use the ones the DNS really
has, in `.env` and in the web server's site config.

## First install

1. **Back up first.** Take a database dump and a copy of the site directory
   of anything already at `/www/wwwroot/staging-api`. Staging never shares a
   database, bucket or key with production; check that before going on.
2. **Code.** Clone the repository into `/www/wwwroot/staging-api` as the
   site's user. Point the site's document root at
   `/www/wwwroot/staging-api/public`, on PHP 8.4.
3. **Database.** Create an empty database and a user with rights on it only:

   ```sql
   CREATE DATABASE creatif_staging CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'creatif_staging'@'localhost' IDENTIFIED BY '<a new password>';
   GRANT ALL PRIVILEGES ON creatif_staging.* TO 'creatif_staging'@'localhost';
   ```

4. **Settings.** `cp ops/staging/.env.staging.example .env`, then fill in:
   - `DB_PASSWORD`;
   - the Creem **test** API key, webhook secret and test product ids;
   - the host names;
   - `MCP_INTROSPECT_SECRET` (`openssl rand -hex 32`).

   Leave the Outstand, AI and PostHog keys empty. Then:

   ```bash
   composer install --no-dev --optimize-autoloader
   php artisan key:generate
   ```

5. **Migrate and seed:**

   ```bash
   php artisan migrate --force
   php artisan db:seed --force
   ```

   The seed adds roles, plans, reserved handles and a staff account,
   `owner@creatif.space`, whose password is `password`. **Change that
   password right after seeding** (sign in, Settings), or remove the account.
6. **MCP server settings.** Copy `mcp/.env.example` to `mcp/.env` and set:
   - `V1_API_URL=https://staging-api.creatif.space/api/v1`;
   - `MCP_AUTHORIZATION_SERVER=https://staging-api.creatif.space`;
   - `MCP_RESOURCE_URL` (the same value as in the API `.env`);
   - the same `MCP_INTROSPECT_SECRET`;
   - `PORT`: one that production's MCP server does not use;
   - leave `POSTHOG_KEY` empty.
7. **Supervisor.** Copy the three files in `supervisor/` to the supervisor
   configuration directory, set `user=` to the site's user, then
   `supervisorctl reread && supervisorctl update`. The programs are all
   `staging-*`: they never stop or restart production's.
8. **Creem webhook (test mode).** In the Creem dashboard, in test mode, add a
   webhook to `https://staging-api.creatif.space/api/v1/webhooks/creem` with
   the secret from `CREEM_WEBHOOK_SECRET`.
9. **First deploy:** `ops/staging/deploy-staging-api.sh`. It ends with the
   output of `/api/v1/health`.

## Every deploy

```bash
/www/wwwroot/staging-api/ops/staging/deploy-staging-api.sh            # main
/www/wwwroot/staging-api/ops/staging/deploy-staging-api.sh my-branch  # a branch or commit
```

The script refuses to run unless `.env` says `APP_ENV=staging` and
`CREEM_TEST_MODE=true`. It then:
1. puts the site in maintenance mode;
2. fetches the ref and installs dependencies;
3. creates the OAuth keys on the first run;
4. migrates;
5. rebuilds the caches (`php artisan optimize`, so the route cache always matches the code);
6. builds the MCP server;
7. restarts the queue worker and the `staging-*` programs;
8. brings the site back up and prints the health check.

The site comes back up even if a step fails.

## Notes

- Use a separate browser profile for staging. Both sites set cookies on
  `.creatif.space`; the session cookie name differs, but the CSRF cookie
  does not.
- Mail is in `storage/logs/laravel.log` (`MAIL_MAILER=log`): sign-in links,
  verification and receipts are read there.
- Nothing secret belongs in this directory. Keys live only in the server's
  `.env` files.
