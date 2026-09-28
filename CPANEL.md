# cPanel Deployment Guide

This guide explains how to deploy Inventoros on shared hosting using cPanel.

## Prerequisites

Before deploying, ensure your hosting provider supports:
- PHP 8.4.1 or newer (8.4 and 8.5)
- MySQL 8.0+ or PostgreSQL 13+
- Composer (or SSH access to run it)
- Node.js 20.19+ or 22.12+ (for building assets, can be done locally)

## Deployment Steps

### 1. Prepare Your Local Build

Before uploading, build the frontend assets locally:

```bash
npm install
npm run build
```

This creates the production-ready assets in the `public/build` directory.

### 2. Upload Files to cPanel

You have two options for uploading:

**Option A: File Manager**
1. Log into cPanel
2. Open File Manager
3. Navigate to your domain's root directory (usually `public_html` or a subdomain folder)
4. Upload all files from the Inventoros project

**Option B: FTP/SFTP**
1. Connect using your FTP credentials from cPanel
2. Upload all project files to your domain's root directory

### 3. Configure Document Root

Laravel requires the `public` folder to be the document root. You have two approaches:

**Option A: Subdomain/Addon Domain (Recommended)**
1. In cPanel, go to **Domains** > **Subdomains** or **Addon Domains**
2. Set the document root to point to `/public_html/inventoros/public` (adjust path as needed)

**Option B: Main Domain with .htaccess**

If you must use the main `public_html` folder, add this `.htaccess` file to your root:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^(.*)$ public/$1 [L]
</IfModule>
```

### 4. Set Up Environment File

1. Rename or copy `.env.example` to `.env`
2. Update the following values:

```env
APP_NAME=Inventoros
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=your_database_name
DB_USERNAME=your_database_user
DB_PASSWORD=your_database_password
```

### 5. Create Database

1. In cPanel, go to **MySQL Databases**
2. Create a new database
3. Create a new database user with a strong password
4. Add the user to the database with **All Privileges**
5. Update your `.env` file with these credentials

### 6. Generate Application Key

If you have SSH access:

```bash
php artisan key:generate
```

If you don't have SSH access, generate a key locally and copy the `APP_KEY` value to your `.env` file on the server.

### 7. Run Migrations

**With SSH access:**

```bash
php artisan migrate --force
```

**Without SSH access:**

You can use the web-based installer at `/install` if the application hasn't been set up yet, or import the database schema manually through phpMyAdmin.

### 8. Set File Permissions

Ensure these directories are writable (755 or 775):

```
storage/
bootstrap/cache/
```

In cPanel File Manager:
1. Right-click on `storage` folder
2. Select **Change Permissions**
3. Set to `755` or `775`
4. Check "Recurse into subdirectories"
5. Repeat for `bootstrap/cache`

### 9. Configure the Scheduler and Queue Worker (Required)

Inventoros relies on background processing for two things:

- **The scheduler** runs `inventory:check-reorder-points` (low-stock alerts),
  `inventory:run-cycle-counts` (scheduled cycle counts, hourly),
  `reports:send-scheduled` (scheduled report emails, every 15 minutes),
  `shipping:track` (shipment tracking, every 30 minutes) and the nightly
  retention prune commands.
- **The queue** holds webhook deliveries (`WebhookDeliveryJob`) and queued
  mail. With `QUEUE_CONNECTION=database` (the default) the scheduler also works
  the queue every minute, so the one cron entry below covers both. Without that
  cron these rows pile up in the `jobs` and `webhook_deliveries` tables and
  **never fire**; the admin dashboard warns when the scheduler has not run for
  10 minutes or jobs have waited more than 15.

#### Scheduler cron (always add this)

1. In cPanel, go to **Cron Jobs**
2. Add a cron job that runs every minute:

```
* * * * * cd /home/username/inventoros && php artisan schedule:run >> /dev/null 2>&1
```

Replace `/home/username/inventoros` with your actual project path (the folder
containing `artisan`, not the `public` document root).

#### Queue worker

**Default: the scheduler runs it.** Keep `QUEUE_CONNECTION=database`. Each
minute `schedule:run` starts `queue:work --stop-when-empty --max-time=50`,
which exits when the queue is empty, so no second cron entry is needed. This is
controlled by `QUEUE_RUN_VIA_SCHEDULER` (on by default for the database queue).

**Option A: a persistent worker.** If your host supports a long-running
"daemon" process (some offer this via **Application Manager** or
`cpsupervisor`), run a worker there and set `QUEUE_RUN_VIA_SCHEDULER=false`:

```
php artisan queue:work --tries=3 --max-time=3600
```

**Option B: `sync` queue (simplest, no worker).**
If your host forbids extra cron/daemon processes, set the queue to run inline:

```env
QUEUE_CONNECTION=sync
```

Jobs then execute immediately inside the web request that dispatches them. This
needs no worker, but webhook deliveries and emails run synchronously, so a slow
or failing endpoint will slow down the request that triggered it. Acceptable for
low-volume single-tenant installs, and only needed when the host allows no cron at all.

> **Other deploy types.** The Docker stack (`docker-compose.prod.yml`) runs its own
> `worker` and `scheduler` containers, so it needs neither cron entry. A VPS install
> has no containers: install the systemd units in `deploy/systemd/` (a worker
> service plus a scheduler timer) or add the same two cron lines. See
> `docs/site/sections/installation-vps.md`.

### 10. Enable SSL

1. In cPanel, go to **SSL/TLS** or **Let's Encrypt SSL**
2. Generate and install an SSL certificate for your domain
3. Update `APP_URL` in `.env` to use `https://`

## Troubleshooting

### 500 Internal Server Error
- Check file permissions on `storage/` and `bootstrap/cache/`
- Verify `.env` file exists and has correct database credentials
- Check `storage/logs/laravel.log` for detailed error messages

### Blank Page
- Ensure `APP_DEBUG=true` temporarily to see errors
- Verify PHP version is 8.4 (check in cPanel > Select PHP Version)

### Assets Not Loading
- Verify you ran `npm run build` before uploading
- Check that `public/build` directory was uploaded
- Ensure your document root points to the `public` folder

### Database Connection Error
- Verify database credentials in `.env`
- Ensure the database user has proper privileges
- Check if `localhost` should be `127.0.0.1` (varies by host)

## PHP Version Selection

Most cPanel hosts allow you to select the PHP version:

1. Go to **Select PHP Version** or **MultiPHP Manager**
2. Select your domain
3. Choose PHP 8.4
4. Enable required extensions: `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`

## Performance Tips

- Enable OPcache in PHP settings
- Use Redis for session/cache if available (update `.env` accordingly)
- Enable gzip compression via `.htaccess`
- Consider using Cloudflare for CDN and additional caching
