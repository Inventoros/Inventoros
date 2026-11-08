Inventoros runs on any cloud server or self-hosted platform that runs Docker. This page covers the quickest routes: a new cloud server that installs Inventoros as it boots, and Coolify. Every route uses the official image and ends in the web installer, where you create your organization and administrator.

Whichever you choose, Inventoros needs persistent storage for the database, uploads and plugins. Platforms that throw away the filesystem on each deploy (most "serverless" and app-platform products) are not a good fit unless you configure [external storage](https://github.com/Inventoros/Inventoros/blob/main/docs/external-storage.md) and a managed database.

## A new cloud server (DigitalOcean, Hetzner, Vultr, Linode, AWS)

Most providers accept a cloud-init script, called **User data**, when you create a server. Inventoros provides one that runs the [one-command installer](installation-linux) on first boot.

1. Create a server with **Ubuntu 24.04**, x86_64, and at least 1 GB of memory (2 GB recommended).
2. In the advanced options, find **User data** (DigitalOcean: *Advanced options > Add initialization scripts*; Hetzner: *Cloud config*; AWS: *Advanced details > User data*) and paste:

```yaml
#cloud-config
package_update: true
packages:
  - curl
  - ca-certificates
  - openssl
runcmd:
  - curl -fsSL https://inventoros.com/install.sh -o /root/inventoros-install.sh
  - bash /root/inventoros-install.sh --yes
```

3. Create the server and wait about five minutes after it boots.
4. Open `http://SERVER-IP:8080/install` and complete the web installer. The database password is `DB_PASSWORD` in `/opt/inventoros/.env` on the server.

The same file is in the repository as [`deploy/cloud-init.yaml`](https://github.com/Inventoros/Inventoros/blob/main/deploy/cloud-init.yaml).

Some providers (AWS in particular) give the server a private address, so the printed address points at it. Pass the public address with `--url`, or use a domain:

- **HTTPS from the start:** create a DNS record for your domain pointing at the server's public IP first, then change the last line to `bash /root/inventoros-install.sh --yes --domain inventory.example.com`.
- **Pin a release:** add `--version 2.4.0`.

Progress is written to `/opt/inventoros/install.log` and `/var/log/cloud-init-output.log`. Make sure the provider's firewall allows port 8080, or 80 and 443 with `--domain`.

## Coolify

[Coolify](https://coolify.io) is an open-source, self-hosted platform that deploys Docker Compose stacks with automatic HTTPS. Inventoros has a ready-made Coolify template that generates the domain, the `APP_KEY` and the database password, so there is nothing to fill in.

1. In your Coolify project, choose **+ New**, then **Docker Compose Empty**.
2. Paste the contents of [`deploy/coolify/docker-compose.yml`](https://github.com/Inventoros/Inventoros/blob/main/deploy/coolify/docker-compose.yml).
3. Optionally change the generated domain for the `app` service under its settings.
4. Deploy. When the `app` service is healthy, open its URL with `/install` added and complete the web installer. On the database step, use the password shown as `SERVICE_PASSWORD_64_POSTGRES` in the resource's environment variables.

The template runs the app, the queue worker, the scheduler and PostgreSQL 17, with named volumes for the database, storage and plugins. Generated values stay the same across redeploys.

**Upgrading:** set `INVENTOROS_VERSION` to the new release in the environment variables and redeploy. The app applies database migrations on start.

**Mail:** set `MAIL_MAILER=smtp` and the `MAIL_` variables in the environment variables. Until then, mail is written to the log.

## Other platforms

Any platform that runs Docker Compose with persistent volumes can use [`docker-compose.prod.yml`](installation-docker) directly. Portainer, Dokploy, CapRover, Synology and TrueNAS users can follow the [Docker guide](installation-docker): the same four services, the same environment variables, and the same volumes to keep.

On a platform without persistent volumes, run the web app, worker and scheduler from the image `ghcr.io/inventoros/inventoros`, connect a managed PostgreSQL or MySQL database, and configure [external storage](https://github.com/Inventoros/Inventoros/blob/main/docs/external-storage.md) for uploads. Plugins installed at runtime are not kept on such platforms.
