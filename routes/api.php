<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BarcodeLookupController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductCategoryController;
use App\Http\Controllers\Api\BatchTrackingController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductLocationController;
use App\Http\Controllers\Api\SerialTrackingController;
use App\Http\Controllers\Api\ProductOptionController;
use App\Http\Controllers\Api\ProductVariantController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\StockAdjustmentController;
use App\Http\Controllers\Api\StockAuditController as ApiStockAuditController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\PermissionSetController;
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

        // Saved Reports
        Route::apiResource('reports', \App\Http\Controllers\Api\SavedReportController::class)
            ->middleware('api.permission:view_reports');
        Route::get('reports/{report}/export', [\App\Http\Controllers\Api\SavedReportController::class, 'export'])
            ->middleware('api.permission:view_reports');
    });
});
