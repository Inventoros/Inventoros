This guide installs Inventoros from source on a VPS with full root access. Examples cover Ubuntu 22.04+, CentOS / RHEL, and Debian, then the scheduler and queue worker, SSL and firewall setup. Inventoros needs PHP 8.4.1 or newer (8.4 and 8.5) and Node.js 22.12 or newer to build the frontend.

## Ubuntu 22.04+

1. Update system packages:

```bash
sudo apt update && sudo apt upgrade -y
```

2. Install the required packages. Ubuntu's own repositories do not ship PHP 8.4, so add the `ondrej/php` PPA first:

```bash
sudo apt install -y software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt install -y php8.4 php8.4-cli php8.4-fpm php8.4-mysql \
    php8.4-mbstring php8.4-xml php8.4-bcmath php8.4-curl \
    php8.4-zip php8.4-gd php8.4-intl nginx mysql-server \
    git curl unzip
```

3. Install Composer:

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
sudo chmod +x /usr/local/bin/composer
```

4. Install Node.js and npm:

```bash
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
```

5. Set up the MySQL database:

```bash
sudo mysql -e "CREATE DATABASE inventoros;"
sudo mysql -e "CREATE USER 'inventoros'@'localhost' IDENTIFIED BY 'secure_password';"
sudo mysql -e "GRANT ALL PRIVILEGES ON inventoros.* TO 'inventoros'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"
```

6. Clone and set up Inventoros (or download it with Composer instead, see [Install with Composer instead of git](#install-with-composer-instead-of-git)):

```bash
cd /var/www
sudo git clone https://github.com/Inventoros/Inventoros.git inventoros
cd inventoros
sudo composer install --no-dev --optimize-autoloader
sudo npm install && sudo npm run build
sudo cp .env.example .env
sudo php artisan key:generate
```

7. Configure the environment. Edit `.env` and update these values:

```bash
sudo nano .env

# Update these values:
APP_ENV=production
APP_DEBUG=false
APP_URL=http://your-domain.com
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=inventoros
DB_USERNAME=inventoros
DB_PASSWORD=secure_password
```

`.env.example` ships with `DB_CONNECTION=sqlite` and the other `DB_` lines commented out, so set all six (remove the leading `#`). If you finish setup with the web installer (step 10), its database step writes these values for you.

8. Set permissions:

```bash
sudo chown -R www-data:www-data /var/www/inventoros
sudo chmod -R 755 /var/www/inventoros
sudo chmod -R 775 /var/www/inventoros/storage
sudo chmod -R 775 /var/www/inventoros/bootstrap/cache
```

9. Configure Nginx. Create the site configuration:

```nginx
# /etc/nginx/sites-available/inventoros
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/inventoros/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Enable the site:

```bash
sudo ln -s /etc/nginx/sites-available/inventoros /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl restart nginx
```

10. Access the web installer. Visit your domain to complete setup:

```text
http://your-domain.com/install
```

The installer asks for a setup token. Set `INVENTOROS_SETUP_TOKEN` in `.env` beforehand (for example `openssl rand -hex 24`), or read the token the installer generates on its first visit:

```bash
sudo cat /var/www/inventoros/storage/app/private/setup-token
```

The database step writes the connection you enter to `.env` (`DB_CONNECTION`, host, database and credentials) and creates the tables in that database. Finishing the installer (creating the admin account) sets `APP_ENV=production` and `APP_DEBUG=false`, so a site set up with the example `.env` does not stay in debug mode.

## CentOS / RHEL

1. Update the system and enable EPEL and Remi:

```bash
sudo dnf update -y
sudo dnf install -y epel-release
sudo dnf install -y https://rpms.remirepo.net/enterprise/remi-release-$(rpm -E %rhel).rpm
```

2. Install PHP 8.4:

```bash
sudo dnf module reset php
sudo dnf module enable php:remi-8.4
sudo dnf install -y php php-cli php-fpm php-mysqlnd php-mbstring \
    php-xml php-bcmath php-curl php-zip php-gd php-intl php-sodium
```

Remi packages Sodium separately as `php-sodium`; the in-app updater needs it to verify signed releases.

3. Install MySQL and Nginx:

```bash
sudo dnf install -y mysql-server nginx git curl unzip
sudo systemctl enable --now mysqld nginx php-fpm
```

4. Install Composer:

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
sudo chmod +x /usr/local/bin/composer
```

