#!/usr/bin/env bash
#
# Server-side deploy steps for Hostinger shared hosting.
# Executed over SSH by .github/workflows/ci-cd.yml after rsync upload.
#
set -euo pipefail

DOMAIN="${DOMAIN:?DOMAIN env var is required}"
APP_DIR="$HOME/domains/$DOMAIN/finance-manager"
WEB_ROOT="$HOME/domains/$DOMAIN/public_html"

# --- Pick a PHP >= 8.3 binary -------------------------------------------------
PHP_BIN=""
for candidate in php php8.3 php83 /opt/alt/php83/usr/bin/php /opt/alt/php84/usr/bin/php; do
  if command -v "$candidate" >/dev/null 2>&1 \
     && "$candidate" -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' 2>/dev/null; then
    PHP_BIN="$candidate"
    break
  fi
done
if [ -z "$PHP_BIN" ]; then
  echo "ERROR: no PHP >= 8.3 binary found on server" >&2
  exit 1
fi
echo "Using PHP: $($PHP_BIN -v | head -1)"

cd "$APP_DIR"

# --- Storage skeleton (storage/ is never uploaded or deleted by rsync) --------
mkdir -p storage/framework/cache/data \
         storage/framework/sessions \
         storage/framework/views \
         storage/logs \
         storage/app/public \
         bootstrap/cache

# --- First run: create .env from the committed template -----------------------
if [ ! -f .env ]; then
  sed "s#__APP_DIR__#$APP_DIR#g" deploy/env.production > .env
  echo "Created .env from deploy/env.production"
fi

# --- SQLite database file (lives in storage/, survives deploys) ---------------
DB_FILE="$APP_DIR/storage/database.sqlite"
if [ ! -f "$DB_FILE" ]; then
  touch "$DB_FILE"
  echo "Created SQLite database at $DB_FILE"
fi

# --- Generate APP_KEY on the server, only once --------------------------------
if grep -qE '^APP_KEY=[[:space:]]*$' .env; then
  "$PHP_BIN" artisan key:generate --force
fi

# --- Point public_html at the app's public dir (first run only) ---------------
if [ ! -L "$WEB_ROOT" ]; then
  if [ -e "$WEB_ROOT" ]; then
    mv "$WEB_ROOT" "$WEB_ROOT.backup.$(date +%s)"
    echo "Backed up existing public_html"
  fi
  ln -s "$APP_DIR/public" "$WEB_ROOT"
  echo "Linked public_html -> finance-manager/public"
fi

# --- Laravel housekeeping ------------------------------------------------------
[ -e public/storage ] || "$PHP_BIN" artisan storage:link

"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan config:clear
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan route:cache || echo "route:cache skipped (uncacheable routes)"

echo "Deploy complete: https://$DOMAIN"
