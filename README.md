# Inventoros

> **⚠️ PROJECT IN ACTIVE DEVELOPMENT / BETA**
> Inventoros is currently in early development. Contributions and feedback are welcome!

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Laravel 12](https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel)](https://laravel.com)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2+-777BB4?logo=php)](https://php.net)
[![Code of Conduct](https://img.shields.io/badge/Contributor%20Covenant-2.1-4baaaa.svg)](CODE_OF_CONDUCT.md)

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
git clone https://github.com/inventoros/inventoros.git
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

### Phase 1 - MVP Foundation ✅ (v1.0 Beta)
- [x] Base Laravel + Inertia scaffolding
- [x] Multi-tenant architecture foundation
- [x] Installer & Update Manager (CLI + UI)
- [x] Basic Inventory module (CRUD operations)
- [x] Role-Based Access Control (Permission-based system)
- [x] Product categorization and locations
- [x] Multi-currency support
- [x] CSV Import/Export foundation
- [x] Backup system
- [x] Unit and feature tests

### Phase 2 - Core WMS Features ✅ (v1.0 Beta)
- [x] Warehouse location management
- [x] Order management (CRUD with inventory tracking)
- [x] Stock movement tracking (automatic on orders)
- [x] Product categorization system
- [x] Supplier management
- [x] Purchase order management
- [x] Product variants with options (size, color, material)
- [ ] Advanced stock movement workflows

### Phase 3 - Extensions & API ✅ (v1.0 Beta)
- [x] Plugin system architecture (WordPress-style hooks)
- [x] Database plugin activation system
- [x] Plugin slot system with multiple hooks
- [x] Sample plugin with documentation
- [x] REST API endpoints (Products, Categories, Locations, Orders, Stock Adjustments, Suppliers, Purchase Orders, Barcode Lookup)
- [ ] GraphQL API layer
- [ ] API documentation

### Phase 4 - Advanced Features ✅ (v1.0 Beta)
- [x] Barcode/QR code generation
- [x] Barcode scanning integration (Products, Stock Adjustments, Purchase Orders)
- [x] Reporting and analytics dashboard (Inventory Valuation, Stock Movement, Sales Analysis, Low Stock, Category Performance)
- [x] Audit logging (Activity Log with filtering)
- [x] Email notification system (multi-provider, templates, user preferences)
- [x] Webhook system (event subscriptions, HMAC signing, retry logic, delivery logs)
- [ ] Advanced permissions (custom permission sets)
- [ ] Multi-warehouse support

## Current Features (v1.0 Beta)

### ✅ Completed

**Inventory & Product Management**
- **Product Management**: Complete inventory CRUD with SKU, pricing, stock levels, categories, locations, and barcodes
- **Product Variants**: Support for product variants with options (size, color, material) and variant-specific SKUs, pricing, and stock
- **Multi-Currency Support**: Product pricing in multiple currencies with conversion support
- **Categories & Locations**: Organize products by category and track warehouse locations
- **Barcode Generation**: Automatic barcode generation (UPC/EAN/Code128) with bulk printing
- **Barcode Scanning**: Camera-based barcode scanning in Products, Stock Adjustments, and Purchase Orders
- **CSV Import/Export**: Bulk import and export of product data

**Order & Warehouse Management**
- **Order Management**: Full order lifecycle (create, view, edit, delete) with automatic inventory adjustments
- **Stock Movement Tracking**: Automatic tracking of inventory changes through orders
- **Warehouse Locations**: Location-based inventory tracking
- **Supplier Management**: Complete supplier CRUD with product associations
- **Purchase Orders**: Full purchase order lifecycle with item receiving workflow

**User & Access Management**
- **User Management**: Full CRUD with role assignment and permission-based access control
- **Role Management**: Custom roles with granular permissions, system roles (Administrator, Manager, Member)
- **Permission System**: Granular permission checks at route and UI levels
- **Custom Error Pages**: Beautiful 403 access denied pages with light/dark mode

**System & Extensions**
- **Plugin System**: WordPress-style hooks and filters with database activation
- **Plugin Slots**: Extensible UI component system with multiple hook points
- **Sample Plugin**: Comprehensive example plugin with documentation
- **REST API**: Complete API for Products, Categories, Locations, Orders, Stock Adjustments, Suppliers, Purchase Orders, and Barcode Lookup
- **Installer Wizard**: Guided setup with database validation and admin account creation
- **Update Manager**: Version-controlled updates with automatic backup system
- **Backup System**: Automatic backups before system updates
- **Notification System**: Header dropdown with notification management
- **Activity Logging**: Complete audit log with filtering by user, action, date, and subject type

**Notifications & Integrations**
- **Email Notifications**: Multi-provider support (SMTP, Mailgun, SendGrid), customizable templates, per-user preferences
- **Webhook System**: Event subscriptions, HMAC-SHA256 signing, exponential backoff retry, delivery logs with response tracking

**Reporting & Analytics**
- **Dashboard Analytics**: Overview with stats, recent activity, stock movements, and top products
- **Inventory Valuation Report**: Stock value by product and category
- **Stock Movement Report**: Track all inventory adjustments with filtering
- **Sales Analysis Report**: Revenue, top products, and daily sales trends
- **Low Stock Report**: Identify products below minimum stock levels
- **Category Performance Report**: Analyze stock and value by category

**User Experience**
- **Dark Mode**: Full application support with persistent theme preferences
- **Responsive Design**: Mobile-friendly UI throughout the application
- **Modern UI**: Built with Vue 3, Inertia.js, and Tailwind CSS
- **Proper Sidebar Navigation**: Intuitive navigation structure

**Testing & Quality**
- **Unit Tests**: Comprehensive unit test coverage
- **Feature Tests**: Integration tests for critical workflows
- **Code Quality**: PSR-12 compliant with Laravel Pint formatting

### 🔜 Coming Next
- GraphQL API layer
- API documentation (OpenAPI/Swagger)
- Stock transfers between locations
- Advanced inventory workflows
- Multi-warehouse support
- Custom report builder
- Advanced permissions (custom permission sets)

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

- [cPanel Deployment Guide](CPANEL.md) - Deploy Inventoros on shared hosting with cPanel
- [Email Notifications](docs/features/email-notifications.md) - Configuration and usage guide
- [Barcode Scanning](docs/features/barcode-scanning.md) - Camera-based barcode scanning integration
- [Installation Guide](docs/installation.md) (Coming Soon)
- [Plugin Development](docs/plugins.md) (Coming Soon)
- [API Documentation](docs/api.md) (Coming Soon)
- [Architecture Overview](docs/architecture.md) (Coming Soon)

## Community & Support

- **Issues**: [GitHub Issues](https://github.com/inventoros/inventoros/issues)
- **Discussions**: [GitHub Discussions](https://github.com/inventoros/inventoros/discussions)
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
