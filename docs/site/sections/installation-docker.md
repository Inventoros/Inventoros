Official Docker images and Docker Compose configurations for Inventoros are in progress. Until they land, advanced users can build their own setup with the templates below.

Watch the GitHub repository to get notified when official images are available: https://github.com/Inventoros/Inventoros

### Prerequisites

- Docker 24.0 or higher installed
- Docker Compose v2.0 or higher
- At least 2GB RAM and 10GB disk space

### What is planned

- Official Docker Hub image (pre-built images ready to pull)
- Docker Compose template for one-command deployment
- Multi-container setup (app, MySQL, Redis, Nginx)
- Volume management for persistent data storage
- Environment configuration via environment variables
- Auto-updates through Watchtower integration

### Do-it-yourself setup

For advanced users who want to create their own Docker setup, here is a basic Dockerfile template.

Sample Dockerfile:

```dockerfile
FROM php:8.2-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git curl libpng-dev libonig-dev libxml2-dev zip unzip \
    nginx supervisor

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install Node.js
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
RUN apt-get install -y nodejs

# Set working directory
WORKDIR /var/www

# Copy application files
COPY . /var/www

# Install dependencies
RUN composer install --no-dev --optimize-autoloader
RUN npm install && npm run build

# Set permissions
RUN chown -R www-data:www-data /var/www

EXPOSE 9000
CMD ["php-fpm"]
```

Sample docker-compose.yml:

```yaml
services:
  app:
    build: .
    volumes:
      - ./storage:/var/www/storage
    environment:
      - DB_HOST=mysql
      - DB_DATABASE=inventoros
      - DB_USERNAME=inventoros
      - DB_PASSWORD=secret

  mysql:
    image: mysql:8.0
    environment:
      - MYSQL_DATABASE=inventoros
      - MYSQL_USER=inventoros
      - MYSQL_PASSWORD=secret
      - MYSQL_ROOT_PASSWORD=root
    volumes:
      - mysql_data:/var/lib/mysql

  nginx:
    image: nginx:alpine
    ports:
      - "80:80"
    volumes:
      - ./public:/var/www/public
      - ./nginx.conf:/etc/nginx/conf.d/default.conf
    depends_on:
      - app

volumes:
  mysql_data:
```

After the containers are up, run the usual setup commands inside the app container (`php artisan key:generate`, `php artisan migrate`) or complete setup through the web installer at `/install`.

Report an issue: https://github.com/Inventoros/Inventoros/issues
