#!/usr/bin/env bash
# Inventoros container entrypoint: prepare the app on first boot, then exec the server.
#
# Behavior knobs (set via docker-compose `environment:`):
#   DEV_AUTOSEED=true|false   — seed E2ETestSeeder + DemoDataSeeder on first
#                                boot so the app has a login + visible data.
#                                Defaults to true. The marker file
#                                storage/.seeded prevents reseeding on every
#                                container restart; `docker compose down -v`
#                                wipes it along with the DB.
#   APP_URL                   — usually injected by compose so it matches the
#                                host port (e.g. http://localhost:8484).
set -euo pipefail

cd /app

# ---------------------------------------------------------------------------
# .env bootstrap
# ---------------------------------------------------------------------------

if [ ! -f .env ]; then
    cp .env.example .env
    echo "[entrypoint] copied .env.example -> .env"
fi

if ! grep -qE '^APP_KEY=base64:' .env; then
    php artisan key:generate --force --no-interaction
    echo "[entrypoint] generated APP_KEY"
fi

# Local-dev safety: if APP_URL is plain HTTP localhost, the secure-cookie
# default in .env.example will silently break login (the cookie won't be set
# without TLS). Flip it off so devs don't have to debug that.
if grep -qE '^APP_URL=http://localhost' .env; then
    if grep -qE '^SESSION_SECURE_COOKIE=true' .env; then
        sed -i 's/^SESSION_SECURE_COOKIE=true/SESSION_SECURE_COOKIE=false/' .env
        echo "[entrypoint] APP_URL is plain HTTP — set SESSION_SECURE_COOKIE=false"
    fi
fi

# Reflect the runtime APP_URL into .env so anything that reads .env directly
# (artisan tinker, queue workers) sees the same host the user is hitting.
if [ -n "${APP_URL:-}" ]; then
    if grep -qE '^APP_URL=' .env; then
        sed -i "s|^APP_URL=.*|APP_URL=${APP_URL}|" .env
    else
        echo "APP_URL=${APP_URL}" >> .env
    fi
fi

# ---------------------------------------------------------------------------
# DB bootstrap
# ---------------------------------------------------------------------------

if grep -qE '^DB_CONNECTION=sqlite' .env; then
    mkdir -p database
    touch database/database.sqlite
fi

# Wait for Postgres if configured (max 30 s)
if grep -qE '^DB_CONNECTION=pgsql' .env; then
    db_host=$(grep -E '^DB_HOST=' .env | cut -d= -f2- | tr -d '"')
    db_port=$(grep -E '^DB_PORT=' .env | cut -d= -f2- | tr -d '"')
    db_host=${db_host:-postgres}
    db_port=${db_port:-5432}
    echo "[entrypoint] waiting for postgres at ${db_host}:${db_port}…"
    for _ in $(seq 1 30); do
        if (echo > "/dev/tcp/${db_host}/${db_port}") >/dev/null 2>&1; then
            echo "[entrypoint] postgres ready"
            break
        fi
        sleep 1
    done
fi

php artisan migrate --force --no-interaction || true
php artisan storage:link --force --no-interaction || true

# ---------------------------------------------------------------------------
# Auto-seed for local preview (idempotent via marker file)
# ---------------------------------------------------------------------------

SEEDED_MARKER=storage/.seeded
DEV_AUTOSEED=${DEV_AUTOSEED:-true}

if [ "${DEV_AUTOSEED}" = "true" ] && [ ! -f "${SEEDED_MARKER}" ]; then
    echo "[entrypoint] first boot — seeding test user + demo data"
    php artisan db:seed --class=E2ETestSeeder --force --no-interaction || true
    php artisan db:seed --class=DemoDataSeeder --force --no-interaction || true
    touch "${SEEDED_MARKER}"
    echo "[entrypoint] seeded; marker at ${SEEDED_MARKER}"
fi

# Always clear caches in dev so .env edits take effect on container restart
php artisan config:clear --no-interaction || true
php artisan view:clear --no-interaction || true

# ---------------------------------------------------------------------------
# Friendly banner (visible in `docker compose logs app`)
# ---------------------------------------------------------------------------

URL=${APP_URL:-http://localhost}
cat <<BANNER

================================================================
  Inventoros is ready.

  App         ${URL}
  REST API    ${URL}/api/v1
  MCP server  ${URL}/mcp        (POST + Bearer token)
  API docs    ${URL}/docs/api
  Mailpit     http://localhost:\${MAILPIT_PORT:-8025}

  Demo login  e2e-test@inventoros.test / E2ETestPassword123!

  UI redesign preview:
    ${URL}/preview/dashboard
    ${URL}/preview/products

================================================================

[entrypoint] handing off to: $*
BANNER

exec "$@"
