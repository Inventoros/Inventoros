#!/usr/bin/env bash
# Inventoros production entrypoint.
#
# Prepares the container, then execs the command (the web server by default,
# or a queue worker / scheduler when the compose file overrides it).
#
#   APP_KEY          required; generate once with: echo "base64:$(openssl rand -base64 32)"
#   RUN_MIGRATIONS   "true" to run `php artisan migrate --force` before start.
#                    Set it on ONE service only (the web app), never on the
#                    worker/scheduler, so containers never race on schema changes.
#   CACHE_ON_START   "false" to skip config/route/view/event caching.
#   DB_WAIT_SECONDS  how long to wait for the database port (default 60).
set -euo pipefail

cd /app

log() { echo "[entrypoint] $*"; }

if [ -z "${APP_KEY:-}" ]; then
    echo "[entrypoint] APP_KEY is not set. Generate one with:" >&2
    echo '  echo "base64:$(openssl rand -base64 32)"' >&2
    echo "and pass it to every Inventoros container as APP_KEY." >&2
    exit 1
fi

# A fresh bind-mounted storage directory starts empty; recreate the layout.
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
         storage/framework/views storage/logs bootstrap/cache

# Wait for a networked database so migrations and the first request don't fail.
case "${DB_CONNECTION:-}" in
    mysql|mariadb|pgsql)
        host="${DB_HOST:-127.0.0.1}"
        if [ "${DB_CONNECTION}" = "pgsql" ]; then port="${DB_PORT:-5432}"; else port="${DB_PORT:-3306}"; fi
        wait="${DB_WAIT_SECONDS:-60}"
        log "waiting up to ${wait}s for ${DB_CONNECTION} at ${host}:${port}"
        for _ in $(seq 1 "${wait}"); do
            if (echo > "/dev/tcp/${host}/${port}") >/dev/null 2>&1; then
                log "database is reachable"
                break
            fi
            sleep 1
        done
        ;;
esac

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    log "running migrations (RUN_MIGRATIONS=true)"
    php artisan migrate --force --no-interaction
else
    log "skipping migrations (set RUN_MIGRATIONS=true on one service to enable)"
fi

php artisan storage:link --no-interaction >/dev/null 2>&1 || true

if [ "${CACHE_ON_START:-true}" = "true" ]; then
    php artisan config:cache --no-interaction
    php artisan route:cache --no-interaction
    php artisan view:cache --no-interaction
    php artisan event:cache --no-interaction
fi

log "starting: $*"
exec "$@"
