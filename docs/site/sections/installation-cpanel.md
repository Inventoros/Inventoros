Inventoros runs on cPanel shared hosting using a release package built specifically for the split directory layout cPanel expects.

### What the cPanel release includes

The cPanel release packages are specially built and include:

- Pre-compiled frontend assets (no npm required on the server)
- Production-optimized dependencies
- A cPanel-compatible directory structure
- A modified `index.php` for the split directory setup

### Prerequisites

- Access to your cPanel control panel
- PHP 8.2 or higher enabled for your domain
- A MySQL database available
- SSH access (recommended) or the cPanel Terminal
- At least 512MB of disk space

### Step 1: Download the cPanel release

Download the latest cPanel-specific release package from GitHub. Look for the file named `inventoros-cpanel-X.X.X.zip`.

Latest release: https://github.com/Inventoros/Inventoros/releases/latest

The package contains:

```text
inventoros-cpanel-X.X.X/
  inventoros/      # Laravel application files
  public_html/     # Web-accessible files
  INSTALL.md       # Quick reference
```

### Step 2: Create the database

In cPanel, open MySQL Databases:

1. Create a new database (for example `username_inventoros`).
2. Create a database user with a strong password.
3. Add the user to the database with ALL PRIVILEGES.
4. Note down the database name, username, and password.

Example credentials to save:

```text
Database: cpaneluser_inventoros
Username: cpaneluser_invuser
Host:     localhost
Password: [your secure password]
```

### Step 3: Upload the files

Extract the ZIP and upload using cPanel File Manager or FTP:

1. Upload the `inventoros` folder to your home directory. Result: `/home/username/inventoros/`
2. Upload the contents of `public_html` to your web root. Result: `/home/username/public_html/`

Final directory structure:

```text
/home/username/
  inventoros/
    app/
    bootstrap/
    config/
    vendor/
    ...
  public_html/
    index.php
    build/
    .htaccess
```

### Step 4: Set permissions

Using SSH or the cPanel Terminal, run:

```bash
# Make Laravel files readable
chmod -R 755 ~/inventoros

# Make storage and cache writable
chmod -R 775 ~/inventoros/storage
chmod -R 775 ~/inventoros/bootstrap/cache
```

You can also use cPanel File Manager to set permissions on these folders.

### Step 5: Configure the environment

Set up your environment file:

```bash
cd ~/inventoros
cp .env.example .env
```

Edit `.env` with your settings:

```bash
APP_NAME=Inventoros
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=cpaneluser_inventoros
DB_USERNAME=cpaneluser_invuser
DB_PASSWORD=your_password
```

### Step 6: Generate the key, migrate, and cache

Using SSH or the cPanel Terminal, run:

```bash
cd ~/inventoros

# Generate the application key
php artisan key:generate

# Run database migrations
php artisan migrate

# Create the storage symlink (the standard storage:link does not work with
# the split directory layout, so link manually)
ln -s ~/inventoros/storage/app/public ~/public_html/storage

# Optimize for production
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Step 7: Enable HTTPS

Secure your installation:

1. In cPanel, go to SSL/TLS Status or Let's Encrypt.
2. Install a free SSL certificate for your domain.
3. Ensure `APP_URL` in `.env` uses `https://`.

### Installing on a subdomain or addon domain

Installing on a subdomain or addon domain requires adjusting the paths.

For subdomains, if your subdomain points to `~/inventory_public`:

1. Upload the `inventoros` folder to `~/inventoros/`.
2. Upload the `public_html` contents to `~/inventory_public/`.
3. Update the `index.php` path if needed: `$laravelPath = __DIR__ . '/../inventoros';`

For addon domains, if your addon domain points to `~/yourdomain.com`:

1. Upload the `inventoros` folder to `~/inventoros_yourdomain/`.
2. Upload the `public_html` contents to `~/yourdomain.com/`.
3. Update `index.php`: `$laravelPath = __DIR__ . '/../inventoros_yourdomain';`

### Troubleshooting

- 500 Internal Server Error. Check storage and bootstrap/cache permissions, verify `.env` exists, and check `~/inventoros/storage/logs/laravel.log` for errors.
- Assets not loading (CSS / JS broken). Ensure the `build/` folder was uploaded to `public_html` and `.htaccess` is present. Check that `APP_URL` matches your domain.
- Database connection failed. Verify the credentials in `.env`. Test the same credentials in phpMyAdmin. Ensure the database user has privileges.
- PHP version issues. In cPanel, open MultiPHP Manager or Select PHP Version and ensure PHP 8.2+ is selected for your domain.
- Storage link issues. If uploaded files are not accessible, verify the symlink with `ls -la ~/public_html/storage` and recreate it if needed.

Report an issue: https://github.com/Inventoros/Inventoros/issues
