The Inventoros installer sets up a complete production install on a Linux server with one command. It installs Docker if needed, downloads the release you choose, generates the secrets, starts the app, queue worker, scheduler and PostgreSQL, and waits until everything is healthy. You finish in the same web installer as every other method.

## Requirements

- A Linux server: Ubuntu 22.04 or 24.04, Debian 12, or a RHEL-family system (Rocky, AlmaLinux, CentOS Stream, Fedora). Other distributions usually work but are not tested.
- x86_64 (amd64). The published image does not support ARM servers yet; on ARM, [build the image yourself](installation-docker#building-the-image-yourself).
- 1 GB of memory (2 GB recommended) and 5 GB of free disk.
- Root access (`sudo`) and outbound internet access to GitHub and the Docker registries.

## Install

Download the script, read it, then run it:

```bash
curl -fsSL https://inventoros.com/install.sh -o install.sh
less install.sh
sudo bash install.sh
```

When it finishes it prints the address to open, for example `http://203.0.113.10:8080/install`. On the database step of the web installer, the host, port, database name and user are already filled in. The password is `DB_PASSWORD` in `/opt/inventoros/.env`:

```bash
sudo grep DB_PASSWORD /opt/inventoros/.env
```

Then create your organization and the administrator account.

`https://inventoros.com/install.sh` always serves the installer from the latest release. A specific release's copy is attached to that release on GitHub, for example `https://github.com/Inventoros/Inventoros/releases/download/v2.4.0/install.sh`.

## HTTPS with your own domain

Point a DNS `A` record at the server, make sure ports 80 and 443 are open, then run:

```bash
sudo bash install.sh --domain inventory.example.com
```

Inventoros obtains and renews a Let's Encrypt certificate itself. The app reports healthy once the certificate is issued, which takes up to a minute after DNS resolves.

Already running nginx, Traefik, Caddy or a load balancer in front? Keep plain HTTP on the server and tell Inventoros its public address instead:

```bash
sudo bash install.sh --url https://inventory.example.com
```

See [Behind a reverse proxy](installation-docker#behind-a-reverse-proxy) for the proxy settings.

## Options

| Option | Default | Purpose |
|---|---|---|
| `--version X.Y.Z` | latest release | Release to install, or to upgrade to on a re-run |
| `--dir PATH` | `/opt/inventoros` | Where the Compose files, `.env` and `install.log` live |
| `--domain HOST` | none | Serve HTTPS on `HOST` with an automatic certificate |
| `--url URL` | `http://SERVER-IP:PORT` | Public address when a proxy or load balancer is in front |
| `--port N` | `8080` | Host port for plain HTTP (not used with `--domain`) |
| `--yes` | asks | Install Docker without asking (needed when no terminal is attached, such as cloud-init) |
| `--no-docker-install` | installs | Stop instead of installing Docker when it is missing |

Every option also has an environment variable: `INVENTOROS_VERSION`, `INVENTOROS_DIR`, `INVENTOROS_DOMAIN`, `INVENTOROS_URL`, `INVENTOROS_PORT` and `INVENTOROS_YES=true`.

## What it changes on the server

- Installs `curl` and `openssl` if they are missing.
- Installs Docker Engine and the Compose plugin with the official script from `get.docker.com` if Docker is missing (it asks first unless you pass `--yes`).
- Creates the installation directory with `docker-compose.prod.yml`, `docker-compose.https.yml`, a `.env` readable only by root, and `install.log`.
- Starts the containers. Data lives in Docker volumes: the database in `db-data`, uploads and backups in `storage`, plugins in `plugins` and `plugin-assets`.

The `.env` holds `APP_KEY`, which encrypts sessions and stored secrets. Back it up with the volumes. It also sets `COMPOSE_FILE`, so plain `docker compose` commands work from the directory:

```bash
cd /opt/inventoros
sudo docker compose ps
sudo docker compose logs -f app
sudo docker compose restart worker scheduler
```

## Upgrading

Run the installer again. It keeps your secrets and settings, moves the image to the new release and restarts the stack, and the app applies database migrations on start:

```bash
sudo bash install.sh                    # latest release
sudo bash install.sh --version 2.4.0    # a specific release
```

Take a backup first (see [Updates & Backups](updates)). Moving back to an older release is refused, because migrations only run forward; restore a backup instead.

Do not use the in-app updater on a Docker install. It replaces files inside the running container, and the change is lost when the container is recreated.

## Troubleshooting

The installer stops with a plain explanation when something is missing: not root, an unsupported architecture, too little disk, a port already in use, or no internet access. Everything it runs is recorded in `/opt/inventoros/install.log` (secrets are never written to it).

If the app does not become healthy, the installer prints the last lines of its output and writes the container status and logs to `install.log`. The most common causes:

- **`--domain` and the app stays unhealthy.** DNS for the domain does not point at this server yet, or ports 80 and 443 are blocked, so no certificate can be issued. Fix DNS or the firewall and run the installer again.
- **Port already in use.** Another web server is listening. Choose another port with `--port`, or stop the other server.
- **Could not download the image.** The server cannot reach `ghcr.io`, often because of an outbound firewall or proxy.

More fixes are listed under [Docker troubleshooting](installation-docker#troubleshooting). Still stuck? Open an issue with `install.log` attached: https://github.com/Inventoros/Inventoros/issues
