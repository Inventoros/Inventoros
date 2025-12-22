<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\PluginController;
use App\Http\Controllers\Admin\UpdateController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Import\ImportExportController;
use App\Http\Controllers\Install\InstallerController;
use App\Http\Controllers\Inventory\ProductController;
use App\Http\Controllers\Inventory\ProductCategoryController;
use App\Http\Controllers\Inventory\ProductLocationController;
use App\Http\Controllers\Inventory\StockAdjustmentController;
use App\Http\Controllers\Inventory\SupplierController;
use App\Http\Controllers\Order\OrderController;
use App\Http\Controllers\Purchasing\PurchaseOrderController;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Installer routes
Route::prefix('install')->name('install.')->group(function () {
    Route::get('/', [InstallerController::class, 'index'])->name('index');
    Route::get('/requirements', [InstallerController::class, 'requirements'])->name('requirements');
    Route::get('/database', [InstallerController::class, 'database'])->name('database');
    Route::post('/database/test', [InstallerController::class, 'testDatabase'])->name('database.test');
    Route::post('/database/install', [InstallerController::class, 'installDatabase'])->name('database.install');
    Route::get('/admin', [InstallerController::class, 'admin'])->name('admin');
    Route::post('/admin', [InstallerController::class, 'createAdmin'])->name('admin.create');
    Route::get('/complete', [InstallerController::class, 'complete'])->name('complete');
});

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }
    return redirect()->route('login');
});

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Inventory Management - Permission based
    Route::get('/products', [ProductController::class, 'index'])->name('products.index')->middleware('permission:view_products');
    Route::get('/products/create', [ProductController::class, 'create'])->name('products.create')->middleware('permission:create_products');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store')->middleware('permission:create_products');
    Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show')->middleware('permission:view_products');
    Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit')->middleware('permission:edit_products');
    Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update')->middleware('permission:edit_products');
    Route::patch('/products/{product}', [ProductController::class, 'update'])->middleware('permission:edit_products');
    Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy')->middleware('permission:delete_products');

    // Barcode Management
    Route::prefix('products/{product}/barcode')->name('products.barcode.')->middleware('permission:view_products')->group(function () {
        Route::get('/generate', [\App\Http\Controllers\Inventory\BarcodeController::class, 'generate'])->name('generate');
        Route::get('/print', [\App\Http\Controllers\Inventory\BarcodeController::class, 'print'])->name('print');
        Route::post('/generate-random', [\App\Http\Controllers\Inventory\BarcodeController::class, 'generateRandom'])->middleware('permission:edit_products')->name('generate-random');
        Route::post('/generate-from-sku', [\App\Http\Controllers\Inventory\BarcodeController::class, 'generateFromSKU'])->middleware('permission:edit_products')->name('generate-from-sku');
    });

    // SKU Management
    Route::prefix('sku')->name('sku.')->middleware('permission:view_products')->group(function () {
        Route::get('/patterns', [\App\Http\Controllers\Inventory\SKUController::class, 'patterns'])->name('patterns');
        Route::post('/generate', [\App\Http\Controllers\Inventory\SKUController::class, 'generate'])->name('generate');
        Route::post('/check-unique', [\App\Http\Controllers\Inventory\SKUController::class, 'checkUnique'])->name('check-unique');
    });

    // Categories - Permission based
    Route::resource('categories', ProductCategoryController::class)
        ->except(['create', 'show', 'edit'])
        ->middleware('permission:manage_categories');

    // Locations - Permission based
    Route::resource('locations', ProductLocationController::class)
        ->except(['create', 'show', 'edit'])
        ->middleware('permission:manage_locations');

    // Stock Adjustments - Permission based
    Route::get('/stock-adjustments', [StockAdjustmentController::class, 'index'])->name('stock-adjustments.index')->middleware('permission:manage_stock');
    Route::get('/stock-adjustments/create', [StockAdjustmentController::class, 'create'])->name('stock-adjustments.create')->middleware('permission:manage_stock');
    Route::post('/stock-adjustments', [StockAdjustmentController::class, 'store'])->name('stock-adjustments.store')->middleware('permission:manage_stock');
    Route::get('/stock-adjustments/{stockAdjustment}', [StockAdjustmentController::class, 'show'])->name('stock-adjustments.show')->middleware('permission:manage_stock');

    // Supplier Management - Permission based
    Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index')->middleware('permission:view_suppliers');
    Route::get('/suppliers/create', [SupplierController::class, 'create'])->name('suppliers.create')->middleware('permission:create_suppliers');
    Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store')->middleware('permission:create_suppliers');
    Route::get('/suppliers/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show')->middleware('permission:view_suppliers');
    Route::get('/suppliers/{supplier}/edit', [SupplierController::class, 'edit'])->name('suppliers.edit')->middleware('permission:edit_suppliers');
    Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update')->middleware('permission:edit_suppliers');
    Route::patch('/suppliers/{supplier}', [SupplierController::class, 'update'])->middleware('permission:edit_suppliers');
    Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('suppliers.destroy')->middleware('permission:delete_suppliers');

    // Purchase Order Management - Permission based
    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index')->middleware('permission:view_purchase_orders');
    Route::get('/purchase-orders/create', [PurchaseOrderController::class, 'create'])->name('purchase-orders.create')->middleware('permission:create_purchase_orders');
    Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase-orders.store')->middleware('permission:create_purchase_orders');
    Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->name('purchase-orders.show')->middleware('permission:view_purchase_orders');
    Route::get('/purchase-orders/{purchaseOrder}/edit', [PurchaseOrderController::class, 'edit'])->name('purchase-orders.edit')->middleware('permission:edit_purchase_orders');
    Route::put('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->name('purchase-orders.update')->middleware('permission:edit_purchase_orders');
    Route::patch('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->middleware('permission:edit_purchase_orders');
    Route::delete('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy'])->name('purchase-orders.destroy')->middleware('permission:delete_purchase_orders');
    Route::get('/purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive'])->name('purchase-orders.receive')->middleware('permission:receive_purchase_orders');
    Route::post('/purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'processReceiving'])->name('purchase-orders.process-receiving')->middleware('permission:receive_purchase_orders');
    Route::post('/purchase-orders/{purchaseOrder}/send', [PurchaseOrderController::class, 'sendToSupplier'])->name('purchase-orders.send')->middleware('permission:edit_purchase_orders');
    Route::post('/purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel')->middleware('permission:edit_purchase_orders');

    // Order Management - Permission based
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index')->middleware('permission:view_orders');
    Route::get('/orders/create', [OrderController::class, 'create'])->name('orders.create')->middleware('permission:create_orders');
    Route::post('/orders', [OrderController::class, 'store'])->name('orders.store')->middleware('permission:create_orders');
    Route::get('/orders/{order}/edit', [OrderController::class, 'edit'])->name('orders.edit')->middleware('permission:edit_orders');
    Route::put('/orders/{order}', [OrderController::class, 'update'])->name('orders.update')->middleware('permission:edit_orders');
    Route::patch('/orders/{order}', [OrderController::class, 'update'])->middleware('permission:edit_orders');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show')->middleware('permission:view_orders');
    Route::delete('/orders/{order}', [OrderController::class, 'destroy'])->name('orders.destroy')->middleware('permission:delete_orders');

    // User Management - Permission based
    Route::get('/users', [UserController::class, 'index'])->name('users.index')->middleware('permission:view_users');
    Route::get('/users/create', [UserController::class, 'create'])->name('users.create')->middleware('permission:create_users');
    Route::post('/users', [UserController::class, 'store'])->name('users.store')->middleware('permission:create_users');
    Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show')->middleware('permission:view_users');
    Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit')->middleware('permission:edit_users');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update')->middleware('permission:edit_users');
    Route::patch('/users/{user}', [UserController::class, 'update'])->middleware('permission:edit_users');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy')->middleware('permission:delete_users');

    // Role Management - Permission based
    Route::get('/roles', [RoleController::class, 'index'])->name('roles.index')->middleware('permission:view_roles');
    Route::get('/roles/create', [RoleController::class, 'create'])->name('roles.create')->middleware('permission:create_roles');
    Route::post('/roles', [RoleController::class, 'store'])->name('roles.store')->middleware('permission:create_roles');
    Route::get('/roles/{role}', [RoleController::class, 'show'])->name('roles.show')->middleware('permission:view_roles');
    Route::get('/roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit')->middleware('permission:edit_roles');
    Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update')->middleware('permission:edit_roles');
    Route::patch('/roles/{role}', [RoleController::class, 'update'])->middleware('permission:edit_roles');
    Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy')->middleware('permission:delete_roles');

    // Plugins - Permission based
    Route::prefix('plugins')->name('plugins.')->middleware('permission:view_plugins')->group(function () {
        Route::get('/', [PluginController::class, 'index'])->name('index');
        Route::post('/upload', [PluginController::class, 'upload'])->middleware('permission:manage_plugins')->name('upload');
        Route::post('/{plugin}/activate', [PluginController::class, 'activate'])->middleware('permission:manage_plugins')->name('activate');
        Route::post('/{plugin}/deactivate', [PluginController::class, 'deactivate'])->middleware('permission:manage_plugins')->name('deactivate');
        Route::delete('/{plugin}', [PluginController::class, 'destroy'])->middleware('permission:manage_plugins')->name('destroy');
    });

    // Settings - Permission based
    Route::prefix('settings')->name('settings.')->group(function () {
        // Legacy settings route - redirect to organization settings
        Route::get('/', function () {
            return redirect()->route('settings.organization.index');
        })->middleware('permission:view_settings')->name('index');

        // Organization Settings
        Route::prefix('organization')->name('organization.')->middleware('permission:view_settings')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\OrganizationSettingsController::class, 'index'])->name('index');
            Route::patch('/general', [\App\Http\Controllers\Admin\OrganizationSettingsController::class, 'updateGeneral'])->middleware('permission:manage_organization')->name('update.general');
            Route::patch('/regional', [\App\Http\Controllers\Admin\OrganizationSettingsController::class, 'updateRegional'])->middleware('permission:manage_organization')->name('update.regional');

            // User management within organization settings (admin only)
            Route::middleware('permission:manage_organization')->group(function () {
                Route::get('/users', [\App\Http\Controllers\Admin\OrganizationSettingsController::class, 'users'])->name('users.index');
                Route::post('/users', [\App\Http\Controllers\Admin\OrganizationSettingsController::class, 'storeUser'])->name('users.store');
                Route::patch('/users/{user}', [\App\Http\Controllers\Admin\OrganizationSettingsController::class, 'updateUser'])->name('users.update');
                Route::delete('/users/{user}', [\App\Http\Controllers\Admin\OrganizationSettingsController::class, 'destroyUser'])->name('users.destroy');
            });
        });

        // Account Settings (accessible by all authenticated users)
        Route::prefix('account')->name('account.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\AccountSettingsController::class, 'index'])->name('index');
            Route::patch('/profile', [\App\Http\Controllers\Admin\AccountSettingsController::class, 'updateProfile'])->name('update.profile');
            Route::patch('/password', [\App\Http\Controllers\Admin\AccountSettingsController::class, 'updatePassword'])->name('update.password');
            Route::patch('/notifications', [\App\Http\Controllers\Admin\AccountSettingsController::class, 'updateNotifications'])->name('update.notifications');
            Route::patch('/preferences', [\App\Http\Controllers\Admin\AccountSettingsController::class, 'updatePreferences'])->name('update.preferences');
        });
    });

    // Activity Log - Permission based
    Route::get('/activity-log', [\App\Http\Controllers\Admin\ActivityLogController::class, 'index'])
        ->middleware('permission:view_activity_log')
        ->name('activity-log.index');

    // System Update - Admin only
    Route::prefix('admin/update')->name('admin.update.')->middleware('permission:manage_organization')->group(function () {
        Route::get('/', [UpdateController::class, 'index'])->name('index');
        Route::get('/check', [UpdateController::class, 'check'])->name('check');
        Route::post('/perform', [UpdateController::class, 'update'])->name('perform');
        Route::post('/backup', [UpdateController::class, 'backup'])->name('backup');
        Route::get('/backups', [UpdateController::class, 'listBackups'])->name('backups.list');
        Route::post('/restore', [UpdateController::class, 'restore'])->name('restore');
        Route::delete('/backup', [UpdateController::class, 'deleteBackup'])->name('backup.delete');
    });

    // Reports - Permission based
    Route::prefix('reports')->name('reports.')->middleware('permission:view_reports')->group(function () {
        Route::get('/', [\App\Http\Controllers\Reports\ReportController::class, 'index'])->name('index');
        Route::get('/inventory-valuation', [\App\Http\Controllers\Reports\ReportController::class, 'inventoryValuation'])->name('inventory-valuation');
        Route::get('/stock-movement', [\App\Http\Controllers\Reports\ReportController::class, 'stockMovement'])->name('stock-movement');
        Route::get('/sales-analysis', [\App\Http\Controllers\Reports\ReportController::class, 'salesAnalysis'])->name('sales-analysis');
        Route::get('/low-stock', [\App\Http\Controllers\Reports\ReportController::class, 'lowStock'])->name('low-stock');
        Route::get('/category-performance', [\App\Http\Controllers\Reports\ReportController::class, 'categoryPerformance'])->name('category-performance');
    });

    // Notifications
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [\App\Http\Controllers\NotificationController::class, 'index'])->name('index');
        Route::get('/unread-count', [\App\Http\Controllers\NotificationController::class, 'unreadCount'])->name('unread-count');
        Route::post('/{notification}/read', [\App\Http\Controllers\NotificationController::class, 'markAsRead'])->name('mark-as-read');
        Route::post('/mark-all-read', [\App\Http\Controllers\NotificationController::class, 'markAllAsRead'])->name('mark-all-read');
        Route::delete('/{notification}', [\App\Http\Controllers\NotificationController::class, 'destroy'])->name('destroy');
        Route::delete('/clear/read', [\App\Http\Controllers\NotificationController::class, 'clearRead'])->name('clear-read');
    });

    // Import/Export - Permission based
    Route::prefix('import-export')->name('import-export.')->group(function () {
        Route::get('/', [ImportExportController::class, 'index'])->middleware('permission:export_data|import_data')->name('index');
        Route::get('/export-products', [ImportExportController::class, 'exportProducts'])->middleware('permission:export_data')->name('export-products');
        Route::get('/download-template', [ImportExportController::class, 'downloadTemplate'])->middleware('permission:import_data')->name('download-template');
        Route::post('/import-products', [ImportExportController::class, 'importProducts'])->middleware('permission:import_data')->name('import-products');
    });
});

require __DIR__.'/auth.php';
