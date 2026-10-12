Inventoros is a self-hosted, open-source inventory and warehouse management system built on Laravel. You run it on your own server, you own your data, and you choose how to deploy it.

This guide covers the system requirements and the three supported installation paths. Pick the one that matches your hosting.

## Recommended: the web installer

Every release ships with a guided web installer. The fastest way to get started is:

1. Download the latest release package, `inventoros-cpanel-X.X.X.zip`, from GitHub.
2. Upload the `inventoros/` folder beside your web root (for example your home directory) and the contents of `public_html/` into your web server's document root. The [cPanel guide](installation-cpanel) walks through this.
3. Visit `http://yourdomain.com/install` in your browser.
4. Follow the on-screen instructions: the installer checks the server requirements, connects to your database and creates the tables, then creates the first administrator.

Download the latest release: https://github.com/Inventoros/Inventoros/releases/latest

## Choose an installation method

- [Install on cPanel](installation-cpanel). Perfect for shared hosting environments with cPanel access. Uses a pre-built release package, no npm required on the server.
- [Install on a VPS](installation-vps). Full control on Ubuntu, Debian, or CentOS / RHEL with Nginx, PHP-FPM, and MySQL or MariaDB.
- [Install with Docker](installation-docker). The official production image from GHCR, with a Compose file for the app, queue worker, scheduler and PostgreSQL.

## System requirements

### Server

- PHP 8.4.1 or newer (8.4 and 8.5)
- MySQL 8.0+ or PostgreSQL 13+
- Composer 2.0+
- Node.js 20.19+ or 22.12+ and npm (required by Vite 7; only needed when building from source; the cPanel release ships pre-compiled assets)

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
