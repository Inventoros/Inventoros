# Features

## Features

### Inventory & Product Management
- Full product CRUD with SKU, pricing, stock levels, categories, locations, and barcodes
- **Product variants** with options (size, color, material) and variant-specific SKUs, pricing, and stock
- **Kitting & bundling** -- create virtual kit products with auto-calculated stock from components
- **Assembly & work orders** -- production workflow that consumes components and produces finished goods
- **Batch tracking** with lot numbers, expiry dates, and manufacturing dates
- **Serial number tracking** with individual status management
- Barcodes in Code 128, EAN-13, UPC-A, EAN-8 and Code 39, QR codes for products and locations, bulk printing, and keyboard-wedge or camera scanning
- Configurable SKU patterns with auto-generation
- CSV import/export for products, orders and users, with row-by-row results
- Scheduled cycle counts and stock audits

### Multi-Warehouse Management
- **Multiple warehouses** with addresses, contacts, and per-warehouse settings
- **Warehouse-level user access control** -- restrict staff to specific warehouses
- **Global warehouse switcher** in the header to filter all views
- **Inter-warehouse stock transfers** with shipping method, tracking number, and transit status
- Default warehouse per organization with manual override on orders
- Per-warehouse reorder points, warehouse and location capacity, and fulfilment priority
- Locations nested within warehouses (Warehouse > Aisle > Shelf > Bin)

### Order & Supply Chain
- Full order lifecycle with automatic inventory adjustments and row locking
- Order approval workflow (pending, approved, rejected)
- **Line and order discounts, payments and refunds**, order payment status, and an accounts-receivable aging report
- **Invoices** with per-organization numbering, emailed to the customer as a PDF
- **Returns & exchanges (RMA)** -- full return lifecycle with automatic stock restoration
- Purchase orders emailed to suppliers with the PDF, item receiving, supplier price history and ratings
- Choose a receiving bin per delivery line, with warehouse access checks and planned destinations preserved ([guide](features/purchase-order-receiving.md))
- Customer management with order history
- Automated reorder points with supplier-grouped PO generation

### Shipping & Fulfilment
- Printable order pick lists and price-free, shipment-specific packing slips, including partial and manual shipments ([guide](features/fulfillment-documents.md))
- **Shipments** per order, including partial shipments; orders move to shipped and delivered as they go
- **EasyPost** rates, label purchase, tracking and voids, or manual shipments with your own tracking
- Tracking updates by webhook with a polling backstop, and an optional shipment email to the customer

### Customer Portal
- A B2B portal per organization at `/portal/{org-slug}` for invited customer contacts
- Customers see their orders, shipments, payments, balance and invoice PDFs, and request returns

### Approval Workflows
- Optional approval for purchase orders, manual stock adjustments and stock transfers, with thresholds
- A Pending approvals page with a sidebar count; all workflows are off by default

### Reports & Analytics
- Valuation, stock movement, sales analysis, low stock, dead stock, inventory turnover, profit margin, sales by location and ABC analysis
- **Custom report builder** over 6 data sources with columns, filters, sorting and shared templates
- Export any report to CSV, Excel or PDF, and email saved reports on a daily, weekly or monthly schedule

### User & Access Management
- Role-based access control with 30+ granular permissions
- Custom roles with reusable permission set templates
- Two-factor authentication (TOTP with setup wizard, QR code, and recovery codes)
- API token management (create, list, revoke)

### REST & GraphQL APIs
- **REST API v1** covering products, orders, payments, shipments, approvals, customers, returns, transfers, users and more
- **GraphQL API** with queries and mutations, permission checks, and query depth/complexity limits
- **MCP server** at `/mcp` so AI clients can read and act on inventory with a user's token ([docs/mcp/README.md](mcp/README.md))
- OpenAPI spec in [docs/api/openapi.yaml](api/openapi.yaml), also served by a running install at `/docs/api`
- Sanctum token authentication with scoped abilities

### Plugin System
- WordPress-style hooks and filters
- Runtime plugin UI: pages, dashboard widgets, menu items and tabs, loaded without rebuilding the app
- **Plugin marketplace**: install and update signed plugins from inventoros.com
- Sample plugin with documentation

### Notifications & Integrations
- Email notifications with multi-provider support (SMTP, Mailgun, SendGrid)
- Customizable templates with per-user preferences
- Webhook system with HMAC-SHA256 signing, exponential backoff retry, and delivery logs

### System & DevOps
- Multi-tenant architecture with organization-based data isolation
- Installer wizard with database validation and admin creation
- Update manager with automatic backups (UI + CLI)
- Activity logging with filtering and export
- Dark mode with persistent preferences
- Global search command palette (Ctrl+K / Cmd+K)
- Automated screenshot generation via GitHub Actions
- 1000+ tests with comprehensive coverage
