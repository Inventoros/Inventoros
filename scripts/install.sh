#!/usr/bin/env bash
# Inventoros installer for Linux servers.
#
# Installs Docker if needed, downloads the production Compose files for one
# Inventoros release, generates secrets, starts the stack and waits until it
# is healthy. Then open the printed address to finish in the web installer.
#
#   curl -fsSL https://inventoros.com/install.sh -o install.sh
#   less install.sh
#   sudo bash install.sh
#
# Re-running it is safe: secrets in an existing .env are kept, and passing
# --version upgrades (or pins) the release. Run `bash install.sh --help` for
# every option.

set -Eeuo pipefail

REPO="Inventoros/Inventoros"
IMAGE_REPO="ghcr.io/inventoros/inventoros"
MIN_COMPOSE_VERSION="2.24.4"   # docker-compose.https.yml uses !override

INSTALL_DIR="${INVENTOROS_DIR:-/opt/inventoros}"
VERSION="${INVENTOROS_VERSION:-}"
DOMAIN="${INVENTOROS_DOMAIN:-}"
APP_URL_ARG="${INVENTOROS_URL:-}"
PORT="${INVENTOROS_PORT:-}"
ASSUME_YES="${INVENTOROS_YES:-false}"
INSTALL_DOCKER=true
HEALTH_TIMEOUT="${INVENTOROS_HEALTH_TIMEOUT:-300}"

LOG_FILE=""

# --- output -----------------------------------------------------------------

if [ -t 1 ]; then
    BOLD=$'\033[1m'; DIM=$'\033[2m'; RED=$'\033[31m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; RESET=$'\033[0m'
else
    BOLD=""; DIM=""; RED=""; GREEN=""; YELLOW=""; RESET=""
fi

log() { [ -n "$LOG_FILE" ] && printf '%s %s\n' "$(date -u +%FT%TZ)" "$*" >> "$LOG_FILE"; return 0; }
step() { printf '\n%s==>%s %s\n' "$BOLD" "$RESET" "$*"; log "STEP $*"; }
info() { printf '    %s\n' "$*"; log "INFO $*"; }
ok() { printf '    %s%s%s\n' "$GREEN" "$*" "$RESET"; log "OK $*"; }
warn() { printf '    %sWarning:%s %s\n' "$YELLOW" "$RESET" "$*" >&2; log "WARN $*"; }
die() { printf '\n%sError:%s %s\n' "$RED" "$RESET" "$*" >&2; log "ERROR $*"; exit 1; }

on_error() {
    local status=$? line=$1
    log "FAILED status=$status line=$line"
    printf '\n%sThe installer stopped unexpectedly (line %s, exit %s).%s\n' "$RED" "$line" "$status" "$RESET" >&2
    [ -n "$LOG_FILE" ] && printf 'Details: %s\n' "$LOG_FILE" >&2
    printf 'Report it at https://github.com/%s/issues with that file attached (it contains no secrets).\n' "$REPO" >&2
}
trap 'on_error $LINENO' ERR

usage() {
    cat <<EOF
Inventoros installer

Usage: sudo bash install.sh [options]

Options:
  --version X.Y.Z     Release to install or upgrade to (default: latest release)
  --dir PATH          Installation directory (default: /opt/inventoros)
  --domain HOST       Serve HTTPS on HOST with an automatic Let's Encrypt
                      certificate. Needs DNS for HOST pointing at this server
                      and ports 80 and 443 open.
  --url URL           Public address people will use, when a reverse proxy or
                      load balancer sits in front (default: http://SERVER-IP:PORT)
  --port N            Host port for plain HTTP when --domain is not used
                      (default: 8080)
  --yes               Do not ask before installing Docker
  --no-docker-install Fail instead of installing Docker when it is missing
  -h, --help          Show this help

Each option can also be set with an environment variable: INVENTOROS_VERSION,
INVENTOROS_DIR, INVENTOROS_DOMAIN, INVENTOROS_URL, INVENTOROS_PORT and
INVENTOROS_YES=true.

Examples:
  sudo bash install.sh
  sudo bash install.sh --domain inventory.example.com
  sudo bash install.sh --version 2.5.0 --port 8088
EOF
}

# --- arguments --------------------------------------------------------------

need_value() { [ $# -ge 2 ] && [ -n "$2" ] || die "$1 needs a value. See --help."; }

while [ $# -gt 0 ]; do
    case "$1" in
        --version) need_value "$@"; VERSION="$2"; shift 2 ;;
        --dir) need_value "$@"; INSTALL_DIR="$2"; shift 2 ;;
        --domain) need_value "$@"; DOMAIN="$2"; shift 2 ;;
        --url) need_value "$@"; APP_URL_ARG="$2"; shift 2 ;;
        --port) need_value "$@"; PORT="$2"; shift 2 ;;
        --yes|-y) ASSUME_YES=true; shift ;;
        --no-docker-install) INSTALL_DOCKER=false; shift ;;
        -h|--help) usage; exit 0 ;;
        *) die "Unknown option: $1. See --help." ;;
    esac
