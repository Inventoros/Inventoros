# Direct HTTPS with Docker

The production stack supports both an existing TLS reverse proxy and Caddy's
automatic HTTPS. The default remains the proxy-friendly port 8080.

For Caddy to obtain and renew a certificate directly:

1. Point a public hostname's DNS records at this host.
2. Set `INVENTOROS_DOMAIN=inventory.example.com` alongside `APP_KEY` and
   `DB_PASSWORD` in the Compose `.env` file. Use a hostname without a scheme,
   path or port.
3. Allow inbound TCP ports 80 and 443; UDP 443 is optional for HTTP/3.
4. Run with Docker Compose 2.24.4 or later:

```sh
docker compose -f docker-compose.prod.yml -f docker-compose.https.yml config
docker compose -f docker-compose.prod.yml -f docker-compose.https.yml up -d
```

Open `https://inventory.example.com/install`. The override replaces the base
port mapping, keeps certificate/account data in named volumes, and sets the
same HTTPS application URL for the app, worker and scheduler so links in email
and background work use the public address. The existing health check follows
`SERVER_NAME` and probes HTTPS locally.

Keep using both files for subsequent `up`, `down`, `logs` and upgrade commands.
Back up `caddy-data` and `caddy-config` along with the normal application volumes.
Do not use `down -v` during normal upgrades: it deletes persistent volumes.
The default stack remains available when TLS is handled by another proxy.

[Compose override semantics](https://docs.docker.com/reference/compose-file/merge/)
explain why `!override` is required to remove the original port mapping.
