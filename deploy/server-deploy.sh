#!/usr/bin/env bash
#
# Server-side deploy steps for Hostinger shared hosting.
# Executed over SSH by .github/workflows/ci-cd.yml after rsync upload.
#
set -euo pipefail

DOMAIN="${DOMAIN:?DOMAIN env var is required}"
APP_DIR="$HOME/domains/$DOMAIN/finance-manager"
WEB_ROOT="$HOME/domains/$DOMAIN/public_html"

# --- Pick a PHP >= 8.4 binary -------------------------------------------------
PHP_BIN=""
for candidate in php php8.4 php84 /opt/alt/php84/usr/bin/php /opt/alt/php85/usr/bin/php; do
  if command -v "$candidate" >/dev/null 2>&1 \
     && "$candidate" -r 'exit(version_compare(PHP_VERSION, "8.4.1", ">=") ? 0 : 1);' 2>/dev/null; then
    PHP_BIN="$candidate"
    break
  fi
done
if [ -z "$PHP_BIN" ]; then
  echo "ERROR: no PHP >= 8.4 binary found on server" >&2
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

# --- Database credentials -----------------------------------------------------
#
# Written on every deploy, not just the first, so a change of database actually
# reaches a server that already has a .env. Credentials come from GitHub Actions
# secrets and are never committed to the repository.
#
# Rewriting a key rather than editing in place keeps values with commas, quotes
# or slashes safe, which sed substitution would not.
set_env() {
  local key="$1" value="$2"

  grep -v -E "^${key}=" .env > .env.deploy-tmp || true
  printf '%s="%s"\n' "$key" "$value" >> .env.deploy-tmp
  mv .env.deploy-tmp .env
}

# Credentials arrive base64-encoded so they survive the SSH command line
# intact, whatever characters they contain.
decode() { [ -n "${1:-}" ] && printf '%s' "$1" | base64 -d || printf ''; }

DB_HOST="$(decode "${DB_HOST_B64:-}")"
DB_DATABASE="$(decode "${DB_DATABASE_B64:-}")"
DB_USERNAME="$(decode "${DB_USERNAME_B64:-}")"
DB_PASSWORD="$(decode "${DB_PASSWORD_B64:-}")"

if [ -n "${DB_DATABASE:-}" ]; then
  set_env DB_CONNECTION "${DB_CONNECTION:-mysql}"
  set_env DB_HOST "${DB_HOST:-localhost}"
  set_env DB_PORT "${DB_PORT:-3306}"
  set_env DB_DATABASE "$DB_DATABASE"
  set_env DB_USERNAME "${DB_USERNAME:-}"
  set_env DB_PASSWORD "${DB_PASSWORD:-}"

  # A SQLite deployment used to set this; it is meaningless for MySQL and
  # confusing to leave behind.
  grep -v -E '^DB_FOREIGN_KEYS=' .env > .env.deploy-tmp || true
  mv .env.deploy-tmp .env

  echo "Database configured: ${DB_CONNECTION:-mysql} -> $DB_DATABASE"
else
  echo "No DB_DATABASE provided; leaving the existing .env database settings alone."
fi

# --- Generate APP_KEY on the server, only once --------------------------------
if grep -qE '^APP_KEY=[[:space:]]*"?[[:space:]]*"?$' .env; then
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
[ -e public/storage ] || ln -s "$APP_DIR/storage/app/public" "$APP_DIR/public/storage"

# Clear first: a cached config from the previous deploy would otherwise still
# point at the old database and every command below would use it.
"$PHP_BIN" artisan config:clear
"$PHP_BIN" artisan cache:clear || true

# Fail loudly here rather than serving 500s to users.
if ! "$PHP_BIN" artisan db:show --json >/dev/null 2>&1; then
  echo "ERROR: cannot connect to the database with the configured credentials" >&2
  "$PHP_BIN" artisan db:show || true
  exit 1
fi

"$PHP_BIN" artisan migrate --force

"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan route:cache || echo "route:cache skipped (uncacheable routes)"

echo "Deploy complete: https://$DOMAIN"