done

VERSION="${VERSION#v}"
[ -z "$VERSION" ] || [[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "--version must look like 2.5.0, got '$VERSION'."
[ -z "$PORT" ] || { [[ "$PORT" =~ ^[0-9]+$ ]] && [ "$PORT" -ge 1 ] && [ "$PORT" -le 65535 ]; } || die "--port must be a number from 1 to 65535."
[ -z "$DOMAIN" ] || [[ "$DOMAIN" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ && "$DOMAIN" == *.* ]] || die "--domain must be a host name like inventory.example.com (no https:// or path)."
[ -z "$APP_URL_ARG" ] || [[ "$APP_URL_ARG" =~ ^https?://[^/[:space:]]+$ ]] || die "--url must be like https://inventory.example.com (scheme and host, no path)."
[ -z "$DOMAIN" ] || [ -z "$PORT" ] || die "--port only applies without --domain; HTTPS uses ports 80 and 443."
[ -z "$DOMAIN" ] || [ -z "$APP_URL_ARG" ] || die "Use either --domain (HTTPS served by Inventoros) or --url (behind your own proxy), not both."
case "$ASSUME_YES" in true|1|yes) ASSUME_YES=true ;; *) ASSUME_YES=false ;; esac

# --- helpers ----------------------------------------------------------------

have() { command -v "$1" >/dev/null 2>&1; }

confirm() {
    [ "$ASSUME_YES" = true ] && return 0
    if [ ! -r /dev/tty ]; then
        die "$1 Re-run with --yes to allow it without asking."
    fi
    local reply
    printf '    %s [y/N] ' "$1"
    read -r reply < /dev/tty || reply=""
    [[ "$reply" =~ ^[Yy]([Ee][Ss])?$ ]]
}

# curl with retries; writes to $2 when given, else stdout.
fetch() {
    local url=$1 out=${2:-}
    local args=(-fsSL --retry 4 --retry-delay 3 --connect-timeout 15 --max-time 120)
    log "GET $url"
    if [ -n "$out" ]; then curl "${args[@]}" -o "$out" "$url"; else curl "${args[@]}" "$url"; fi
}

version_ge() { [ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -n1)" = "$2" ]; }

random_hex() { openssl rand -hex "$1"; }
random_app_key() { printf 'base64:%s' "$(openssl rand -base64 32)"; }

env_get() { [ -f "$ENV_FILE" ] && grep -E "^$1=" "$ENV_FILE" | tail -n1 | cut -d= -f2- || true; }

# Set KEY=VALUE in .env, replacing an existing line.
env_set() {
    local key=$1 value=$2 tmp
    tmp="$(mktemp "${ENV_FILE}.XXXXXX")"
    if [ -f "$ENV_FILE" ]; then grep -vE "^${key}=" "$ENV_FILE" > "$tmp" || true; fi
    printf '%s=%s\n' "$key" "$value" >> "$tmp"
    chmod 600 "$tmp"
    mv "$tmp" "$ENV_FILE"
}

port_in_use() {
    if have ss; then ss -Hltn "sport = :$1" 2>/dev/null | grep -q .
    elif have netstat; then netstat -ltn 2>/dev/null | awk '{print $4}' | grep -Eq "[:.]$1\$"
    else return 1
    fi
}

compose() { (cd "$INSTALL_DIR" && docker compose "$@"); }

# --- checks -----------------------------------------------------------------

printf '%sInventoros installer%s\n' "$BOLD" "$RESET"

[ "$(uname -s)" = "Linux" ] || die "This installer supports Linux servers. On macOS or Windows use Docker Desktop and the Docker guide: https://inventoros.com/docs/installation-docker"
[ "$(id -u)" -eq 0 ] || die "Run the installer as root, for example: sudo bash install.sh"

mkdir -p "$INSTALL_DIR"
INSTALL_DIR="$(cd "$INSTALL_DIR" && pwd)"
ENV_FILE="$INSTALL_DIR/.env"
LOG_FILE="$INSTALL_DIR/install.log"
: >> "$LOG_FILE"
chmod 600 "$LOG_FILE"
log "installer started: $*"

step "Checking this server"

OS_ID="unknown"; OS_LIKE=""; OS_NAME="Linux"
if [ -r /etc/os-release ]; then
    # In a subshell: os-release defines VERSION, which would replace ours.
    # shellcheck disable=SC1091
    OS_ID="$(. /etc/os-release && printf '%s' "${ID:-unknown}")"
    # shellcheck disable=SC1091
    OS_LIKE="$(. /etc/os-release && printf '%s' "${ID_LIKE:-}")"
    # shellcheck disable=SC1091
    OS_NAME="$(. /etc/os-release && printf '%s' "${PRETTY_NAME:-${ID:-Linux}}")"
fi
info "System: $OS_NAME ($(uname -m))"

case "$(uname -m)" in
    x86_64|amd64) ;;
    *) die "Inventoros images are published for x86_64 (amd64) only, and this server is $(uname -m). Build the image from source instead: https://inventoros.com/docs/installation-docker#building-the-image-yourself" ;;
esac

supported_os=false
case " $OS_ID $OS_LIKE " in
    *" ubuntu "*|*" debian "*|*" rhel "*|*" centos "*|*" fedora "*|*" rocky "*|*" almalinux "*) supported_os=true ;;