5. Install Node.js and npm:

```bash
curl -fsSL https://rpm.nodesource.com/setup_22.x | sudo bash -
sudo dnf install -y nodejs
```

6. Set up the MySQL database:

```bash
sudo mysql_secure_installation
sudo mysql -e "CREATE DATABASE inventoros;"
sudo mysql -e "CREATE USER 'inventoros'@'localhost' IDENTIFIED BY 'secure_password';"
sudo mysql -e "GRANT ALL PRIVILEGES ON inventoros.* TO 'inventoros'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"
```

7. Clone and set up Inventoros (or download it with Composer instead, see [Install with Composer instead of git](#install-with-composer-instead-of-git)), then edit `.env` as in Ubuntu step 7 (including `APP_ENV=production` and `APP_DEBUG=false`):

```bash
cd /var/www
sudo git clone https://github.com/Inventoros/Inventoros.git inventoros
cd inventoros
sudo composer install --no-dev --optimize-autoloader
sudo npm install && sudo npm run build
sudo cp .env.example .env
sudo php artisan key:generate
```

8. Set permissions and SELinux contexts:

```bash
sudo chown -R nginx:nginx /var/www/inventoros
sudo chmod -R 755 /var/www/inventoros
sudo chmod -R 775 /var/www/inventoros/storage
sudo chmod -R 775 /var/www/inventoros/bootstrap/cache

# SELinux permissions (if enabled)
sudo setsebool -P httpd_can_network_connect 1
sudo chcon -R -t httpd_sys_rw_content_t /var/www/inventoros/storage
sudo chcon -R -t httpd_sys_rw_content_t /var/www/inventoros/bootstrap/cache
```

9. Configure Nginx. Use the same server block as Ubuntu in `/etc/nginx/conf.d/inventoros.conf`, but change the PHP-FPM socket to `fastcgi_pass unix:/run/php-fpm/www.sock;`. PHP-FPM's default pool runs as `apache`, so set `user = nginx` and `group = nginx` in `/etc/php-fpm.d/www.conf` to match the file ownership from step 8. Then:

```bash
sudo nginx -t
sudo systemctl restart php-fpm nginx
```

10. Configure the firewall:

```bash
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --add-service=https
sudo firewall-cmd --reload
```

## Debian

1. Update system packages:

```bash
sudo apt update && sudo apt upgrade -y
```

2. Add the PHP repository (for PHP 8.4):

```bash
sudo apt install -y lsb-release apt-transport-https ca-certificates curl
sudo curl -sSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg
echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" | sudo tee /etc/apt/sources.list.d/php.list
sudo apt update
```

3. Install the required packages:

```bash
sudo apt install -y php8.4 php8.4-cli php8.4-fpm php8.4-mysql \
    php8.4-mbstring php8.4-xml php8.4-bcmath php8.4-curl \
    php8.4-zip php8.4-gd php8.4-intl nginx mariadb-server \
    git curl unzip
```

4. Install Composer:

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
sudo chmod +x /usr/local/bin/composer
```

5. Install Node.js and npm:

```bash
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
```

6. Set up the MariaDB database:

```bash
sudo mysql_secure_installation
sudo mysql -e "CREATE DATABASE inventoros;"
sudo mysql -e "CREATE USER 'inventoros'@'localhost' IDENTIFIED BY 'secure_password';"
sudo mysql -e "GRANT ALL PRIVILEGES ON inventoros.* TO 'inventoros'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"
```

7. The remaining steps are identical to Ubuntu. Clone the repo (or download it with Composer instead, see [Install with Composer instead of git](#install-with-composer-instead-of-git)), install dependencies, build assets, configure `.env`, set permissions, and configure Nginx (Ubuntu steps 6 through 10):

```bash
cd /var/www
sudo git clone https://github.com/Inventoros/Inventoros.git inventoros
cd inventoros
sudo composer install --no-dev --optimize-autoloader
sudo npm install && sudo npm run build
sudo cp .env.example .env
sudo php artisan key:generate
```

## Install with Composer instead of git

Inventoros is also on Packagist, so you can download it with Composer in place of the `git clone` step in any of the guides above:

```bash
cd /var/www
sudo composer create-project inventoros/inventoros inventoros --no-dev
cd inventoros
sudo npm ci && sudo npm run build
```

Composer installs the PHP dependencies, copies `.env.example` to `.env` and generates `APP_KEY`, so carry on with editing `.env` (Ubuntu step 7). Composer picks the latest release. To install a specific one, add its version after the folder name: `composer create-project inventoros/inventoros inventoros <version> --no-dev`. Leave out `--no-dev` only if you want to run the development tools.

Releases up to and including 2.5.0 were published before the Composer package, so `create-project` with one of them also creates and migrates a SQLite database in `database/database.sqlite` and keeps the test suites. The web installer still runs as normal: choose your database there, and delete the SQLite file if you don't use it.

The download has no `.git` folder and leaves out the test suites, so update it from the admin panel like any other VPS install (see [Updates & Backups](updates)), not with `git pull`. Don't run `composer update` to upgrade either: it only moves the PHP libraries to newer versions than the release was tested with, and leaves Inventoros itself on the same version.

## Scheduler and queue worker

Every install needs the Laravel scheduler and a queue worker. The scheduler runs low-stock checks, scheduled cycle counts, scheduled report emails, shipment tracking, log pruning and removal of expired export files. The worker sends webhook deliveries and queued emails (purchase orders, invoices, approvals, shipment notices). Without them these jobs pile up and never run.

The repository ships systemd units in `deploy/systemd/`. Edit `User`, `Group` and `WorkingDirectory` in each file to match your server (`nginx` instead of `www-data` on CentOS / RHEL), then install them:

```bash
sudo cp /var/www/inventoros/deploy/systemd/inventoros-worker.service /etc/systemd/system/
sudo cp /var/www/inventoros/deploy/systemd/inventoros-scheduler.service /etc/systemd/system/
sudo cp /var/www/inventoros/deploy/systemd/inventoros-scheduler.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now inventoros-worker
sudo systemctl enable --now inventoros-scheduler.timer
```

With the worker running, set `QUEUE_RUN_VIA_SCHEDULER=false` in `.env`. Otherwise the scheduler also starts a short-lived queue worker every minute (the fallback for shared hosts that have no worker).

If you prefer cron to the scheduler timer, add this to the web user's crontab (`sudo crontab -u www-data -e`) instead:

```text
* * * * * cd /var/www/inventoros && php artisan schedule:run >> /dev/null 2>&1
```

After each deploy, run `php artisan queue:restart` so the worker picks up the new code.

Imports, exports and scheduled reports can run for up to 600 seconds. Queue
reservations must last longer, or another worker can pick up a job that is still
running. Database, Redis and Beanstalkd queues default to 660 seconds; keep any
`DB_QUEUE_RETRY_AFTER`, `REDIS_QUEUE_RETRY_AFTER` or
`BEANSTALKD_QUEUE_RETRY_AFTER` override above 600. For SQS, set the queue's
visibility timeout above 600 seconds in AWS. After changing these settings,
rebuild the configuration cache and restart workers.

Large exports are written to `EXPORT_DISK` and can be downloaded for
`EXPORT_RETENTION_DAYS` (default 7) after they finish. After that the download
link reports the export as expired, and the nightly `exports:prune` command
deletes the file and its record, at most 1000 per run. Set
`EXPORT_RETENTION_DAYS=0` to keep exports forever.

## SSL certificate

Ubuntu / Debian:

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d your-domain.com
sudo systemctl restart nginx
```

CentOS / RHEL:

```bash
sudo dnf install -y certbot python3-certbot-nginx
sudo certbot --nginx -d your-domain.com
sudo systemctl restart nginx
```

Once the site answers over HTTPS, set `APP_URL=https://your-domain.com` and `SESSION_SECURE_COOKIE=true` in `.env` (the installer leaves it `false` when it ran over plain HTTP), then run `php artisan config:cache` if you cache the configuration.

## Firewall

Ubuntu / Debian (UFW):

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
```

CentOS / RHEL (firewalld):

```bash
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --add-service=https
sudo firewall-cmd --permanent --add-service=ssh
sudo firewall-cmd --reload
```

Report an issue: https://github.com/Inventoros/Inventoros/issues
