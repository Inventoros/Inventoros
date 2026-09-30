// The permissions each page (named GET route) requires, as its
// `permission:` middleware declares them: every entry must pass; an entry
// "a|b" passes with any of them, "a|b,all" needs all of them.
//
// Links and buttons use canVisit() to hide what would only lead to a 403.
// tests/Feature/RoutePermissionsMapTest.php fails when this drifts from
// the routes; regenerate the object from `php artisan route:list --json`.

export const ROUTE_PERMISSIONS = {
    "activity-log.export": [
        "view_activity_log"
    ],
    "activity-log.index": [
        "view_activity_log"
    ],
    "admin.update.backups.list": [
        "manage_organization"
    ],
    "admin.update.check": [
        "manage_organization"
    ],
    "admin.update.index": [
        "manage_organization"
    ],
    "barcode.lookup": [
        "view_products"
    ],
    "categories.index": [
        "manage_categories"
    ],
    "customers.create": [
        "create_customers"
    ],
    "customers.edit": [
        "edit_customers"
    ],
    "customers.index": [
        "view_customers"
    ],
    "customers.show": [
        "view_customers"
    ],
    "cycle-counts.create": [
        "manage_stock_audits"
    ],
    "cycle-counts.edit": [
        "manage_stock_audits"
    ],
    "cycle-counts.index": [
        "view_stock_audits"
    ],
    "import-export.download": [
        "export_data"
    ],
    "import-export.download-order-template": [
        "import_data",
        "create_orders"
    ],
    "import-export.download-template": [
        "import_data"
    ],
    "import-export.download-user-template": [
        "import_data",
        "create_users"
    ],
    "import-export.export-orders": [
        "export_data"
    ],
    "import-export.export-products": [
        "export_data"
    ],
    "import-export.export-users": [
        "export_data",
        "view_users"
    ],
    "import-export.index": [
        "export_data|import_data"
    ],
    "locations.index": [
        "manage_locations"
    ],
    "locations.qr.bulk-print": [
        "manage_locations"
    ],
    "locations.qr.print": [
        "manage_locations"
    ],
    "orders.create": [
        "create_orders"
    ],
    "orders.customer-lookup": [
        "view_orders"
    ],
    "orders.edit": [
        "edit_orders"
    ],
    "orders.index": [
        "view_orders"
    ],
    "orders.invoice.download": [
        "view_orders"
    ],
    "orders.invoice.preview": [
        "view_orders"
    ],
    "orders.show": [
        "view_orders"
    ],
    "plugins.index": [
        "view_plugins"
    ],
    "plugins.marketplace": [
        "view_plugins"
    ],
    "products.barcode.bulk-print": [
        "view_products"
    ],
    "products.barcode.generate": [
        "view_products"
    ],
    "products.barcode.print": [
        "view_products"
    ],
    "products.components.index": [
        "edit_products"
    ],
    "products.create": [
        "create_products"
    ],
    "products.edit": [
        "edit_products"
    ],
    "products.index": [
        "view_products"
    ],
    "products.qr.bulk-print": [
        "view_products"
    ],
    "products.qr.generate": [
        "view_products"
    ],
    "products.qr.print": [
        "view_products"
    ],
    "products.show": [
        "view_products"
    ],
    "purchase-orders.create": [
        "create_purchase_orders"
    ],
    "purchase-orders.edit": [
        "edit_purchase_orders"
    ],
    "purchase-orders.index": [
        "view_purchase_orders"
    ],
    "purchase-orders.invoice.download": [
        "view_purchase_orders"
    ],
    "purchase-orders.invoice.preview": [
        "view_purchase_orders"
    ],
    "purchase-orders.receive": [
        "receive_purchase_orders"
    ],
    "purchase-orders.show": [
        "view_purchase_orders"
    ],
    "reports.abc-analysis": [
        "view_reports",
        "view_orders"
    ],
    "reports.builder.create": [
        "view_reports"
    ],
    "reports.builder.edit": [
        "view_reports"
    ],
    "reports.builder.export": [
        "view_reports"
    ],
    "reports.builder.index": [
        "view_reports"
    ],
    "reports.builder.show": [
        "view_reports"
    ],
    "reports.category-performance": [
        "view_reports"
    ],
    "reports.dead-stock": [
        "view_reports",
        "view_products|view_orders,all"
    ],
    "reports.index": [
        "view_reports"
    ],
    "reports.inventory-turnover": [
        "view_reports",
        "view_products|view_orders,all"
    ],
    "reports.inventory-valuation": [
        "view_reports"
    ],
    "reports.low-stock": [
        "view_reports"
    ],
    "reports.profit-margin": [
        "view_reports",
        "view_products|view_orders,all"
    ],
    "reports.receivables": [
        "view_reports",
        "view_payments"
    ],
    "reports.sales-analysis": [
        "view_reports"
    ],
    "reports.sales-by-location": [
        "view_reports",
        "view_orders"
    ],
    "reports.stock-movement": [
        "view_reports"
    ],
    "returns.create": [
        "manage_returns"
    ],
    "returns.index": [
        "manage_returns"
    ],
    "returns.show": [
        "manage_returns"
    ],
    "roles.create": [
        "create_roles"
    ],
    "roles.edit": [
        "edit_roles"
    ],
    "roles.index": [
        "view_roles"
    ],
    "roles.show": [
        "view_roles"
    ],
    "settings.email.index": [
        "manage_organization"
    ],
    "settings.organization.index": [
        "view_settings"
    ],
    "settings.shipping.index": [
        "manage_organization"
    ],
    "shipments.label": [
        "view_shipments"
    ],
    "sku.patterns": [
        "view_products"
    ],
    "stock-adjustments.create": [
        "manage_stock"
    ],
    "stock-adjustments.index": [
        "manage_stock"
    ],
    "stock-adjustments.show": [
        "manage_stock"
    ],
    "stock-audits.create": [
        "create_stock_audits"
    ],
    "stock-audits.edit": [
        "manage_stock_audits"
    ],
    "stock-audits.index": [
        "view_stock_audits"
    ],
    "stock-audits.show": [
        "view_stock_audits"
    ],
    "stock-transfers.create": [
        "transfer_stock"
    ],
    "stock-transfers.index": [
        "transfer_stock"
    ],
    "stock-transfers.show": [
        "transfer_stock"
    ],
    "suppliers.create": [
        "create_suppliers"
    ],
    "suppliers.edit": [
        "edit_suppliers"
    ],
    "suppliers.index": [
        "view_suppliers"
    ],
    "suppliers.show": [
        "view_suppliers"
    ],
    "users.create": [
        "create_users"
    ],
    "users.edit": [
        "edit_users"
    ],
    "users.index": [
        "view_users"
    ],
    "users.show": [
        "view_users"
    ],
    "warehouses.create": [
        "create_warehouses"
    ],
    "warehouses.edit": [
        "edit_warehouses|manage_warehouse_users"
    ],
    "warehouses.index": [
        "view_warehouses"
    ],
    "warehouses.show": [
        "view_warehouses"
    ],
    "webhooks.index": [
        "manage_organization"
    ],
    "webhooks.show": [
        "manage_organization"
    ],
    "work-orders.create": [
        "manage_stock"
    ],
    "work-orders.index": [
        "manage_stock"
    ],
    "work-orders.show": [
        "manage_stock"
    ]
};

export function canVisit(name, permissions = []) {
    const specs = ROUTE_PERMISSIONS[name];
    if (!specs) return true;

    const held = new Set(permissions);

    return specs.every((spec) => {
        const [list, guard] = spec.split(',');
        const names = list.split('|');
        return guard === 'all' ? names.every((n) => held.has(n)) : names.some((n) => held.has(n));
    });
}