esac
[ "$supported_os" = true ] || warn "$OS_NAME is not tested. Ubuntu, Debian and RHEL-family systems are supported; continuing."

mem_kb="$(awk '/^MemTotal:/ {print $2}' /proc/meminfo 2>/dev/null || echo 0)"
if [ "${mem_kb:-0}" -lt 900000 ]; then
    warn "This server has $((mem_kb / 1024)) MB of memory. Inventoros needs at least 1 GB; 2 GB is recommended."
else
    info "Memory: $((mem_kb / 1024)) MB"
fi

free_kb="$(df -Pk "$INSTALL_DIR" | awk 'NR==2 {print $4}')"
docker_root_free_kb="$(df -Pk /var/lib 2>/dev/null | awk 'NR==2 {print $4}' || echo "$free_kb")"
[ "${docker_root_free_kb:-0}" -lt "${free_kb:-0}" ] && free_kb="$docker_root_free_kb"
if [ "${free_kb:-0}" -lt 3000000 ]; then
    die "Only $((free_kb / 1024)) MB of disk space is free. Inventoros needs at least 5 GB (the image, the database and uploads)."
elif [ "${free_kb:-0}" -lt 5000000 ]; then
    warn "Only $((free_kb / 1024 / 1024)) GB of disk space is free; 5 GB or more is recommended."
