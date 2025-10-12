# Inventoros

> **⚠️ PROJECT IN ACTIVE DEVELOPMENT**
> Inventoros is currently in early development. Features are incomplete, APIs are subject to change, and the system is not yet ready for production use. Contributions and feedback are welcome!

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Laravel 12](https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel)](https://laravel.com)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2+-777BB4?logo=php)](https://php.net)

**Inventory Management for the Rest of Us**

Inventoros is an open-source Inventory and Warehouse Management System (WMS) built with Laravel, Inertia.js, and Vue 3. Designed to bridge the gap between complex enterprise-grade WMS tools and user-friendly systems, Inventoros provides powerful inventory management capabilities with a developer-focused, extensible architecture.

## Vision

Inventoros aims to democratize warehouse and inventory management by providing small and medium businesses with tools typically found only in expensive enterprise software, while offering developers a flexible, plugin-ready foundation for customization.

## Core Features (Planned)

- **Inventory Management**: Complete CRUD operations, stock tracking, SKU management, product categories, suppliers, and locations
- **Warehouse Management**: Order management, fulfillment queues, and location-based tracking
- **CSV Import/Export**: Bootstrap your inventory with bulk data operations
- **Plugin System**: Modular extension layer for third-party add-ons and custom functionality
- **REST + GraphQL API**: Secure, tokenized API endpoints for integration with e-commerce, ERP, and custom systems
- **Multi-User Support**: Role-based permissions, teams, and organization-level data isolation
- **Installer Wizard**: Guided setup flow with database validation and admin creation
- **Update Management**: Version-controlled migrations and UI-driven update flow

## Technology Stack

- **Backend**: Laravel 12 (PHP 8.2+)
- **Frontend**: Inertia.js + Vue 3 + Vite
- **Database**: MySQL / PostgreSQL (abstraction-ready)
- **Queueing & Events**: Redis + Laravel Horizon (planned)
- **Extensions**: Composer-based + JSON manifest layer for plugin registration

## Requirements

- PHP 8.2 or higher
- Composer
- Node.js 18+ and npm
- MySQL 8.0+ or PostgreSQL 13+
- Redis (optional, recommended for production)

## Installation

### Quick Start

```bash
# Clone the repository
git clone https://github.com/yourusername/inventoros.git
cd inventoros

# Install dependencies and set up
composer install
cp .env.example .env
php artisan key:generate

# Configure your database in .env, then migrate
php artisan migrate

# Install frontend dependencies and build
npm install
npm run build

# Serve the application
php artisan serve
```

### Development Setup

For active development with hot-reloading:

```bash
# Run all development services (server, queue, logs, vite)
composer dev

# Or run individually:
php artisan serve       # Application server
npm run dev            # Vite dev server with HMR
php artisan queue:work # Queue worker (if needed)
```

## Configuration

1. **Environment Setup**: Copy `.env.example` to `.env` and configure:
   - Database credentials (`DB_*`)
   - Application URL (`APP_URL`)
   - Mail settings (for notifications)
   - Queue driver (recommend `redis` for production)

2. **Database Migration**: Run `php artisan migrate` to create the database schema

3. **Asset Compilation**: Run `npm run build` for production or `npm run dev` for development

## Development Roadmap

### Phase 1 - MVP Foundation (Current)
- [ ] Base Laravel + Inertia scaffolding
- [ ] Multi-tenant architecture foundation
- [ ] Installer & Update Manager (CLI + UI)
- [ ] Basic Inventory module (CRUD operations)
- [ ] Role-Based Access Control
- [ ] CSV Import/Export foundation

### Phase 2 - Core WMS Features
- [ ] Warehouse location management
- [ ] Order fulfillment workflows
- [ ] Stock movement tracking
- [ ] Supplier management
- [ ] Product categorization and variants

### Phase 3 - Extensions & API
- [ ] Plugin system architecture
- [ ] REST API endpoints
- [ ] GraphQL API layer
- [ ] Basic plugin examples
- [ ] API documentation

### Phase 4 - Advanced Features
- [ ] Barcode/QR code support
- [ ] Reporting and analytics
- [ ] Audit logging
- [ ] Advanced permissions
- [ ] Multi-warehouse support

## Contributing

We welcome contributions! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines on how to get started.

### Development Guidelines

- Follow [PSR-12](https://www.php-fig.org/psr/psr-12/) coding standards
- Write tests for new features
- Use conventional commit messages
- Update documentation for significant changes

## Testing

```bash
# Run all tests
composer test

# Run specific test suites
php artisan test --testsuite=Feature
php artisan test --testsuite=Unit
```

## Code Quality

```bash
# Format code with Laravel Pint
./vendor/bin/pint

# Run static analysis (when configured)
./vendor/bin/phpstan analyse
```

## Documentation

- [Installation Guide](docs/installation.md) (Coming Soon)
- [Plugin Development](docs/plugins.md) (Coming Soon)
- [API Documentation](docs/api.md) (Coming Soon)
- [Architecture Overview](docs/architecture.md) (Coming Soon)

## Community & Support

- **Issues**: [GitHub Issues](https://github.com/yourusername/inventoros/issues)
- **Discussions**: [GitHub Discussions](https://github.com/yourusername/inventoros/discussions)
- **Security**: See [SECURITY.md](SECURITY.md) for reporting vulnerabilities

## License

Inventoros is open-source software licensed under the [MIT license](LICENSE).

## Acknowledgments

Built with:
- [Laravel](https://laravel.com) - The PHP Framework for Web Artisans
- [Inertia.js](https://inertiajs.com) - The Modern Monolith
- [Vue.js](https://vuejs.org) - The Progressive JavaScript Framework
- [Tailwind CSS](https://tailwindcss.com) - A utility-first CSS framework

---

**Note**: This is the open-source core of Inventoros. The hosted SaaS platform and marketplace (Inventoros Live) are maintained separately and are not part of this repository.
