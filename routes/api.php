<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BarcodeLookupController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductCategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductLocationController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\StockAdjustmentController;
use App\Http\Controllers\Api\SupplierController;
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

// API Version 1
Route::prefix('v1')->group(function () {
    // Public routes
    Route::post('/login', [AuthController::class, 'login']);

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

        // Product Categories
        Route::apiResource('categories', ProductCategoryController::class)
            ->middleware('api.permission:view_categories|manage_categories');

        // Product Locations
        Route::apiResource('locations', ProductLocationController::class)
            ->middleware('api.permission:view_locations|manage_locations');

        // Orders
        Route::apiResource('orders', OrderController::class)
            ->middleware('api.permission:view_orders|manage_orders');

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
    });
});
