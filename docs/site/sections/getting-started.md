Inventoros is a self-hosted, open-source inventory and warehouse management system built on Laravel. You run it on your own server, you own your data, and you choose how to deploy it.

This guide covers the installation methods and the system requirements. Pick the method that matches your hosting.

## Choose an installation method

| Method | Best for | What you need |
|---|---|---|
| [One-command install](installation-linux) (recommended) | A Linux VPS or dedicated server | Root access; the installer sets up Docker and everything else |
| [Install with Docker](installation-docker) | Servers that already run Docker, and IT teams | Docker and Docker Compose |
| [Cloud & Coolify](installation-cloud) | A new cloud server, or a Coolify instance | An account with a cloud provider, or Coolify |
| [Install on cPanel](installation-cpanel) | Shared hosting | cPanel with PHP 8.4 and a MySQL database |
| [Install on a VPS](installation-vps) | Running PHP directly, without Docker, from git or `composer create-project` | Nginx, PHP-FPM, MySQL or PostgreSQL, Composer and Node.js |

The Docker-based methods (one-command, Docker, cloud and Coolify) run the official production image with a queue worker, a scheduler and PostgreSQL, and upgrade by switching to the new release's image. cPanel and manual VPS installs use the PHP package and update from the admin panel.

On a Linux server, the quickest start is:

```bash
curl -fsSL https://inventoros.com/install.sh -o install.sh
less install.sh
sudo bash install.sh
```

If you run PHP and Node.js yourself, you can download Inventoros with Composer instead and finish in the web installer. The server setup around it (web server, queue worker, scheduler) is in [Install on a VPS](installation-vps#install-with-composer-instead-of-git).

```bash
composer create-project inventoros/inventoros inventoros --no-dev
cd inventoros
npm ci && npm run build
```

## Every method ends in the web installer

However you deploy, you finish at `https://your-address/install`. The guided installer checks the server, connects to the database and creates the tables, then creates your organization and the first administrator. With the Docker-based methods the database details are already filled in.

The installer first asks for a one-time **setup token**, so nobody else can finish the installation before you. It is `INVENTOROS_SETUP_TOKEN` in `.env` when that is set (the Linux installer and the Coolify template set it for you). Otherwise Inventoros writes a random token to `storage/app/private/setup-token` in the application folder the first time `/install` is opened, and logs the file's location. The file is deleted when the installation completes.

Download the latest release: https://github.com/Inventoros/Inventoros/releases/latest

## System requirements

The requirements below apply to cPanel and manual VPS installs. The Docker-based methods only need Docker (the one-command installer installs it), x86_64, 1 GB of memory (2 GB recommended) and 5 GB of disk.

### Server

- PHP 8.4.1 or newer (8.4 and 8.5)
- MySQL 8.0+ or PostgreSQL 13+
- Composer 2.0+
- Node.js 22.12+ and npm (Vite 8 and vue-i18n 11 need it; only needed when building from source; the cPanel release ships pre-compiled assets)

### Required PHP extensions

- BCMath, Ctype, Fileinfo, JSON
- Mbstring, OpenSSL, PDO with the MySQL or PostgreSQL driver (`pdo_mysql` or `pdo_pgsql`)
- Tokenizer, XML, cURL
- ZIP, GD or Imagick
- Sodium (required for verifying signed releases used by the in-app updater)

## For developers

Once Inventoros is running, integrate it with the rest of your stack, or with AI assistants, through the public APIs:

- [REST & GraphQL API](rest-api). Sanctum bearer auth, OpenAPI 3.1 spec, multi-tenant scoping, pagination, and code examples in curl, PHP, and JavaScript.
- [MCP Server](mcp-server). Connect Claude Desktop, Claude Code, Cursor, and other AI clients to Inventoros over the Model Context Protocol, with full permission scoping.
- [Plugin Development](plugins). Extend Inventoros with a WordPress-style hook and filter system.

## Need help?

- Report an issue or ask a question: https://github.com/Inventoros/Inventoros/issues

## License

Inventoros is licensed under the GNU Affero General Public License v3.0 only (AGPL-3.0-only) from v1.0.9 onwards. Releases up to and including v1.0.8 were published under the MIT License.
