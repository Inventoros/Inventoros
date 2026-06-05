This guide installs Inventoros from source on a VPS with full root access. Examples cover Ubuntu 22.04+, CentOS / RHEL, and Debian, then SSL and firewall setup.

### Ubuntu 22.04+

1. Update system packages:

```bash
sudo apt update && sudo apt upgrade -y
```

2. Install the required packages:

```bash
sudo apt install -y php8.2 php8.2-cli php8.2-fpm php8.2-mysql \
    php8.2-mbstring php8.2-xml php8.2-bcmath php8.2-curl \
    php8.2-zip php8.2-gd php8.2-intl nginx mysql-server \
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
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
```

5. Set up the MySQL database:

```bash
sudo mysql -e "CREATE DATABASE inventoros;"
sudo mysql -e "CREATE USER 'inventoros'@'localhost' IDENTIFIED BY 'secure_password';"
sudo mysql -e "GRANT ALL PRIVILEGES ON inventoros.* TO 'inventoros'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"
```

6. Clone and set up Inventoros:

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
APP_URL=http://your-domain.com
DB_DATABASE=inventoros
DB_USERNAME=inventoros
DB_PASSWORD=secure_password
```

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
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
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

### CentOS / RHEL

1. Update the system and enable EPEL and Remi:

```bash
sudo dnf update -y
sudo dnf install -y epel-release
sudo dnf install -y https://rpms.remirepo.net/enterprise/remi-release-8.rpm
```

2. Install PHP 8.2:

```bash
sudo dnf module reset php
sudo dnf module enable php:remi-8.2
sudo dnf install -y php php-cli php-fpm php-mysqlnd php-mbstring \
    php-xml php-bcmath php-curl php-zip php-gd php-intl
```

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
curl -fsSL https://rpm.nodesource.com/setup_20.x | sudo bash -
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

7. Clone and set up Inventoros (same as Ubuntu steps 6 and 7):

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

9. Configure Nginx. Use the same server block as Ubuntu in `/etc/nginx/conf.d/inventoros.conf`, but change the PHP-FPM socket to `fastcgi_pass unix:/run/php-fpm/www.sock;`. Then:

```bash
sudo nginx -t
sudo systemctl restart nginx
```

10. Configure the firewall:

```bash
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --add-service=https
sudo firewall-cmd --reload
```

### Debian

1. Update system packages:

```bash
sudo apt update && sudo apt upgrade -y
```

2. Add the PHP repository (for PHP 8.2):

```bash
sudo apt install -y lsb-release apt-transport-https ca-certificates curl
sudo curl -sSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg
echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" | sudo tee /etc/apt/sources.list.d/php.list
sudo apt update
```

3. Install the required packages:

```bash
sudo apt install -y php8.2 php8.2-cli php8.2-fpm php8.2-mysql \
    php8.2-mbstring php8.2-xml php8.2-bcmath php8.2-curl \
    php8.2-zip php8.2-gd php8.2-intl nginx mariadb-server \
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
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
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

7. The remaining steps are identical to Ubuntu. Clone the repo, install dependencies, build assets, configure `.env`, set permissions, and configure Nginx (Ubuntu steps 6 through 10):

```bash
cd /var/www
sudo git clone https://github.com/Inventoros/Inventoros.git inventoros
cd inventoros
sudo composer install --no-dev --optimize-autoloader
sudo npm install && sudo npm run build
sudo cp .env.example .env
sudo php artisan key:generate
```

### SSL certificate

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

### Firewall

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
