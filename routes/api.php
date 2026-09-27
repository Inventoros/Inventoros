<?php

use App\Http\Controllers\Api\ApprovalController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BarcodeLookupController;
use App\Http\Controllers\Api\BatchTrackingController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PermissionSetController;
use App\Http\Controllers\Api\ProductCategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductLocationController;
use App\Http\Controllers\Api\ProductOptionController;
use App\Http\Controllers\Api\ProductVariantController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\ReturnOrderController;
use App\Http\Controllers\Api\SerialTrackingController;
use App\Http\Controllers\Api\StockAdjustmentController;
use App\Http\Controllers\Api\StockAuditController as ApiStockAuditController;
use App\Http\Controllers\Api\StockTransferController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group.
|
*/

/*
|--------------------------------------------------------------------------
| GraphQL API
|--------------------------------------------------------------------------
|
| The GraphQL endpoint is available at /graphql and is handled by the
| rebing/graphql-laravel package. Authentication is enforced via Sanctum
| middleware configured in config/graphql.php on the default schema.
|
| Endpoint: POST /graphql
| Auth: Bearer token (Sanctum)
|
*/

/*
| Authorization note:
| Write verbs (store/update/destroy) are gated by the per-verb
| create_/edit_/delete_ permission, while read verbs (index/show) use
| view_. The previous single `view_X|manage_X` gate on the whole
| apiResource let a read-only user perform writes: `manage_products`,
| `manage_orders`, `manage_suppliers`, `manage_purchase_orders`,
| `manage_roles` and `manage_warehouses` are NOT real permissions
| (see App\Enums\Permission), so the OR collapsed to the read
| permission alone. Nested product sub-resources (options, variants,
| batches, serials, components) are product edits, so their writes use
| edit_products; a variant stock adjustment uses manage_stock.
*/

