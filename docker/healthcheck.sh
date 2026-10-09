#!/bin/sh
# Container health check for the Inventoros production image.
#
# Requests Laravel's /up endpoint at the address Caddy actually serves, which
# follows SERVER_NAME: ":8080" (the default) and ":<port>" are plain HTTP on
# that port; a domain name means Caddy serves HTTPS on 443 itself. A fixed
# probe of :8080 reported the container unhealthy whenever SERVER_NAME was
# changed, which also kept the worker and scheduler from starting.
set -eu

addr="${SERVER_NAME:-:8080}"
addr="${addr%%,*}"   # several site addresses: probe the first
addr="${addr%% *}"

case "$addr" in
    https://*) scheme=https; hostport="${addr#https://}" ;;
    http://*)  scheme=http;  hostport="${addr#http://}" ;;
    :*)        scheme=http;  hostport="$addr" ;;
    *)         scheme=https; hostport="$addr" ;;
esac
hostport="${hostport%%/*}"

host="${hostport%:*}"
port="${hostport##*:}"
if [ "$host" = "$hostport" ]; then
    # No port given.
    port=""
fi
[ -n "$host" ] || host=localhost
case "$host" in
    \*|\*.*) host=localhost ;;   # wildcard site addresses
esac
if [ -z "$port" ]; then
    if [ "$scheme" = https ]; then port=443; else port=80; fi
fi

# --resolve keeps the request on this container while sending the configured
# host name, so Caddy matches the site and presents its certificate (-k:
# the certificate may not be issued yet, and only reachability matters here).
exec curl -fsS -k -o /dev/null --max-time 4 \
    --resolve "${host}:${port}:127.0.0.1" "${scheme}://${host}:${port}/up"
