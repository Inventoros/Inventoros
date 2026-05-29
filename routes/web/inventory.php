<?php

declare(strict_types=1);

use App\Http\Controllers\Inventory\BarcodeController;
use App\Http\Controllers\Inventory\BulkProductController;
use App\Http\Controllers\Inventory\ProductCategoryController;
use App\Http\Controllers\Inventory\ProductComponentController;
use App\Http\Controllers\Inventory\ProductController;
use App\Http\Controllers\Inventory\ProductLocationController;
use App\Http\Controllers\Inventory\SKUController;
use App\Http\Controllers\Inventory\StockAdjustmentController;
use App\Http\Controllers\Inventory\StockAuditController;
use App\Http\Controllers\Inventory\StockTransferController;
use App\Http\Controllers\Inventory\WorkOrderController;
use App\Http\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;

/*
 * Inventory web routes. Loaded inside the `auth` group in routes/web.php, so
 * every route here is already authenticated.
 */

// Inventory Management - Permission based
Route::get('/products', [ProductController::class, 'index'])->name('products.index')->middleware('permission:view_products');
Route::get('/products/create', [ProductController::class, 'create'])->name('products.create')->middleware('permission:create_products');
Route::post('/products', [ProductController::class, 'store'])->name('products.store')->middleware('permission:create_products');
Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show')->middleware('permission:view_products');
Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit')->middleware('permission:edit_products');
Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update')->middleware('permission:edit_products');
Route::patch('/products/{product}', [ProductController::class, 'update'])->middleware('permission:edit_products');
Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy')->middleware('permission:delete_products');
Route::post('/products/{product}/duplicate', [ProductController::class, 'duplicate'])->name('products.duplicate')->middleware('permission:create_products');

// Bulk Product Operations
Route::prefix('products/bulk')->name('products.bulk.')->middleware('permission:edit_products')->group(function () {
    Route::post('/delete', [BulkProductController::class, 'bulkDelete'])->middleware('permission:delete_products')->name('delete');
    Route::post('/update-category', [BulkProductController::class, 'bulkUpdateCategory'])->name('update-category');
    Route::post('/update-price', [BulkProductController::class, 'bulkUpdatePrice'])->name('update-price');
    Route::post('/export', [BulkProductController::class, 'bulkExport'])->middleware('permission:export_data')->name('export');
});

// Barcode Management
Route::prefix('products/{product}/barcode')->name('products.barcode.')->middleware('permission:view_products')->group(function () {
    Route::get('/generate', [BarcodeController::class, 'generate'])->name('generate');
    Route::get('/print', [BarcodeController::class, 'print'])->name('print');
    Route::post('/generate-random', [BarcodeController::class, 'generateRandom'])->middleware('permission:edit_products')->name('generate-random');
    Route::post('/generate-from-sku', [BarcodeController::class, 'generateFromSKU'])->middleware('permission:edit_products')->name('generate-from-sku');
});

// Bulk Barcode Printing
Route::get('/products/barcode/bulk-print', [BarcodeController::class, 'bulkPrint'])
    ->name('products.barcode.bulk-print')
    ->middleware('permission:view_products');

// SKU Management
Route::prefix('sku')->name('sku.')->middleware('permission:view_products')->group(function () {
    Route::get('/patterns', [SKUController::class, 'patterns'])->name('patterns');
    Route::post('/generate', [SKUController::class, 'generate'])->name('generate');
    Route::post('/check-unique', [SKUController::class, 'checkUnique'])->name('check-unique');
});

// Product Components (BOM) for Kits & Assemblies
Route::prefix('products/{product}/components')->middleware('permission:edit_products')->group(function () {
    Route::get('/', [ProductComponentController::class, 'index'])->name('products.components.index');
    Route::post('/', [ProductComponentController::class, 'store'])->name('products.components.store');
    Route::put('/{component}', [ProductComponentController::class, 'update'])->name('products.components.update');
    Route::delete('/{component}', [ProductComponentController::class, 'destroy'])->name('products.components.destroy');
    Route::post('/reorder', [ProductComponentController::class, 'reorder'])->name('products.components.reorder');
});

// Work Orders
Route::resource('work-orders', WorkOrderController::class)->except(['edit', 'update'])->middleware('permission:manage_stock');
Route::post('work-orders/{workOrder}/start', [WorkOrderController::class, 'start'])->name('work-orders.start')->middleware('permission:manage_stock');
Route::post('work-orders/{workOrder}/complete', [WorkOrderController::class, 'complete'])->name('work-orders.complete')->middleware('permission:manage_stock');
Route::post('work-orders/{workOrder}/cancel', [WorkOrderController::class, 'cancel'])->name('work-orders.cancel')->middleware('permission:manage_stock');

