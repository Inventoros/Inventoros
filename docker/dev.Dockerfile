# syntax=docker/dockerfile:1.7

# Single-image dev runtime for Inventoros: FrankenPHP (Caddy + PHP-FPM in one binary)
# + Node for asset builds. SQLite works out of the box; Postgres works via the
# postgres profile in docker-compose.yml.
#
# Build:   docker build -t inventoros .
# Run:     docker compose up
# See:     docker/README.md

ARG PHP_VERSION=8.4

FROM dunglas/frankenphp:1-php${PHP_VERSION} AS base

ARG USER=app
ARG UID=1000
ARG GID=1000

ENV SERVER_NAME=:80 \
    APP_ENV=local \
    APP_DEBUG=true \
    PHP_INI_DIR=/usr/local/etc/php

# System deps + PHP extensions Inventoros needs
RUN apt-get update && apt-get install -y --no-install-recommends \
        git curl unzip ca-certificates \
        libsqlite3-dev libpq-dev libzip-dev libpng-dev libjpeg-dev libfreetype6-dev \
        libicu-dev libxml2-dev libonig-dev \
        nodejs npm \
    && install-php-extensions \
        pdo_sqlite pdo_pgsql pdo_mysql \
        gd intl zip bcmath exif pcntl \
        opcache redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Non-root user (matches typical Linux UID; FrankenPHP/Caddy still binds :80 via cap)
RUN groupadd -g ${GID} ${USER} && useradd -m -u ${UID} -g ${GID} -s /bin/bash ${USER} \
    && setcap cap_net_bind_service=+ep /usr/local/bin/frankenphp \
    && mkdir -p /data/caddy /config/caddy \
    && chown -R ${USER}:${USER} /data/caddy /config/caddy

WORKDIR /app

# PHP recommended dev settings
RUN cp ${PHP_INI_DIR}/php.ini-development ${PHP_INI_DIR}/php.ini \
    && sed -ri 's/^memory_limit\s*=.*/memory_limit = 512M/' ${PHP_INI_DIR}/php.ini \
    && sed -ri 's/^upload_max_filesize\s*=.*/upload_max_filesize = 32M/' ${PHP_INI_DIR}/php.ini \
    && sed -ri 's/^post_max_size\s*=.*/post_max_size = 32M/' ${PHP_INI_DIR}/php.ini

# Caddyfile (FrankenPHP serves PHP and static files in one binary)
COPY docker/Caddyfile /etc/caddy/Caddyfile

# Composer + node deps (cached)
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

# App source
COPY --chown=${USER}:${USER} . /app

# Build assets, autoloader, optimise
RUN composer dump-autoload --optimize \
    && npm run build \
    && mkdir -p storage/framework/{sessions,cache,views,testing} \
                storage/app/public storage/logs bootstrap/cache database \
    && chown -R ${USER}:${USER} /app

# Entrypoint runs migrations, key:generate, storage:link before serving
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

USER ${USER}

EXPOSE 80

ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
