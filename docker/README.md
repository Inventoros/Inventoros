# Running Inventoros with Docker

Single-image dev stack: FrankenPHP (Caddy + PHP-FPM in one binary) serves the Laravel app, with Mailpit for catching outgoing email. Postgres and Redis are opt-in via Compose profiles so the default boot is zero-config.

## Quick start

```bash
# From the repo root
APP_PORT=8484 docker compose up --build
```

When the container is ready (~60s on first build), watch the logs for the ready banner. You'll see:

```
================================================================
  Inventoros is ready.

  App         http://localhost:8484
  REST API    http://localhost:8484/api/v1
  MCP server  http://localhost:8484/mcp
  Mailpit     http://localhost:8025

  Demo login  e2e-test@inventoros.test / E2ETestPassword123!

  UI redesign preview:
    http://localhost:8484/preview/dashboard
    http://localhost:8484/preview/products
================================================================
```

| URL | What |
|---|---|
| http://localhost:8484 | Inventoros app |
| http://localhost:8484/api/v1 | REST API |
| http://localhost:8484/mcp | MCP server (POST + Bearer token) |
| http://localhost:8484/docs/api | Stoplight Elements API browser |
| http://localhost:8484/preview/dashboard | UI redesign — dashboard |
| http://localhost:8484/preview/products | UI redesign — products list |
| http://localhost:8025 | Mailpit web UI |

The first boot:

1. Creates `.env` from `.env.example` and generates `APP_KEY`.
2. Sets `SESSION_SECURE_COOKIE=false` because `APP_URL` is plain HTTP localhost (so login actually works).
3. Sets `APP_URL` to match the host port you used.
4. Runs migrations.
5. Seeds `E2ETestSeeder` (creates the demo login above) and `DemoDataSeeder` (gives you visible products, orders, suppliers, etc.).
6. Drops a marker at `storage/.seeded` so subsequent restarts don't re-seed.

Default install uses SQLite — no external DB needed. The DB file persists across restarts in the `app-data` named volume.

To start fresh (wipe DB + reseed): `docker compose down -v && APP_PORT=8484 docker compose up`.

To boot without auto-seeding (e.g. you want to start from an empty DB): `DEV_AUTOSEED=false APP_PORT=8484 docker compose up`.

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

- **Port** — set `APP_PORT=80` in `.env` (or your shell) before `docker compose up` to expose on a different host port.
- **Mailpit ports** — `MAILPIT_PORT=8025`, `MAILPIT_SMTP_PORT=1025` (defaults).
- **PHP version** — change `PHP_VERSION` build arg in `docker-compose.yml`. Tested on 8.4 (matches CI). Inventoros requires PHP ≥ 8.2.

## Production note

This image targets local dev. Bind-mounts of `app/`, `routes/`, etc. let you edit code without rebuilding. For a production build:

1. Drop those bind-mounts from your production compose file.
2. Set `APP_ENV=production`, `APP_DEBUG=false`.
3. Run `php artisan config:cache route:cache view:cache` in the entrypoint.
4. Replace `:80` in the Caddyfile with your domain — FrankenPHP will provision Let's Encrypt certs automatically (`auto_https on` is the default once a hostname is present).

## Troubleshooting

- **`storage/` writes fail** — make sure the `app-storage` volume is healthy: `docker volume inspect inventoros_app-storage`. Recreate with `docker compose down -v` if needed.
- **`docker compose up` never finishes the build** — first build downloads ~500 MB (PHP + Node + Composer + npm deps). Use `docker compose build --progress=plain` to watch what's happening.
- **`pcntl` errors at boot** — the image installs `pcntl`, but if you customised `php.ini` you may have disabled it. Check `docker compose exec app php -m | grep pcntl`.