// Categories - Permission based
Route::resource('categories', ProductCategoryController::class)
    ->except(['create', 'show', 'edit'])
    ->middleware('permission:manage_categories');

// Locations - Permission based
Route::resource('locations', ProductLocationController::class)
    ->except(['create', 'show', 'edit'])
    ->middleware('permission:manage_locations');

// Warehouses - Permission based
Route::resource('warehouses', WarehouseController::class)->middleware('permission:view_warehouses');
Route::post('warehouses/{warehouse}/users', [WarehouseController::class, 'updateUsers'])->name('warehouses.users.update')->middleware('permission:manage_warehouse_users');
Route::post('warehouses/{warehouse}/set-default', [WarehouseController::class, 'setDefault'])->name('warehouses.set-default')->middleware('permission:edit_warehouses');
Route::post('/set-warehouse', [WarehouseController::class, 'setActiveWarehouse'])->name('warehouses.set-active');

// Stock Transfers - Permission based
Route::get('/stock-transfers', [StockTransferController::class, 'index'])->name('stock-transfers.index')->middleware('permission:transfer_stock');
Route::get('/stock-transfers/create', [StockTransferController::class, 'create'])->name('stock-transfers.create')->middleware('permission:transfer_stock');
Route::post('/stock-transfers', [StockTransferController::class, 'store'])->name('stock-transfers.store')->middleware('permission:transfer_stock');
Route::get('/stock-transfers/{stockTransfer}', [StockTransferController::class, 'show'])->name('stock-transfers.show')->middleware('permission:transfer_stock');
Route::put('/stock-transfers/{stockTransfer}', [StockTransferController::class, 'update'])->name('stock-transfers.update')->middleware('permission:transfer_stock');
Route::post('/stock-transfers/{stockTransfer}/complete', [StockTransferController::class, 'complete'])->name('stock-transfers.complete')->middleware('permission:transfer_stock');
Route::post('/stock-transfers/{stockTransfer}/cancel', [StockTransferController::class, 'cancel'])->name('stock-transfers.cancel')->middleware('permission:transfer_stock');

// Stock Audits - Permission based
Route::get('/stock-audits', [StockAuditController::class, 'index'])->name('stock-audits.index')->middleware('permission:view_stock_audits');
Route::get('/stock-audits/create', [StockAuditController::class, 'create'])->name('stock-audits.create')->middleware('permission:create_stock_audits');
Route::post('/stock-audits', [StockAuditController::class, 'store'])->name('stock-audits.store')->middleware('permission:create_stock_audits');
Route::get('/stock-audits/{stockAudit}', [StockAuditController::class, 'show'])->name('stock-audits.show')->middleware('permission:view_stock_audits');
Route::get('/stock-audits/{stockAudit}/edit', [StockAuditController::class, 'edit'])->name('stock-audits.edit')->middleware('permission:manage_stock_audits');
Route::put('/stock-audits/{stockAudit}', [StockAuditController::class, 'update'])->name('stock-audits.update')->middleware('permission:manage_stock_audits');
Route::delete('/stock-audits/{stockAudit}', [StockAuditController::class, 'destroy'])->name('stock-audits.destroy')->middleware('permission:manage_stock_audits');
Route::post('/stock-audits/{stockAudit}/start', [StockAuditController::class, 'start'])->name('stock-audits.start')->middleware('permission:manage_stock_audits');
Route::post('/stock-audits/{stockAudit}/complete', [StockAuditController::class, 'complete'])->name('stock-audits.complete')->middleware('permission:manage_stock_audits');
Route::post('/stock-audits/{stockAudit}/items/{item}/count', [StockAuditController::class, 'updateCount'])->name('stock-audits.items.count')->middleware('permission:manage_stock_audits');

// Stock Adjustments - Permission based
Route::get('/stock-adjustments', [StockAdjustmentController::class, 'index'])->name('stock-adjustments.index')->middleware('permission:manage_stock');
Route::get('/stock-adjustments/create', [StockAdjustmentController::class, 'create'])->name('stock-adjustments.create')->middleware('permission:manage_stock');
Route::post('/stock-adjustments', [StockAdjustmentController::class, 'store'])->name('stock-adjustments.store')->middleware('permission:manage_stock');
Route::get('/stock-adjustments/{stockAdjustment}', [StockAdjustmentController::class, 'show'])->name('stock-adjustments.show')->middleware('permission:manage_stock');
