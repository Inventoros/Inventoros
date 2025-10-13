<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\PluginController;
use App\Http\Controllers\Admin\UserController;
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

    // Inventory Management
    Route::resource('products', ProductController::class);
    Route::resource('categories', ProductCategoryController::class)->except(['create', 'show', 'edit']);
    Route::resource('locations', ProductLocationController::class)->except(['create', 'show', 'edit']);

    // Order Management
    Route::resource('orders', OrderController::class);

    // Plugins
    Route::prefix('plugins')->name('plugins.')->group(function () {
        Route::get('/', [PluginController::class, 'index'])->name('index');
        Route::post('/upload', [PluginController::class, 'upload'])->name('upload');
        Route::post('/{plugin}/activate', [PluginController::class, 'activate'])->name('activate');
        Route::post('/{plugin}/deactivate', [PluginController::class, 'deactivate'])->name('deactivate');
        Route::delete('/{plugin}', [PluginController::class, 'destroy'])->name('destroy');
    });

    // User Management (Admin only)
    Route::middleware('permission:view_users')->group(function () {
        Route::resource('users', UserController::class)->only(['index', 'show']);
    });

    Route::middleware('permission:create_users')->group(function () {
        Route::resource('users', UserController::class)->only(['create', 'store']);
    });

    Route::middleware('permission:edit_users')->group(function () {
        Route::resource('users', UserController::class)->only(['edit', 'update']);
    });

    Route::middleware('permission:delete_users')->group(function () {
        Route::resource('users', UserController::class)->only(['destroy']);
    });

    // Role Management (Admin only)
    Route::middleware('permission:view_roles')->group(function () {
        Route::resource('roles', RoleController::class)->only(['index', 'show']);
    });

    Route::middleware('permission:create_roles')->group(function () {
        Route::resource('roles', RoleController::class)->only(['create', 'store']);
    });

    Route::middleware('permission:edit_roles')->group(function () {
        Route::resource('roles', RoleController::class)->only(['edit', 'update']);
    });

    Route::middleware('permission:delete_roles')->group(function () {
        Route::resource('roles', RoleController::class)->only(['destroy']);
    });

    // Settings
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/', [SettingsController::class, 'index'])->name('index');
        Route::patch('/organization', [SettingsController::class, 'updateOrganization'])->middleware('permission:manage_organization')->name('organization.update');
    });
});

require __DIR__.'/auth.php';
