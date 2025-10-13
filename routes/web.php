<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\PluginController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Import\ImportExportController;
use App\Http\Controllers\Install\InstallerController;
use App\Http\Controllers\Inventory\ProductController;
use App\Http\Controllers\Inventory\ProductCategoryController;
use App\Http\Controllers\Inventory\ProductLocationController;
use App\Http\Controllers\Order\OrderController;
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

    // Categories - Permission based
    Route::resource('categories', ProductCategoryController::class)
        ->except(['create', 'show', 'edit'])
        ->middleware('permission:manage_categories');

    // Locations - Permission based
    Route::resource('locations', ProductLocationController::class)
        ->except(['create', 'show', 'edit'])
        ->middleware('permission:manage_locations');

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
        Route::get('/', [SettingsController::class, 'index'])->middleware('permission:view_settings')->name('index');
        Route::patch('/organization', [SettingsController::class, 'updateOrganization'])->middleware('permission:manage_organization')->name('organization.update');
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
