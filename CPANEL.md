# cPanel Deployment Guide

This guide explains how to deploy Inventoros on shared hosting using cPanel.

## Prerequisites

Before deploying, ensure your hosting provider supports:
- PHP 8.2 or higher
- MySQL 8.0+ or PostgreSQL 13+
- Composer (or SSH access to run it)
- Node.js 18+ (for building assets, can be done locally)

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

### 9. Configure Cron Jobs (Optional)

If you need Laravel's task scheduler:

1. In cPanel, go to **Cron Jobs**
2. Add a new cron job with the following command:

```
* * * * * cd /home/username/public_html/inventoros && php artisan schedule:run >> /dev/null 2>&1
```

Replace `/home/username/public_html/inventoros` with your actual path.

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
- Verify PHP version is 8.2 or higher (check in cPanel > Select PHP Version)

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
3. Choose PHP 8.2 or higher
4. Enable required extensions: `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`

## Performance Tips

- Enable OPcache in PHP settings
- Use Redis for session/cache if available (update `.env` accordingly)
- Enable gzip compression via `.htaccess`
- Consider using Cloudflare for CDN and additional caching
