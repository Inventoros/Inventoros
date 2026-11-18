Inventoros is on [Packagist](https://packagist.org/packages/inventoros/inventoros) as `inventoros/inventoros`, so if you already run PHP you can install it with Composer like any other Laravel application. It suits a server you manage yourself, or trying Inventoros on your own computer.

If you'd rather not look after PHP, a web server and Node.js yourself, the [one-command install](installation-linux) sets everything up with Docker instead.

## Before you start

You need:

- PHP 8.4.1 or newer (8.4 and 8.5), with the extensions listed under [Required PHP extensions](getting-started#required-php-extensions)
- Composer 2
- Node.js 22.12 or newer and npm, to build the frontend
- A MySQL 8.0+ or PostgreSQL 13+ database, which the web installer connects to

Composer stops before installing anything if the PHP version, or an extension one of its packages needs, is missing. The web installer then checks the rest of the server before it sets anything up.

## Download and build

```bash
composer create-project inventoros/inventoros inventoros --no-dev
cd inventoros
npm ci
npm run build
```

`create-project` downloads the latest release into the `inventoros` folder, installs its PHP dependencies, copies `.env.example` to `.env` and generates `APP_KEY`, then prints the remaining steps. `npm run build` compiles the frontend into `public/build`.

Leave out `--no-dev` only if you want the development tools (the test runner, code style and debugging helpers). To install a specific release rather than the latest, add its version after the folder name:

```bash
composer create-project inventoros/inventoros inventoros <version> --no-dev
```

## Try it on your own computer

To look around before setting up a server, start PHP's built-in web server from the `inventoros` folder:

```bash
php artisan serve
```

Then open `http://127.0.0.1:8000/install` and follow the [web installer](getting-started#every-method-ends-in-the-web-installer). It asks for a setup token, which it writes to `storage/app/private/setup-token` the first time you open `/install`, and for a MySQL or PostgreSQL database to create the tables in.

## Set up a server

On a server, download Inventoros into the folder your web server will serve it from (for example `/var/www/inventoros`), then:

1. Point the web server's document root at the `public` folder. [Install on a VPS](installation-vps) has a complete Nginx and PHP-FPM configuration for Ubuntu, Debian and CentOS / RHEL.
2. Let the web server's user write to `storage` and `bootstrap/cache`:

```bash
sudo chown -R www-data:www-data /var/www/inventoros
sudo chmod -R 775 /var/www/inventoros/storage /var/www/inventoros/bootstrap/cache
```

3. Set `APP_URL` in `.env` to the address people will use, for example `https://inventory.example.com`.
4. Open `/install` on that address and complete the web installer. For the setup token, either set `INVENTOROS_SETUP_TOKEN` in `.env` beforehand (for example `openssl rand -hex 24`) or read the one it writes to `storage/app/private/setup-token`. The database step writes the connection to `.env` and creates the tables.
5. Start the queue worker and the scheduler. Inventoros needs both for low-stock checks, scheduled reports, emails and webhooks. The systemd units are in `deploy/systemd/`, and [Scheduler and queue worker](installation-vps#scheduler-and-queue-worker) shows how to install them.

For HTTPS and the firewall, see [SSL certificate](installation-vps#ssl-certificate) and [Firewall](installation-vps#firewall).

## Updating

Update from the admin panel (Settings, then Updates), the same as every installation that isn't on Docker. It downloads the signed release, takes a backup and runs the migrations. See [Updates & Backups](updates).

Don't use Composer for upgrades:

- `composer update` does not upgrade Inventoros. It only moves the PHP libraries to newer versions than the release was tested with.
- `composer create-project` always makes a new installation, so running it again doesn't update the one you have.

The download has no `.git` folder, so `git pull` doesn't apply either.

## Releases up to 2.5.0

Releases up to and including 2.5.0 were published before the Composer package. Installing one of them with `create-project` also creates and migrates a SQLite database in `database/database.sqlite`, and keeps the test suites. The web installer still runs as normal: choose your database there, and delete the SQLite file if you don't use it.
