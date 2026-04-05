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
        Route::apiResource('products', ProductController::class)
            ->middleware('api.permission:view_products|manage_products');

        // Product Options (nested under products)
        Route::prefix('products/{product}')->middleware('api.permission:view_products|manage_products')->group(function () {
            Route::apiResource('options', ProductOptionController::class);
            Route::post('options/reorder', [ProductOptionController::class, 'reorder']);
        });

        // Product Variants (nested under products)
        Route::prefix('products/{product}')->middleware('api.permission:view_products|manage_products')->group(function () {
            Route::apiResource('variants', ProductVariantController::class);
            Route::post('variants/{variant}/adjust-stock', [ProductVariantController::class, 'adjustStock']);
            Route::post('variants/bulk', [ProductVariantController::class, 'bulkCreate']);
        });

        // Batch Tracking (nested under products)
        Route::prefix('products/{product}')->middleware('api.permission:view_products|manage_products')->group(function () {
            Route::get('batches', [BatchTrackingController::class, 'index']);
            Route::post('batches', [BatchTrackingController::class, 'store']);
            Route::get('batches/{batch}', [BatchTrackingController::class, 'show']);
        });

        // Serial Tracking (nested under products)
        Route::prefix('products/{product}')->middleware('api.permission:view_products|manage_products')->group(function () {
            Route::get('serials', [SerialTrackingController::class, 'index']);
            Route::post('serials', [SerialTrackingController::class, 'store']);
            Route::get('serials/{serial}', [SerialTrackingController::class, 'show']);
            Route::put('serials/{serial}', [SerialTrackingController::class, 'update']);
        });

        // Product Categories
        Route::apiResource('categories', ProductCategoryController::class)
            ->middleware('api.permission:view_categories|manage_categories');

        // Product Locations
        Route::apiResource('locations', ProductLocationController::class)
            ->middleware('api.permission:view_locations|manage_locations');

        // Orders
        Route::apiResource('orders', OrderController::class)
            ->middleware('api.permission:view_orders|manage_orders');

        // Stock Audits
        Route::apiResource('stock-audits', ApiStockAuditController::class)
            ->only(['index', 'show'])
            ->middleware('api.permission:view_stock_audits|manage_stock_audits');

        // Stock Adjustments
        Route::apiResource('stock-adjustments', StockAdjustmentController::class)
            ->only(['index', 'store', 'show'])
            ->middleware('api.permission:view_stock_adjustments|manage_stock');

        // Suppliers (will be available after Supplier model is created)
        Route::apiResource('suppliers', SupplierController::class)
            ->middleware('api.permission:view_suppliers|manage_suppliers');

        // Purchase Orders
        Route::apiResource('purchase-orders', PurchaseOrderController::class)
            ->middleware('api.permission:view_purchase_orders|manage_purchase_orders');
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
        Route::apiResource('permission-sets', PermissionSetController::class)
            ->middleware('api.permission:view_roles|manage_roles');

        // Warehouses
        Route::apiResource('warehouses', \App\Http\Controllers\Api\WarehouseController::class)
            ->middleware('api.permission:view_warehouses|manage_warehouses');

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
        Route::prefix('products/{product}')->middleware('api.permission:view_products|manage_products')->group(function () {
            Route::get('components', [\App\Http\Controllers\Api\ProductComponentController::class, 'index']);
            Route::post('components', [\App\Http\Controllers\Api\ProductComponentController::class, 'store']);
            Route::put('components/{component}', [\App\Http\Controllers\Api\ProductComponentController::class, 'update']);
            Route::delete('components/{component}', [\App\Http\Controllers\Api\ProductComponentController::class, 'destroy']);
        });

        // Saved Reports
        Route::apiResource('reports', \App\Http\Controllers\Api\SavedReportController::class)
            ->middleware('api.permission:view_reports');
        Route::get('reports/{report}/export', [\App\Http\Controllers\Api\SavedReportController::class, 'export'])
            ->middleware('api.permission:view_reports');
    });
});
