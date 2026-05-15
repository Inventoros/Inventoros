#!/usr/bin/env bash
# Inventoros container entrypoint: prepare the app on first boot, then exec the server.
set -euo pipefail

cd /app

# Generate .env from the example on first boot
if [ ! -f .env ]; then
    cp .env.example .env
    echo "[entrypoint] copied .env.example -> .env"
fi

# Generate APP_KEY if missing (idempotent)
if ! grep -qE '^APP_KEY=base64:' .env; then
    php artisan key:generate --force --no-interaction
    echo "[entrypoint] generated APP_KEY"
fi

# SQLite default — make sure the DB file exists so migrations don't fail
if grep -qE '^DB_CONNECTION=sqlite' .env; then
    mkdir -p database
    touch database/database.sqlite
fi

# Wait for Postgres if configured (max 30s)
if grep -qE '^DB_CONNECTION=pgsql' .env; then
    db_host=$(grep -E '^DB_HOST=' .env | cut -d= -f2- | tr -d '"')
    db_port=$(grep -E '^DB_PORT=' .env | cut -d= -f2- | tr -d '"')
    db_host=${db_host:-postgres}
    db_port=${db_port:-5432}
    echo "[entrypoint] waiting for postgres at ${db_host}:${db_port}…"
    for i in $(seq 1 30); do
        if (echo > "/dev/tcp/${db_host}/${db_port}") >/dev/null 2>&1; then
            echo "[entrypoint] postgres ready"
            break
        fi
        sleep 1
    done
fi

# Run migrations (idempotent) and create the storage symlink
php artisan migrate --force --no-interaction || true
php artisan storage:link --force --no-interaction || true

# Recompile config in dev (cleared so .env edits take effect on container restart)
php artisan config:clear --no-interaction || true
php artisan view:clear --no-interaction || true

echo "[entrypoint] ready — handing off to: $*"
exec "$@"
