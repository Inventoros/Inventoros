#!/bin/sh
# Runs scripts/install.sh end to end inside an official bash image, with a stub
# `docker` so no daemon or image is needed. Bash 3.2 (what macOS ships) is the
# oldest version the installer supports.
#
#   docker run --rm -e EXPECT_BASH=3.2 -v "$PWD:/src:ro" bash:3.2 sh /src/tests/installer/smoke.sh
#
# Exits non-zero on the first failed expectation.
set -eu

apk add --no-cache -q curl openssl coreutils >/dev/null

bash --version | head -n1
expect="${EXPECT_BASH:-3.2}"
actual="$(bash -c 'echo ${BASH_VERSINFO[0]}.${BASH_VERSINFO[1]}')"
[ "$actual" = "$expect" ] || { echo "expected Bash $expect, got $actual"; exit 1; }

stub=/tmp/stub-bin
mkdir -p "$stub"
cat > "$stub/docker" <<'EOF'
#!/bin/sh
case "$1" in
    info) exit 0 ;;
    version) echo 29.0.0 ;;
    image) exit 0 ;;
    inspect) case "$3" in *Health*) echo healthy ;; *) echo running ;; esac ;;
    compose)
        shift
        case "$1" in
            version) if [ "${2:-}" = --short ]; then echo 2.30.0; else echo "Docker Compose version v2.30.0"; fi ;;
            ps) if [ "${2:-}" = -q ]; then echo stubcontainer; fi ;;
            *) exit 0 ;;
        esac ;;
esac
EOF
chmod +x "$stub/docker"
export PATH="$stub:$PATH"
export INVENTOROS_SOURCE_DIR=/src INVENTOROS_SKIP_PULL=true INVENTOROS_HEALTH_TIMEOUT=30

fail() { echo "FAIL: $*"; exit 1; }

# Argument validation
bash /src/scripts/install.sh --help | grep -q "Usage: sudo bash install.sh" || fail "--help"
if bash /src/scripts/install.sh --port 99999 2>/tmp/err; then fail "bad --port accepted"; fi
grep -q -- "--port must be a number" /tmp/err || fail "bad --port message"

# Fresh install
out="$(bash /src/scripts/install.sh --version 2.4.0 --dir /tmp/inv --port 8090 --yes 2>&1)" || { echo "$out"; fail "fresh install"; }
echo "$out" | grep -q "Inventoros 2.4.0 is ready" || { echo "$out"; fail "fresh install did not finish"; }
grep -q '^APP_KEY=base64:' /tmp/inv/.env || fail "APP_KEY not generated"
grep -q '^DB_PASSWORD=' /tmp/inv/.env || fail "DB_PASSWORD not generated"
grep -Eq '^INVENTOROS_SETUP_TOKEN=[0-9a-f]{48}$' /tmp/inv/.env || fail "setup token not generated"
echo "$out" | grep -q "sudo grep INVENTOROS_SETUP_TOKEN /tmp/inv/.env" || { echo "$out"; fail "setup token instructions"; }
if echo "$out" | grep -q "$(grep '^INVENTOROS_SETUP_TOKEN=' /tmp/inv/.env | cut -d= -f2)"; then fail "setup token printed"; fi
grep -q '^APP_PORT=8090$' /tmp/inv/.env || fail "APP_PORT not set"
grep -q '^INVENTOROS_IMAGE=ghcr.io/inventoros/inventoros:2.4.0$' /tmp/inv/.env || fail "image not pinned"
key="$(grep '^APP_KEY=' /tmp/inv/.env)"
setup_token="$(grep '^INVENTOROS_SETUP_TOKEN=' /tmp/inv/.env)"

# Re-run upgrades and keeps secrets
bash /src/scripts/install.sh --version 2.5.0 --dir /tmp/inv --yes >/dev/null 2>&1 || fail "upgrade"
[ "$(grep '^APP_KEY=' /tmp/inv/.env)" = "$key" ] || fail "APP_KEY changed on re-run"
[ "$(grep '^INVENTOROS_SETUP_TOKEN=' /tmp/inv/.env)" = "$setup_token" ] || fail "setup token changed on re-run"
grep -q ':2.5.0$' /tmp/inv/.env || fail "upgrade did not pin 2.5.0"

# An upgrade of an instance installed without a setup token does not add one
sed -i '/^INVENTOROS_SETUP_TOKEN=/d' /tmp/inv/.env
out="$(bash /src/scripts/install.sh --version 2.5.0 --dir /tmp/inv --yes 2>&1)" || { echo "$out"; fail "upgrade without token"; }
if grep -q '^INVENTOROS_SETUP_TOKEN=' /tmp/inv/.env; then fail "upgrade generated a setup token"; fi
if echo "$out" | grep -q "setup token"; then echo "$out"; fail "upgrade mentioned the setup token"; fi

# Downgrade is refused
if bash /src/scripts/install.sh --version 2.4.0 --dir /tmp/inv --yes >/dev/null 2>&1; then fail "downgrade accepted"; fi

echo "Installer works under Bash $actual"
