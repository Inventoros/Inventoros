Inventoros publishes a production Docker image to the GitHub Container Registry for every release, starting with v2.0.0:

```
ghcr.io/inventoros/inventoros:latest   # newest release
ghcr.io/inventoros/inventoros:2.0.0    # an exact release
ghcr.io/inventoros/inventoros:2.0      # newest patch of a minor line
ghcr.io/inventoros/inventoros:2        # newest release of a major line
```

There is no image for releases before v2.0.0. If `docker compose pull` cannot fetch the image (for example `unauthorized` or `denied`), build it from a checkout instead: see "Building the image yourself".

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
| `worker` | `php artisan queue:work` | Sends queued mail, webhooks, imports and exports. No health check |
| `scheduler` | `php artisan schedule:work` | Reorder-point checks, retention pruning and other scheduled jobs. No health check |
| `db` | PostgreSQL 17 | Data in the `db-data` volume, health check with `pg_isready` |

`docker compose ps` shows `app` and `db` as `healthy` once they are ready. The worker and scheduler start only after `app` is healthy and have no health check of their own, so they show as plain `Up`.

Uploads, logs and backups live in `/app/storage`, which is the `storage` volume shared by the three Inventoros containers. Installed plugin code lives in the shared `plugins` volume (`/app/plugins`) and published plugin UI assets in `plugin-assets` (`/app/public/plugin-assets`). Back up these three volumes and `db-data`. Keeping plugin code shared lets queued jobs and the scheduler use the same plugins as the web app, and preserves installations when containers are recreated.

After installing, updating, activating or deactivating a plugin, restart the worker and scheduler so their long-running processes load the current plugin code:

```bash
docker compose -f docker-compose.prod.yml restart worker scheduler
```

If you previously ran the compose file without plugin volumes, copy `/app/plugins` and `/app/public/plugin-assets` out of the existing app container before recreating it, then restore them into the new volumes. Existing container files are not automatically migrated into a new named volume.

### Configuration

Every Laravel setting can be passed as an environment variable. The ones specific to the image:

| Variable | Default | Purpose |
|---|---|---|
| `APP_KEY` | required | Encryption key. The container refuses to start without it |
| `RUN_MIGRATIONS` | `false` | Run `php artisan migrate --force` before starting. Enable it on one service only |
| `CACHE_ON_START` | `true` | Build config, route, view and event caches at start |
| `APP_URL` | `http://localhost:${APP_PORT}` | The address people type to reach Inventoros, including `https://` and any port (see below) |
| `APP_PORT` | `8080` | Host port the `app` service is published on (compose file only; see "Changing the port") |
| `SERVER_NAME` | `:8080` | Caddy site address inside the container. Leave it alone unless the container should obtain HTTPS certificates itself (see "HTTPS without a proxy") |
| `TRUSTED_PROXIES` | `private_ranges` | Reverse proxies whose `X-Forwarded-*` headers are believed (see "Behind a reverse proxy") |
| `DB_WAIT_SECONDS` | `60` | How long to wait for the database port before starting |
| `SESSION_SECURE_COOKIE` | follows the request | Unset, the session cookie is marked Secure exactly when the request arrived over HTTPS (directly or through a trusted proxy). Set `true` to force it once the site is HTTPS-only |

To use MySQL instead of PostgreSQL, replace the `db` service with a `mysql:8.0` container and set `DB_CONNECTION=mysql`, `DB_PORT=3306` in the shared environment block.

### Changing the port

The app listens on port 8080 inside the container. To reach it on another port of the host, set `APP_PORT` in `.env` and update `APP_URL` to match:

```bash
APP_PORT=8088
APP_URL=http://192.0.2.10:8088
```

Then run `docker compose -f docker-compose.prod.yml up -d` again. Change only `APP_PORT`: do not edit the `ports:` mapping to `8088:8088` (nothing listens on 8088 inside the container) and do not set `SERVER_NAME` to change the port.

### APP_URL

`APP_URL` must be the exact address people use: scheme, host name and port, for example `https://inventory.example.com` or `http://192.0.2.10:8088`. Pages work from whatever address the browser used, but anything built outside a web request uses `APP_URL`: links in emails and notifications sent by the queue worker, uploaded product images (`/storage/...`), and scheduled reports. With `APP_URL` left at `http://localhost:8080` those links point at `localhost` and images do not load for anyone else.

After changing `APP_URL`, recreate the containers (`docker compose -f docker-compose.prod.yml up -d`); the configuration is cached when a container starts.

### Behind a reverse proxy

The usual setup runs nginx, Traefik, Caddy or a cloud load balancer in front of Inventoros: the proxy holds the domain and the TLS certificate and forwards plain HTTP to the container. Three things must line up:

