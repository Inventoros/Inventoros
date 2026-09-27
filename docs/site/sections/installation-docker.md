Inventoros publishes a production Docker image to the GitHub Container Registry for every release:

```
ghcr.io/inventoros/inventoros:latest   # newest release
ghcr.io/inventoros/inventoros:1.2.3    # an exact release
ghcr.io/inventoros/inventoros:1.2      # newest patch of a minor line
```

The image runs PHP 8.4 under FrankenPHP (Caddy and PHP in one process), serves the app on port 8080 as a non-root user, and ships with production settings: `APP_ENV=production`, `APP_DEBUG=false`, OPcache on, and config, route, view and event caches built at start. The `mysqldump`, `pg_dump` and `sqlite3` clients are included so the built-in backups can capture the database.

### Prerequisites

- Docker 24.0 or higher
- Docker Compose v2
- At least 1GB RAM and 5GB disk space

### Quick start with Docker Compose

The repository contains a ready-to-use `docker-compose.prod.yml` that runs four containers: the web app, a queue worker, the scheduler, and PostgreSQL 17.

1. Download the compose file into an empty directory:

```bash
mkdir inventoros && cd inventoros
curl -fsSLO https://raw.githubusercontent.com/Inventoros/Inventoros/main/docker-compose.prod.yml
```

2. Create a `.env` file next to it:

```bash
cat > .env <<EOF
APP_KEY=base64:$(openssl rand -base64 32)
APP_URL=https://inventory.example.com
DB_PASSWORD=$(openssl rand -hex 24)
EOF
```

Keep this file safe. `APP_KEY` encrypts sessions and stored secrets, so changing it later logs everyone out and makes encrypted values unreadable.

3. Start the stack:

```bash
docker compose -f docker-compose.prod.yml up -d
```

4. Open `APP_URL/install` (or http://localhost:8080/install) and complete the web installer. On the database step enter host `db`, port `5432`, database `inventoros`, user `inventoros` and the `DB_PASSWORD` from your `.env`.

### What runs where

| Service | Command | Notes |
|---|---|---|
| `app` | FrankenPHP web server | Runs migrations on start (`RUN_MIGRATIONS=true`), exposes port 8080, health check on `/up` |
| `worker` | `php artisan queue:work` | Sends queued mail, webhooks, imports and exports |
| `scheduler` | `php artisan schedule:work` | Reorder-point checks, retention pruning and other scheduled jobs |
| `db` | PostgreSQL 17 | Data in the `db-data` volume |

Uploads, logs and backups live in `/app/storage`, which is the `storage` volume shared by the three Inventoros containers. Back up both the `storage` and `db-data` volumes.

### Configuration

Every Laravel setting can be passed as an environment variable. The ones specific to the image:

| Variable | Default | Purpose |
|---|---|---|
| `APP_KEY` | required | Encryption key. The container refuses to start without it |
| `RUN_MIGRATIONS` | `false` | Run `php artisan migrate --force` before starting. Enable it on one service only |
| `CACHE_ON_START` | `true` | Build config, route, view and event caches at start |
| `SERVER_NAME` | `:8080` | Caddy site address. Set to a domain to let Caddy obtain HTTPS certificates itself (then publish ports 80 and 443) |
| `DB_WAIT_SECONDS` | `60` | How long to wait for the database port before starting |
| `SESSION_SECURE_COOKIE` | `true` | Set to `false` only if you serve the app over plain HTTP |

To use MySQL instead of PostgreSQL, replace the `db` service with a `mysql:8.0` container and set `DB_CONNECTION=mysql`, `DB_PORT=3306` in the shared environment block.

### HTTPS

By default the container speaks plain HTTP on port 8080, which suits a reverse proxy or load balancer that terminates TLS (Traefik, nginx, Caddy, a cloud load balancer). To let the container handle TLS on its own, set `SERVER_NAME=inventory.example.com` and publish ports `80:80` and `443:443` on the `app` service.

### Updating

Pull the new image and recreate the containers. The `app` container applies migrations on start:

```bash
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml up -d
```

Pin a version with `INVENTOROS_IMAGE=ghcr.io/inventoros/inventoros:1.2.3` in `.env` if you prefer to upgrade deliberately. Do not use the in-app updater inside a container: it replaces files in the running container, and those changes are lost when the container is recreated.

### Building the image yourself

```bash
git clone https://github.com/Inventoros/Inventoros.git
cd Inventoros
docker build -t inventoros .
```

`docker compose -f docker-compose.prod.yml up -d --build` builds from the checkout instead of pulling from GHCR.

### Development stack

`docker-compose.yml` in the repository is a development environment, not a deployment: it bind-mounts the source, enables `APP_DEBUG`, uses SQLite and includes Mailpit. Start it with `docker compose up --build` and see `docker/README.md` for details.

Report an issue: https://github.com/Inventoros/Inventoros/issues
