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

- **Pull requests to `main`** → run the PHPUnit suite against MySQL 8 (same credentials as `phpunit.xml`).
- **Push to `main`** → run tests, and if they pass:
  1. Build the production release on the runner: `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build` (Vite assets + PWA service worker).
  2. Upload with `rsync --delete` over SSH to `~/domains/gold-wolf-357052.hostingersite.com/finance-manager/` (never touches `.env`, `storage/`, or the SQLite database).
  3. Run `deploy/server-deploy.sh` on the server: first-run setup (creates `.env` from `deploy/env.production`, generates `APP_KEY` server-side, creates the SQLite DB, symlinks `public_html -> finance-manager/public`, `storage:link`), then `migrate --force` and config/view/route caching.

## Required GitHub Actions secrets

Add at **Settings → Secrets and variables → Actions** in this repo:

| Secret | Value |
|---|---|
| `SSH_HOST` | `46.202.138.97` |
| `SSH_PORT` | `65002` |
| `SSH_USER` | `u724764963` |
| `SSH_PRIVATE_KEY` | the ed25519 private key whose public half is registered in hPanel → Advanced → SSH Access (key name `github-actions-finance-manager`) |

## Production config

`deploy/env.production` is the template for the server's `.env` (created on first deploy only; edit the live `.env` via hPanel File Manager afterwards if needed). Production uses SQLite at `finance-manager/storage/database.sqlite` — outside the rsync'd area, so it survives every deploy. `APP_KEY` is generated on the server and never leaves it.

## Scheduler

The app's scheduled commands (alert refresh at 06:00/20:00, plan close at 00:15) require an hPanel cron job running every minute:

```
cd ~/domains/gold-wolf-357052.hostingersite.com/finance-manager && php artisan schedule:run >> /dev/null 2>&1
```

## Rotating the deploy key

Generate a new keypair (`ssh-keygen -t ed25519`), replace the key in hPanel → SSH Access, update the `SSH_PRIVATE_KEY` secret, delete the old key.
