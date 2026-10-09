<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | When Inventoros runs behind a reverse proxy or load balancer that
    | terminates TLS (nginx, Traefik, Caddy, a cloud load balancer), the app
    | only sees plain http from the proxy. Name the proxy here so its
    | X-Forwarded-For, -Host, -Port and -Proto headers are believed; then the
    | app builds https:// URLs, sets Secure cookies and logs the real client
    | address. Forwarded headers from any other address are ignored.
    |
    | A comma-separated list of IP addresses or CIDR ranges, for example
    | "172.18.0.0/16" or "10.0.0.5,10.0.0.6". "private_ranges" trusts every
    | private, loopback and link-local network (the Docker image's default).
    | "*" trusts whoever connects, and is only safe when nothing but the
    | proxy can reach the app. Empty (the default) trusts no proxy.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