else
    info "Free disk: $((free_kb / 1024 / 1024)) GB"
fi

missing=()
for tool in curl openssl; do have "$tool" || missing+=("$tool"); done
if [ ${#missing[@]} -gt 0 ]; then
    info "Installing ${missing[*]}"
    if have apt-get; then DEBIAN_FRONTEND=noninteractive apt-get update -qq >> "$LOG_FILE" 2>&1 && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "${missing[@]}" ca-certificates >> "$LOG_FILE" 2>&1
    elif have dnf; then dnf install -y -q "${missing[@]}" ca-certificates >> "$LOG_FILE" 2>&1
    elif have yum; then yum install -y -q "${missing[@]}" ca-certificates >> "$LOG_FILE" 2>&1
    else die "Install ${missing[*]} and run the installer again."
    fi
fi

# --- docker -----------------------------------------------------------------

step "Checking Docker"

if ! have docker; then
    [ "$INSTALL_DOCKER" = true ] || die "Docker is not installed. Install Docker Engine (https://docs.docker.com/engine/install/) and run the installer again."
    info "Docker is not installed. The official script from get.docker.com installs Docker Engine and the Compose plugin."
    confirm "Install Docker now?" || die "Docker is required. Install it (https://docs.docker.com/engine/install/) and run the installer again."
    fetch https://get.docker.com "$INSTALL_DIR/get-docker.sh"
    info "Installing Docker (this takes a minute or two)"
    sh "$INSTALL_DIR/get-docker.sh" >> "$LOG_FILE" 2>&1 || die "Docker installation failed. See $LOG_FILE."
    rm -f "$INSTALL_DIR/get-docker.sh"
    ok "Docker installed"
fi

if ! docker info >/dev/null 2>&1; then
    if have systemctl; then
        info "Starting the Docker service"
        systemctl enable --now docker >> "$LOG_FILE" 2>&1 || true
    fi
    for _ in $(seq 1 15); do docker info >/dev/null 2>&1 && break; sleep 2; done
    docker info >/dev/null 2>&1 || die "Docker is installed but not running. Start it (systemctl start docker) and run the installer again."
fi
info "Docker $(docker version --format '{{.Server.Version}}' 2>/dev/null || echo unknown)"

docker compose version >/dev/null 2>&1 || die "The Docker Compose plugin is missing. Install docker-compose-plugin (https://docs.docker.com/compose/install/linux/) and run the installer again."
compose_version="$(docker compose version --short 2>/dev/null | sed 's/^v//')"
info "Docker Compose $compose_version"
if [ -n "$DOMAIN" ] && ! version_ge "$compose_version" "$MIN_COMPOSE_VERSION"; then
    die "--domain needs Docker Compose $MIN_COMPOSE_VERSION or newer (found $compose_version). Update Docker and run the installer again."
fi

# --- release ----------------------------------------------------------------

step "Choosing the release"

if [ -z "$VERSION" ]; then
    latest_json="$(fetch "https://api.github.com/repos/$REPO/releases/latest")" || die "Could not reach GitHub to find the latest release. Check the server's internet access, or pass --version."
    VERSION="$(printf '%s' "$latest_json" | grep -m1 '"tag_name"' | sed -E 's/.*"tag_name"[[:space:]]*:[[:space:]]*"v?([^"]+)".*/\1/')"
    [[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "Could not read the latest release version from GitHub. Pass --version, for example --version 2.5.0."
fi

previous_image="$(env_get INVENTOROS_IMAGE)"
previous_version="${previous_image##*:}"
if [ -n "$previous_image" ] && [ "$previous_version" != "$VERSION" ] && [[ "$previous_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] && ! version_ge "$VERSION" "$previous_version"; then
    die "This server runs $previous_version. Moving back to $VERSION is not supported: database migrations only run forward. Restore a backup to go back."
fi
if [ -n "$previous_image" ] && [ "$previous_version" != "$VERSION" ]; then
    info "Upgrading from ${previous_version} to $VERSION"
else
    info "Inventoros $VERSION"
fi

# --- files ------------------------------------------------------------------

step "Preparing $INSTALL_DIR"

raw="https://raw.githubusercontent.com/$REPO/v$VERSION"
for file in docker-compose.prod.yml docker-compose.https.yml; do
    if [ -n "${INVENTOROS_SOURCE_DIR:-}" ]; then
        # Testing hook: use a checkout's Compose files instead of the release's.
        cp "$INVENTOROS_SOURCE_DIR/$file" "$INSTALL_DIR/$file.download"
    else
        fetch "$raw/$file" "$INSTALL_DIR/$file.download" || die "Could not download $file for v$VERSION. Check that the version exists: https://github.com/$REPO/releases"
    fi
    mv "$INSTALL_DIR/$file.download" "$INSTALL_DIR/$file"
done
ok "Got the Compose files for v$VERSION"

if [ -f "$ENV_FILE" ] && [ -n "$(env_get APP_KEY)" ]; then
    info "Keeping the existing secrets in $ENV_FILE"
else
    [ -f "$ENV_FILE" ] || { : > "$ENV_FILE"; chmod 600 "$ENV_FILE"; }
    env_set APP_KEY "$(random_app_key)"
    ok "Generated APP_KEY"
fi
if [ -z "$(env_get DB_PASSWORD)" ]; then
    env_set DB_PASSWORD "$(random_hex 24)"
    ok "Generated a database password"
fi

# Remember the chosen access mode across re-runs unless a flag changes it.
[ -n "$DOMAIN" ] || [ -n "$PORT" ] || [ -n "$APP_URL_ARG" ] || DOMAIN="$(env_get INVENTOROS_DOMAIN)"

if [ -n "$DOMAIN" ]; then
    for p in 80 443; do
        if port_in_use "$p" && ! compose ps --status running --services 2>/dev/null | grep -qx app; then
            die "Port $p is already in use. HTTPS mode needs ports 80 and 443 free. Stop the other web server, or put your own proxy in front and use --url instead."
        fi
    done
    env_set INVENTOROS_DOMAIN "$DOMAIN"
    env_set APP_URL "https://$DOMAIN"
    env_set COMPOSE_FILE "docker-compose.prod.yml:docker-compose.https.yml"
    APP_URL="https://$DOMAIN"
else
    [ -n "$PORT" ] || PORT="$(env_get APP_PORT)"
    [ -n "$PORT" ] || PORT=8080
    if port_in_use "$PORT" && ! compose ps --status running --services 2>/dev/null | grep -qx app; then
        die "Port $PORT is already in use. Choose another with --port, for example --port 8088."
    fi
    if [ -n "$APP_URL_ARG" ]; then
        APP_URL="$APP_URL_ARG"
    else
        APP_URL="$(env_get APP_URL)"
        if [ -z "$APP_URL" ] || [ "$(env_get APP_PORT)" != "$PORT" ] || [ -n "$(env_get INVENTOROS_DOMAIN)" ]; then
            ip="$(hostname -I 2>/dev/null | awk '{print $1}' || true)"
            APP_URL="http://${ip:-localhost}:$PORT"
        fi
    fi
    sed -i '/^INVENTOROS_DOMAIN=/d' "$ENV_FILE"
    env_set APP_PORT "$PORT"
    env_set APP_URL "$APP_URL"
    env_set COMPOSE_FILE "docker-compose.prod.yml"
fi
env_set INVENTOROS_IMAGE "$IMAGE_REPO:$VERSION"
chmod 600 "$ENV_FILE"
info "Address: $APP_URL"

# --- start ------------------------------------------------------------------

step "Downloading the Inventoros image"

if [ "${INVENTOROS_SKIP_PULL:-false}" = true ]; then
    # Testing hook: run an image that is already on this machine.
    docker image inspect "$IMAGE_REPO:$VERSION" >/dev/null 2>&1 || die "INVENTOROS_SKIP_PULL is set but $IMAGE_REPO:$VERSION is not on this server."
elif ! compose pull --quiet >> "$LOG_FILE" 2>&1; then
    if docker image inspect "$IMAGE_REPO:$VERSION" >/dev/null 2>&1; then
        warn "Could not pull images; using $IMAGE_REPO:$VERSION already on this server."
    else
        die "Could not download $IMAGE_REPO:$VERSION. Check the server's internet access and that the release exists. Details: $LOG_FILE"
    fi
fi
ok "Images ready"

step "Starting Inventoros"

compose up -d --remove-orphans >> "$LOG_FILE" 2>&1 || die "docker compose up failed. Details: $LOG_FILE"

info "Waiting for the app to become healthy (database setup can take a minute)"
deadline=$((SECONDS + HEALTH_TIMEOUT))
state="starting"
while [ $SECONDS -lt $deadline ]; do
    cid="$(compose ps -q app 2>/dev/null || true)"
    if [ -n "$cid" ]; then
        state="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$cid" 2>/dev/null || echo unknown)"
        [ "$state" = "healthy" ] && break
        if [ "$(docker inspect --format '{{.State.Status}}' "$cid" 2>/dev/null)" = "exited" ]; then state="exited"; break; fi
    fi
    sleep 5
done

if [ "$state" != "healthy" ]; then
    {
        echo "--- docker compose ps"; compose ps 2>&1 || true
        echo "--- app logs"; compose logs --no-color --tail 80 app 2>&1 || true
        echo "--- db logs"; compose logs --no-color --tail 30 db 2>&1 || true
    } >> "$LOG_FILE"
    printf '\n%sInventoros did not become healthy (state: %s).%s\n' "$RED" "$state" "$RESET" >&2
    printf 'Recent app output:\n%s\n' "$(compose logs --no-color --tail 15 app 2>&1 || true)" >&2
    printf '\nFull diagnostics were written to %s\n' "$LOG_FILE" >&2
    if [ -n "$DOMAIN" ]; then
        printf 'With --domain, the app stays unhealthy until Lets Encrypt issues a certificate: check that %s points at this server and ports 80 and 443 are open.\n' "$DOMAIN" >&2
    fi
    printf 'Common fixes: https://inventoros.com/docs/installation-docker#troubleshooting\n' >&2
    exit 1
fi
ok "Inventoros is running"

# --- done -------------------------------------------------------------------

printf '\n%sInventoros %s is ready.%s\n\n' "$GREEN$BOLD" "$VERSION" "$RESET"
if [ -n "$previous_image" ]; then
    printf '  Open %s and sign in.\n' "$APP_URL"
else
    printf '  1. Open %s/install\n' "$APP_URL"
    printf '  2. On the database step, host, port, database and user are filled in.\n'
    printf '     The password is DB_PASSWORD in %s. Show it with:\n' "$ENV_FILE"
    printf '       sudo grep DB_PASSWORD %s\n' "$ENV_FILE"
    printf '  3. Create your organization and administrator account.\n'
fi
cat <<EOF

  Files:   $INSTALL_DIR  (keep .env safe: APP_KEY encrypts stored secrets)
  Manage:  cd $INSTALL_DIR && docker compose ps | logs -f app | restart
  Upgrade: sudo bash install.sh --version X.Y.Z   (or re-run for the latest)
  Backups: https://inventoros.com/docs/updates
EOF
[ -n "$DOMAIN" ] || printf '\n  %sFor HTTPS, re-run with --domain your.domain, or put a proxy in front and pass --url.%s\n' "$DIM" "$RESET"
log "installer finished: version=$VERSION url=$APP_URL"
