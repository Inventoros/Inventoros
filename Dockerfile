# syntax=docker/dockerfile:1.7

# Inventoros production image.
#
#   assets  -> Node builds the Vite bundle
#   vendor  -> Composer installs production dependencies (no dev packages)
#   runtime -> FrankenPHP (Caddy + PHP in one binary) serving /app/public
#
# Runs as a non-root user on port 8080, APP_DEBUG=false, caches config,
# routes, views and events at start, and keeps /app/storage on a volume.
# Migrations only run when RUN_MIGRATIONS=true (see docker/entrypoint.prod.sh).
#
# Build:  docker build -t inventoros .
# Run:    docker compose -f docker-compose.prod.yml up -d
# Docs:   docs/site/sections/installation-docker.md

ARG PHP_VERSION=8.4
ARG NODE_VERSION=22

############################################
# PHP base: runtime extensions + DB clients
############################################
FROM dunglas/frankenphp:1-php${PHP_VERSION} AS base

# pdo_* for MySQL/PostgreSQL/SQLite, plus what the app and its packages use.
# The mysql/postgresql clients let the in-app backup use mysqldump/pg_dump;
# the PostgreSQL client comes from the PGDG repo so it can dump any current
# server version (Debian's own client refuses to dump newer servers).
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates curl gnupg unzip; \
    install -d /usr/share/postgresql-common/pgdg; \
    curl -fsSL -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc https://www.postgresql.org/media/keys/ACCC4CF8.asc; \
    . /etc/os-release; \
    echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt ${VERSION_CODENAME}-pgdg main" > /etc/apt/sources.list.d/pgdg.list; \
    apt-get update; \
    apt-get install -y --no-install-recommends postgresql-client-17 mariadb-client sqlite3; \
    install-php-extensions \
        pdo_mysql pdo_pgsql pdo_sqlite \
        bcmath exif gd intl pcntl zip opcache redis; \
    apt-get purge -y --auto-remove gnupg; \
    rm -rf /var/lib/apt/lists/*; \
    command -v mysqldump; command -v pg_dump

WORKDIR /app

############################################
# Frontend assets
############################################
FROM node:${NODE_VERSION}-bookworm-slim AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY vite.config.js postcss.config.js tailwind.config.js jsconfig.json ./
COPY resources ./resources
COPY plugins ./plugins
COPY public ./public
RUN npm run build

############################################
# Composer dependencies (production only)
############################################
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./
RUN composer install \
        --no-dev --no-interaction --no-progress --prefer-dist \
        --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts

############################################
# Runtime image
############################################
FROM base AS runtime

ARG UID=1000
ARG GID=1000

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=warning \
    SERVER_NAME=:8080 \
    XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data \
    RUN_MIGRATIONS=false

RUN set -eux; \
    cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    { \
        echo 'memory_limit=512M'; \
        echo 'upload_max_filesize=32M'; \
        echo 'post_max_size=32M'; \
        echo 'expose_php=Off'; \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=0'; \
        echo 'opcache.memory_consumption=192'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
    } > "$PHP_INI_DIR/conf.d/zz-inventoros.ini"; \
    groupadd --gid "${GID}" app; \
    useradd --uid "${UID}" --gid app --create-home --shell /usr/sbin/nologin app; \
    setcap cap_net_bind_service=+ep /usr/local/bin/frankenphp; \
    mkdir -p /data/caddy /config/caddy; \
    chown -R app:app /data /config

COPY docker/Caddyfile.prod /etc/caddy/Caddyfile
COPY --chmod=755 docker/entrypoint.prod.sh /usr/local/bin/inventoros-entrypoint

COPY --chown=app:app . /app
COPY --from=vendor --chown=app:app /app/vendor /app/vendor
COPY --from=assets --chown=app:app /app/public/build /app/public/build

RUN set -eux; \
    rm -rf tests e2e screenshots docs node_modules docker-compose*.yml playwright.config.ts; \
    mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
             storage/framework/views storage/logs bootstrap/cache; \
    # The web installer writes DB settings to .env; it must exist and be writable.
    touch .env; \
    mkdir -p plugins public/plugin-assets; \
    chown -R app:app storage bootstrap/cache .env public plugins; \
    su app -s /bin/sh -c "php artisan package:discover --ansi"

USER app

VOLUME ["/app/storage"]

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=5 \
    CMD curl -fsS http://127.0.0.1:8080/up || exit 1

ENTRYPOINT ["inventoros-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
