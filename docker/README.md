# Running Inventoros with Docker

Single-image dev stack (built from `docker/dev.Dockerfile`): FrankenPHP (Caddy + PHP-FPM in one binary) serves the Laravel app, with Mailpit for catching outgoing email. Postgres and Redis are opt-in via Compose profiles so the default boot is zero-config.

## Quick start

```bash
# From the repo root
docker compose up --build
```

When the container is ready (~60s on first build):

| URL | What |
|---|---|
| http://localhost:8080 | Inventoros app |
| http://localhost:8080/api/v1 | REST API |
| http://localhost:8080/mcp | MCP server (POST + Bearer token) |
| http://localhost:8080/docs/api | Stoplight Elements API browser |
| http://localhost:8025 | Mailpit web UI |

Default install uses SQLite — no external DB needed. The DB file persists across restarts in the `app-data` named volume.

## With Postgres

```bash
docker compose --profile postgres up --build
```

Then point the app at it by editing `.env` inside the container, or set these in `docker-compose.override.yml`:

```yaml
services:
  app:
    environment:
      DB_CONNECTION: pgsql
      DB_HOST: postgres
      DB_PORT: 5432
      DB_DATABASE: inventoros
      DB_USERNAME: inventoros
      DB_PASSWORD: inventoros
```

The entrypoint will wait up to 30 s for postgres before running migrations.

## With Redis

```bash
docker compose --profile redis up --build
```

Set `CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `QUEUE_CONNECTION=redis`, `REDIS_HOST=redis` in `.env`.

## Common tasks

```bash
# Open a shell inside the running app container
docker compose exec app bash

# Run artisan commands
docker compose exec app php artisan migrate:fresh --seed
docker compose exec app php artisan tinker

# Run the test suite
docker compose exec app php artisan test

# Build / re-build assets
docker compose exec app npm run build
```

## Customising

- **Port** — set `APP_PORT=8088` in a `.env` next to `docker-compose.yml` (or in your shell) before `docker compose up` to expose the app on a different host port. `APP_URL` follows it (`http://localhost:8088`); set `APP_URL` yourself if you open the app by another name or IP. Only change `APP_PORT`, not the `ports:` mapping: the app listens on port 80 inside the container.
- **Behind a reverse proxy** — proxies on private networks (the Docker host, another container) are trusted by default through `TRUSTED_PROXIES=private_ranges`, so `X-Forwarded-Proto: https` produces https URLs. See "Behind a reverse proxy" in `docs/site/sections/installation-docker.md`.
- **Mailpit ports** — `MAILPIT_PORT=8025`, `MAILPIT_SMTP_PORT=1025` (defaults).
- **PHP version** — change `PHP_VERSION` build arg in `docker-compose.yml`. Tested on 8.4 (matches CI). Inventoros requires PHP 8.4.1 or newer.

## Production

This stack is for development only. For production use the multi-stage `Dockerfile` at the repo root (published as `ghcr.io/inventoros/inventoros` for every release from v2.0.0; `docker compose -f docker-compose.prod.yml up -d --build` builds it from the checkout instead) with `docker-compose.prod.yml`: non-root, `APP_DEBUG=false`, caches built at start, migrations only when `RUN_MIGRATIONS=true`, and dedicated worker/scheduler containers on PostgreSQL. See `docs/site/sections/installation-docker.md`.

## Troubleshooting

- **`docker compose ps` shows a container as unhealthy** — only `app` and `mailpit` have health checks; `worker` and `scheduler` run no web server and show as plain `Up`. If an older checkout shows them `unhealthy`, they inherited the FrankenPHP base image's health check: update your checkout. For `app`, `docker inspect --format '{{json .State.Health}}' <container>` shows the failing probe.
- **`storage/` writes fail** — make sure the `app-storage` volume is healthy: `docker volume inspect inventoros_app-storage`. Recreate with `docker compose down -v` if needed.
- **`docker compose up` never finishes the build** — first build downloads ~500 MB (PHP + Node + Composer + npm deps). Use `docker compose build --progress=plain` to watch what's happening.
- **`pcntl` errors at boot** — the image installs `pcntl`, but if you customised `php.ini` you may have disabled it. Check `docker compose exec app php -m | grep pcntl`.
