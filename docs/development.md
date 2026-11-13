# Installing and developing Inventoros

## Technology Stack

| Layer | Technology |
|-------|-----------|
| Backend | Laravel 13 (PHP 8.4) |
| Frontend | Inertia.js v3 + Vue 3 + Tailwind CSS |
| Build | Vite 7 |
| Database | SQLite (dev) / MySQL 8+ / PostgreSQL 13+ |
| API | REST + GraphQL (Sanctum auth) |
| Testing | PHPUnit + Playwright |
| Architecture | Multi-tenant, plugin-ready |

## Requirements

- PHP 8.4.1 or newer (8.4 and 8.5)
- Composer 2.x
- Node.js 20.19+ or 22.12+ and npm (Vite 7)
- Safari 16.4+, Chrome/Edge 111+, or Firefox 128+ for the web interface ([Tailwind CSS 4 browser requirements](https://tailwindcss.com/docs/compatibility))
- MySQL 8.0+ or PostgreSQL 13+ (SQLite for development)
- Redis (optional, recommended for production queues)

## Installation

On a Linux server, install with one command (it sets up Docker, generates secrets and starts everything):

```bash
curl -fsSL https://inventoros.com/install.sh -o install.sh
less install.sh
sudo bash install.sh
```

Docker Compose, Coolify, cloud servers, cPanel and manual installs are covered in the [installation guides](https://inventoros.com/docs/getting-started). The steps below are for development.

> **Upgrading from 1.0.x?** The in-app updater in 1.0.x cannot install 2.0.0 (or any release), so upgrade by hand once by following [UPGRADE.md](../UPGRADE.md). From 2.0.0 on, the updater works again.

### Quick Start

```bash
git clone https://github.com/Inventoros/Inventoros.git
cd Inventoros

composer install
cp .env.example .env
php artisan key:generate

php artisan migrate
npm install && npm run build

php artisan serve
```

### Development Setup

```bash
# Run all dev services (server, queue, logs, vite) concurrently
composer dev

# Or run individually
php artisan serve       # Application server
npm run dev             # Vite dev server with HMR
php artisan queue:work  # Queue worker
```

## Configuration

1. Copy `.env.example` to `.env` and configure:
   - Database credentials (`DB_*`)
   - Application URL (`APP_URL`)
   - Mail settings (for notifications)
   - Queue driver (recommend `redis` for production)

2. Run `php artisan migrate` to create the database schema

3. Run `npm run build` for production assets

4. Run the scheduler (`* * * * * php artisan schedule:run` in cron); it is required. It also processes the queue every minute, which is enough on shared hosting; on a VPS run a worker (`php artisan queue:work`) as well. Scheduled reports, cycle counts, shipment tracking, emails and webhooks depend on them. See the [cPanel](site/sections/installation-cpanel.md) and [VPS](site/sections/installation-vps.md) guides.

## Testing

```bash
# Run all tests
composer test

# Run specific suites
php artisan test --testsuite=Feature
php artisan test --testsuite=Unit

# Run E2E tests
npm run test:e2e
```

## CLI Commands

```bash
php artisan app:update --check        # Check for updates
php artisan app:update                # Perform update with backup
php artisan app:update --backup       # Create manual backup
php artisan app:update --list-backups # List backups
php artisan app:update --restore=file # Restore from backup
```
