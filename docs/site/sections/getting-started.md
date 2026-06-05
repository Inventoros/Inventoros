Inventoros is a self-hosted, open-source inventory and warehouse management system built on Laravel. You run it on your own server, you own your data, and you choose how to deploy it.

This guide covers the system requirements and the three supported installation paths. Pick the one that matches your hosting.

### Recommended: the web installer

Every release ships with a guided web installer. The fastest way to get started is:

1. Download the latest release from GitHub.
2. Extract the files to your web server's document root.
3. Visit `http://yourdomain.com/install` in your browser.
4. Follow the on-screen instructions to complete setup.

Download the latest release: https://github.com/Inventoros/Inventoros/releases/latest

### Choose an installation method

- [Install on cPanel](#installation-cpanel). Perfect for shared hosting environments with cPanel access. Uses a pre-built release package, no npm required on the server.
- [Install on a VPS](#installation-vps). Full control on Ubuntu, Debian, or CentOS / RHEL with Nginx, PHP-FPM, and MySQL or MariaDB.
- [Install with Docker](#installation-docker). Containerized deployment. Official images are in progress; a do-it-yourself Dockerfile and Compose template are provided in the meantime.

### System requirements

Server requirements:

- PHP 8.2 or higher
- MySQL 8.0+ or PostgreSQL 13+
- Composer 2.0+
- Node.js 18+ and npm (only needed when building from source; the cPanel release ships pre-compiled assets)

Required PHP extensions:

- BCMath, Ctype, Fileinfo, JSON
- Mbstring, OpenSSL, PDO
- Tokenizer, XML, cURL
- ZIP, GD or Imagick
- Sodium (required for verifying signed releases used by the in-app updater)

### For developers

Once Inventoros is running, integrate it with the rest of your stack, or with AI assistants, through the public APIs:

- [REST & GraphQL API](#rest-api). Sanctum bearer auth, OpenAPI 3.0 spec, multi-tenant scoping, pagination, and code examples in curl, PHP, and JavaScript.
- [MCP Server](#mcp-server). Connect Claude Desktop, Claude Code, Cursor, and other AI clients to Inventoros over the Model Context Protocol, with full permission scoping.
- [Plugin Development](#plugins). Extend Inventoros with a WordPress-style hook and filter system.

### Need help?

- Report an issue: https://github.com/Inventoros/Inventoros/issues
- Community discussions: https://github.com/Inventoros/Inventoros/discussions
