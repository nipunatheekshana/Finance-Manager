# Deployment (Hostinger + GitHub Actions)

The app deploys to **https://gold-wolf-357052.hostingersite.com** (Hostinger Premium shared hosting) via GitHub Actions.

## One-time setup

1. Move the workflow file into place (it couldn't be written there automatically):

   ```
   mkdir -p .github/workflows && mv deploy/github-workflow-ci-cd.yml .github/workflows/ci-cd.yml
   ```

2. Add the four GitHub Actions secrets listed below at
   https://github.com/nipunatheekshana/Finance-Manager/settings/secrets/actions

3. Commit and push to `main` — the first run tests, deploys, and fully bootstraps the server.

## How the pipeline works (`.github/workflows/ci-cd.yml`)

- **Pull requests to `main`** → run the PHPUnit suite against MySQL 8, the same engine production uses (credentials match `phpunit.xml`).
- **Push to `main`** → run tests, and if they pass:
  1. Build the production release on the runner: `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build` (Vite assets + PWA service worker).
  2. Upload with `rsync --delete` over SSH to `~/domains/gold-wolf-357052.hostingersite.com/finance-manager/` (never touches `.env`, `storage/`, or the SQLite database).
  3. Run `deploy/server-deploy.sh` on the server: first-run setup (creates `.env` from `deploy/env.production`, generates `APP_KEY` server-side, symlinks `public_html -> finance-manager/public` and `storage:link`), writes the database credentials into `.env`, verifies the connection with `db:show`, then `migrate --force` and config/view/route caching.

## Required GitHub Actions secrets

Add at **Settings → Secrets and variables → Actions** in this repo:

| Secret | Value |
|---|---|
| `SSH_HOST` | `46.202.138.97` |
| `SSH_PORT` | `65002` |
| `SSH_USER` | `u724764963` |
| `SSH_PRIVATE_KEY` | the ed25519 private key whose public half is registered in hPanel → Advanced → SSH Access (key name `github-actions-finance-manager`) |
| `DB_DATABASE` | the MySQL database name from hPanel → Databases |
| `DB_USERNAME` | the MySQL user |
| `DB_PASSWORD` | that user's password |
| `DB_HOST` | optional; defaults to `localhost`, which is correct for Hostinger shared hosting |

Database credentials are **secrets, not repository files**. `deploy/env.production`
is committed, so it carries empty `DB_*` placeholders; `server-deploy.sh` writes
the real values into the server's `.env` on every deploy. They are base64-encoded
over SSH so a password containing commas or quotes cannot break the remote
command or leak into a process list.

## Production config

`deploy/env.production` is the template for the server's `.env`, created on the
first deploy only. `APP_KEY` is generated on the server and never leaves it.

**Production uses MySQL.** It was previously SQLite, which is what broke the
dashboard and reports: the app is written and tested against MySQL, and two
queries used MySQL-only functions that SQLite does not have. The database
settings are the one part of `.env` the deploy rewrites every run, so changing
the secrets is enough to point the app at a different database — no manual
editing on the server.

If the connection fails, the deploy aborts before migrating rather than leaving
the site serving 500s, and `db:show` output appears in the Actions log.

### Moving data off the old SQLite database

The previous SQLite file is still at `finance-manager/storage/database.sqlite`
and is not deleted by deploys. Switching to MySQL starts from empty tables — the
first deploy runs `migrate --force` against the new database, it does not copy
anything across. If that file holds data worth keeping, migrate it before
relying on the MySQL database.

## Scheduler

The app's scheduled commands (alert refresh at 06:00/20:00, plan close at 00:15) require an hPanel cron job running every minute:

```
cd ~/domains/gold-wolf-357052.hostingersite.com/finance-manager && php artisan schedule:run >> /dev/null 2>&1
```

## Rotating the deploy key

Generate a new keypair (`ssh-keygen -t ed25519`), replace the key in hPanel → SSH Access, update the `SSH_PRIVATE_KEY` secret, delete the old key.