1. `APP_URL` is the public `https://` address.
2. The proxy sends the standard forwarding headers: `Host`, `X-Forwarded-For`, `X-Forwarded-Proto`, `X-Forwarded-Host` and `X-Forwarded-Port`.
3. Inventoros trusts the proxy, so it believes those headers. `TRUSTED_PROXIES` defaults to `private_ranges`, which covers a proxy running on the Docker host or in another container. For a proxy elsewhere, set its address: `TRUSTED_PROXIES=203.0.113.20`, or a comma-separated list of addresses and CIDR ranges.

If the proxy is not trusted, the app sees plain HTTP: the page's scripts and styles are requested over `http://`, the browser blocks them as mixed content, and you see an empty page (or only the browser tab icon) with "Mixed Content" errors in the browser console.

An nginx site for a proxy on the Docker host, with the stack's `APP_PORT` at 8080:

```nginx
server {
    listen 443 ssl;
    http2 on;
    server_name inventory.example.com;

    ssl_certificate     /etc/letsencrypt/live/inventory.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/inventory.example.com/privkey.pem;

    # Allow imports and image uploads.
    client_max_body_size 32m;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host  $host;
        proxy_set_header X-Forwarded-Port  $server_port;
        proxy_read_timeout 300s;
    }
}

server {
    listen 80;
    server_name inventory.example.com;
    return 301 https://$host$request_uri;
}
```

With nginx in a container on the same Compose network, use `proxy_pass http://app:8080;` instead. When the proxy is the only way in, stop publishing the port on every interface by binding it to the host's loopback in `docker-compose.prod.yml`: `"127.0.0.1:${APP_PORT:-8080}:8080"`.

Caddy (`reverse_proxy 127.0.0.1:8080`) and Traefik send these headers by default; only `APP_URL` and, if the proxy is not on a private network, `TRUSTED_PROXIES` need setting.

`TRUSTED_PROXIES` decides whose forwarding headers are believed, including the client IP used for login rate limiting and the activity log. With `private_ranges`, a machine on your private network that can reach the container directly could claim another client IP. Where that matters, set the proxy's exact address. `*` trusts every caller and is only safe when nothing but the proxy can reach the container.

### HTTPS without a proxy

By default the container speaks plain HTTP on port 8080, which suits the reverse proxy setup above. To let the container handle TLS on its own, set `SERVER_NAME=inventory.example.com` and `APP_URL=https://inventory.example.com`, and publish ports `80:80` and `443:443` on the `app` service. Caddy then obtains and renews a Let's Encrypt certificate, which needs the domain's DNS pointing at the server and ports 80 and 443 reachable from the internet. The container health check follows `SERVER_NAME`, and reports the app unhealthy until the certificate has been issued.

### Updating

Pull the new image and recreate the containers. The `app` container applies migrations on start:

```bash
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml up -d
```

Pin a version with `INVENTOROS_IMAGE=ghcr.io/inventoros/inventoros:2.0.0` in `.env` if you prefer to upgrade deliberately. Do not use the in-app updater inside a container: it replaces files in the running container, and those changes are lost when the container is recreated.

### Building the image yourself

```bash
git clone https://github.com/Inventoros/Inventoros.git
cd Inventoros
docker build -t inventoros .
```

`docker compose -f docker-compose.prod.yml up -d --build` builds from the checkout instead of pulling from GHCR. Run it from the repository root, with your `.env` there.

### Troubleshooting

- **Only a blank page (or the tab icon) behind a proxy, with "Mixed Content" in the browser console.** The app does not trust the proxy, or `APP_URL` is not the `https://` address. See "Behind a reverse proxy".
- **Every form answers "419 Page Expired".** The session cookie is not coming back. This happens when `SESSION_SECURE_COOKIE=true` while the app is opened over plain HTTP; leave it unset.
- **A container shows `unhealthy`.** `docker inspect --format '{{json .State.Health}}' <container>` shows the failing probe. The `app` health check requests `/up` at the address `SERVER_NAME` makes Caddy listen on; with `SERVER_NAME` set to a domain it fails until Caddy has a certificate. Images up to v2.0.0 always probed port 8080, so changing `SERVER_NAME` marked them unhealthy. In the development stack (`docker-compose.yml`), older checkouts showed `worker` and `scheduler` as unhealthy because they inherited the web server's health check; update your checkout.
- **`docker compose up` reports `dependency failed to start: container ... is unhealthy`.** The worker and scheduler wait for `app` to be healthy. `docker compose -f docker-compose.prod.yml logs app` shows why it is not (usually the database password or `APP_KEY`).

### Development stack

`docker-compose.yml` in the repository is a development environment, not a deployment: it bind-mounts the source, enables `APP_DEBUG`, uses SQLite and includes Mailpit. Start it with `docker compose up --build` and see `docker/README.md` for details.

Report an issue: https://github.com/Inventoros/Inventoros/issues