// API Version 1
Route::prefix('v1')->as('api.')->middleware('throttle:api')->group(function () {
    // Public routes (rate limited)
    Route::middleware('throttle:5,1')->group(function () {
        Route::post('/login', [AuthController::class, 'login']);
    });

    // Protected routes
    Route::middleware(['auth:sanctum'])->group(function () {
        // Auth
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/user', [AuthController::class, 'user']);
        Route::post('/tokens', [AuthController::class, 'createToken']);
        Route::delete('/tokens/{tokenId}', [AuthController::class, 'revokeToken']);

        // Products
        Route::apiResource('products', ProductController::class)->only(['index', 'show'])
            ->middleware('api.permission:view_products');
        Route::apiResource('products', ProductController::class)->only(['store'])
            ->middleware('api.permission:create_products');
        Route::apiResource('products', ProductController::class)->only(['update'])
            ->middleware('api.permission:edit_products');
        Route::apiResource('products', ProductController::class)->only(['destroy'])
            ->middleware('api.permission:delete_products');

        // Product Options (nested under products)
        Route::prefix('products/{product}')->group(function () {
            Route::apiResource('options', ProductOptionController::class)->only(['index', 'show'])
                ->middleware('api.permission:view_products');
            Route::apiResource('options', ProductOptionController::class)->only(['store', 'update', 'destroy'])
                ->middleware('api.permission:edit_products');
            Route::post('options/reorder', [ProductOptionController::class, 'reorder'])
                ->middleware('api.permission:edit_products');
        });

        // Product Variants (nested under products)
        Route::prefix('products/{product}')->group(function () {
            Route::apiResource('variants', ProductVariantController::class)->only(['index', 'show'])
                ->middleware('api.permission:view_products');
            Route::apiResource('variants', ProductVariantController::class)->only(['store', 'update', 'destroy'])
                ->middleware('api.permission:edit_products');
            Route::post('variants/{variant}/adjust-stock', [ProductVariantController::class, 'adjustStock'])
                ->middleware('api.permission:manage_stock');
            Route::post('variants/bulk', [ProductVariantController::class, 'bulkCreate'])
                ->middleware('api.permission:edit_products');
        });

        // Batch Tracking (nested under products)
        Route::prefix('products/{product}')->group(function () {
            Route::get('batches', [BatchTrackingController::class, 'index'])
                ->middleware('api.permission:view_products');
            Route::post('batches', [BatchTrackingController::class, 'store'])
                ->middleware('api.permission:edit_products');
            Route::get('batches/{batch}', [BatchTrackingController::class, 'show'])
                ->middleware('api.permission:view_products');
        });

        // Serial Tracking (nested under products)
        Route::prefix('products/{product}')->group(function () {
            Route::get('serials', [SerialTrackingController::class, 'index'])
                ->middleware('api.permission:view_products');
            Route::post('serials', [SerialTrackingController::class, 'store'])
                ->middleware('api.permission:edit_products');
            Route::get('serials/{serial}', [SerialTrackingController::class, 'show'])
                ->middleware('api.permission:view_products');
            Route::put('serials/{serial}', [SerialTrackingController::class, 'update'])
                ->middleware('api.permission:edit_products');
        });

        // Product Categories
        Route::apiResource('categories', ProductCategoryController::class)
            ->middleware('api.permission:view_categories|manage_categories');

        // Product Locations
        Route::apiResource('locations', ProductLocationController::class)
            ->middleware('api.permission:view_locations|manage_locations');

        // Orders
        Route::apiResource('orders', OrderController::class)->only(['index', 'show'])
            ->middleware('api.permission:view_orders');
        Route::apiResource('orders', OrderController::class)->only(['store'])
            ->middleware('api.permission:create_orders');
        Route::apiResource('orders', OrderController::class)->only(['update'])
            ->middleware('api.permission:edit_orders');
        Route::apiResource('orders', OrderController::class)->only(['destroy'])
            ->middleware('api.permission:delete_orders');

        // Stock Audits
        Route::apiResource('stock-audits', ApiStockAuditController::class)
            ->only(['index', 'show'])
            ->middleware('api.permission:view_stock_audits|manage_stock_audits');

        // Stock Adjustments
        Route::apiResource('stock-adjustments', StockAdjustmentController::class)
            ->only(['index', 'show'])
            ->middleware('api.permission:view_stock_adjustments|manage_stock');
        Route::apiResource('stock-adjustments', StockAdjustmentController::class)
            ->only(['store'])
            ->middleware('api.permission:manage_stock');

        // Suppliers (will be available after Supplier model is created)
        Route::apiResource('suppliers', SupplierController::class)->only(['index', 'show'])
            ->middleware('api.permission:view_suppliers');
        Route::apiResource('suppliers', SupplierController::class)->only(['store'])
            ->middleware('api.permission:create_suppliers');
        Route::apiResource('suppliers', SupplierController::class)->only(['update'])
            ->middleware('api.permission:edit_suppliers');
        Route::apiResource('suppliers', SupplierController::class)->only(['destroy'])
            ->middleware('api.permission:delete_suppliers');

        // Purchase Orders
        Route::apiResource('purchase-orders', PurchaseOrderController::class)->only(['index', 'show'])
            ->middleware('api.permission:view_purchase_orders');
        Route::apiResource('purchase-orders', PurchaseOrderController::class)->only(['store'])
            ->middleware('api.permission:create_purchase_orders');
        Route::apiResource('purchase-orders', PurchaseOrderController::class)->only(['update'])
            ->middleware('api.permission:edit_purchase_orders');
        Route::apiResource('purchase-orders', PurchaseOrderController::class)->only(['destroy'])
            ->middleware('api.permission:delete_purchase_orders');
        Route::post('purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive'])
            ->middleware('api.permission:receive_purchase_orders');
        Route::post('purchase-orders/{purchaseOrder}/send', [PurchaseOrderController::class, 'send'])
            ->middleware('api.permission:edit_purchase_orders');
        Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])
            ->middleware('api.permission:edit_purchase_orders');
        Route::post('purchase-orders/{purchaseOrder}/submit-for-approval', [PurchaseOrderController::class, 'submitForApproval'])
            ->middleware('api.permission:edit_purchase_orders');

        // Approvals. Listing is open to every user (it only shows what they
        // may decide, or their own requests); deciding needs an approve_*
        // permission, checked per type by ApprovalService.
        Route::get('approvals', [ApprovalController::class, 'index']);
        Route::get('approvals/mine', [ApprovalController::class, 'mine']);
        Route::post('approvals/{type}/{id}/approve', [ApprovalController::class, 'approve'])
            ->whereIn('type', ['purchase_order', 'stock_adjustment', 'stock_transfer'])->whereNumber('id')
            ->middleware('api.permission:approve_purchase_orders|approve_stock_adjustments|approve_stock_transfers');
        Route::post('approvals/{type}/{id}/reject', [ApprovalController::class, 'reject'])
            ->whereIn('type', ['purchase_order', 'stock_adjustment', 'stock_transfer'])->whereNumber('id')
            ->middleware('api.permission:approve_purchase_orders|approve_stock_adjustments|approve_stock_transfers');

        // Barcode Lookup
        Route::get('barcode/{code}', [BarcodeLookupController::class, 'lookup'])
            ->middleware('api.permission:view_products');

        // Permission Sets
        Route::get('permission-sets/categories', [PermissionSetController::class, 'categories'])
            ->middleware('api.permission:view_roles');
        Route::apiResource('permission-sets', PermissionSetController::class)->only(['index', 'show'])
            ->middleware('api.permission:view_roles');
        Route::apiResource('permission-sets', PermissionSetController::class)->only(['store'])
            ->middleware('api.permission:create_roles');
        Route::apiResource('permission-sets', PermissionSetController::class)->only(['update'])
            ->middleware('api.permission:edit_roles');
        Route::apiResource('permission-sets', PermissionSetController::class)->only(['destroy'])
            ->middleware('api.permission:delete_roles');

        // Warehouses
        Route::apiResource('warehouses', \App\Http\Controllers\Api\WarehouseController::class)->only(['index', 'show'])
            ->middleware('api.permission:view_warehouses');
        Route::apiResource('warehouses', \App\Http\Controllers\Api\WarehouseController::class)->only(['store'])
            ->middleware('api.permission:create_warehouses');
        Route::apiResource('warehouses', \App\Http\Controllers\Api\WarehouseController::class)->only(['update'])
            ->middleware('api.permission:edit_warehouses');
        Route::apiResource('warehouses', \App\Http\Controllers\Api\WarehouseController::class)->only(['destroy'])
            ->middleware('api.permission:delete_warehouses');

        // Work Orders
        Route::apiResource('work-orders', \App\Http\Controllers\Api\WorkOrderController::class)
            ->only(['index', 'store', 'show'])
            ->middleware('api.permission:manage_stock');
        Route::post('work-orders/{workOrder}/start', [\App\Http\Controllers\Api\WorkOrderController::class, 'start'])
            ->middleware('api.permission:manage_stock');
        Route::post('work-orders/{workOrder}/complete', [\App\Http\Controllers\Api\WorkOrderController::class, 'complete'])
            ->middleware('api.permission:manage_stock');
        Route::post('work-orders/{workOrder}/cancel', [\App\Http\Controllers\Api\WorkOrderController::class, 'cancel'])
            ->middleware('api.permission:manage_stock');

        // Product Components (nested under products)
        Route::prefix('products/{product}')->group(function () {
            Route::get('components', [\App\Http\Controllers\Api\ProductComponentController::class, 'index'])
                ->middleware('api.permission:view_products');
            Route::post('components', [\App\Http\Controllers\Api\ProductComponentController::class, 'store'])
                ->middleware('api.permission:edit_products');
            Route::put('components/{component}', [\App\Http\Controllers\Api\ProductComponentController::class, 'update'])
                ->middleware('api.permission:edit_products');
            Route::delete('components/{component}', [\App\Http\Controllers\Api\ProductComponentController::class, 'destroy'])
                ->middleware('api.permission:edit_products');
        });

        // Parity endpoints. api.tenant answers 404 for any route-bound model
        // owned by another organization before validation can run.
        Route::middleware('api.tenant')->group(function () {
            // Order approval workflow
            Route::post('orders/{order}/approve', [OrderController::class, 'approve'])
                ->middleware('api.permission:approve_orders');
            Route::post('orders/{order}/reject', [OrderController::class, 'reject'])
                ->middleware('api.permission:approve_orders');
            Route::post('orders/{order}/invoice/email', [OrderController::class, 'emailInvoice'])
                ->middleware('api.permission:edit_orders');

            // Customers
            Route::apiResource('customers', CustomerController::class)->only(['index', 'show'])
                ->middleware('api.permission:view_customers');
            Route::apiResource('customers', CustomerController::class)->only(['store'])
                ->middleware('api.permission:create_customers');
            Route::apiResource('customers', CustomerController::class)->only(['update'])
                ->middleware('api.permission:edit_customers');
            Route::apiResource('customers', CustomerController::class)->only(['destroy'])
                ->middleware('api.permission:delete_customers');
            Route::get('customers/{customer}/orders', [CustomerController::class, 'orders'])
                ->middleware('api.permission:view_customers|view_orders,all');

            // Returns (RMA) - one permission covers the whole lifecycle, as on the web
            Route::middleware('api.permission:manage_returns')->group(function () {
                Route::apiResource('returns', ReturnOrderController::class)
                    ->only(['index', 'show', 'store'])
                    ->parameters(['returns' => 'returnOrder']);
                Route::post('returns/{returnOrder}/approve', [ReturnOrderController::class, 'approve']);
                Route::post('returns/{returnOrder}/receive', [ReturnOrderController::class, 'receive']);
                Route::post('returns/{returnOrder}/complete', [ReturnOrderController::class, 'complete']);
                Route::post('returns/{returnOrder}/reject', [ReturnOrderController::class, 'reject']);
            });

            // Stock Transfers
            Route::middleware('api.permission:transfer_stock')->group(function () {
                Route::apiResource('stock-transfers', StockTransferController::class)
                    ->only(['index', 'show', 'store']);
                Route::post('stock-transfers/{stockTransfer}/ship', [StockTransferController::class, 'ship']);
                Route::post('stock-transfers/{stockTransfer}/complete', [StockTransferController::class, 'complete']);
                Route::post('stock-transfers/{stockTransfer}/cancel', [StockTransferController::class, 'cancel']);
            });

            // Stock Audits (write)
            Route::post('stock-audits', [ApiStockAuditController::class, 'store'])
                ->middleware('api.permission:create_stock_audits');
            Route::post('stock-audits/{stockAudit}/start', [ApiStockAuditController::class, 'start'])
                ->middleware('api.permission:manage_stock_audits');
            Route::post('stock-audits/{stockAudit}/items/{item}/count', [ApiStockAuditController::class, 'recordCount'])
                ->middleware('api.permission:manage_stock_audits');
            Route::post('stock-audits/{stockAudit}/complete', [ApiStockAuditController::class, 'complete'])
                ->middleware('api.permission:manage_stock_audits');

            // Webhooks - organization administration, as on the web
            Route::middleware('api.permission:manage_organization')->group(function () {
                Route::apiResource('webhooks', WebhookController::class);
                Route::post('webhooks/{webhook}/regenerate-secret', [WebhookController::class, 'regenerateSecret']);
                Route::get('webhooks/{webhook}/deliveries', [WebhookController::class, 'deliveries']);
            });

            // Users
            Route::apiResource('users', UserController::class)->only(['index', 'show'])
                ->middleware('api.permission:view_users');
            Route::apiResource('users', UserController::class)->only(['store'])
                ->middleware('api.permission:create_users');
            Route::apiResource('users', UserController::class)->only(['update'])
                ->middleware('api.permission:edit_users');
        });

        // Saved Reports
        Route::apiResource('reports', \App\Http\Controllers\Api\SavedReportController::class)
            ->middleware('api.permission:view_reports');
        Route::get('reports/{report}/export', [\App\Http\Controllers\Api\SavedReportController::class, 'export'])
            ->middleware('api.permission:view_reports');
    });
});
