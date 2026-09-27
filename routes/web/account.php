<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Import\ImportExportController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

/*
 * Per-user routes: search, two-factor, profile, notifications, import/export.
 * Loaded inside the `auth` group in routes/web.php.
 */

// Global Search
Route::get('/search', [SearchController::class, 'search'])->name('search');

// Two-Factor Authentication
Route::get('/two-factor-challenge', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
Route::post('/two-factor-challenge', [TwoFactorController::class, 'verifyChallenge'])->name('two-factor.challenge.verify')->middleware('throttle:5,1');

Route::prefix('settings/two-factor')->name('two-factor.')->group(function () {
    Route::get('/setup', [TwoFactorController::class, 'setup'])->name('setup');
    Route::post('/enable', [TwoFactorController::class, 'enable'])->name('enable');
    Route::post('/disable', [TwoFactorController::class, 'disable'])->name('disable');
});

Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

// Notifications
Route::prefix('notifications')->name('notifications.')->group(function () {
    Route::get('/', [NotificationController::class, 'index'])->name('index');
    Route::get('/unread-count', [NotificationController::class, 'unreadCount'])->name('unread-count');
    Route::post('/{notification}/read', [NotificationController::class, 'markAsRead'])->name('mark-as-read');
    Route::post('/mark-all-read', [NotificationController::class, 'markAllAsRead'])->name('mark-all-read');
    Route::delete('/{notification}', [NotificationController::class, 'destroy'])->name('destroy');
    Route::delete('/clear/read', [NotificationController::class, 'clearRead'])->name('clear-read');
});

// Import/Export - Permission based
Route::prefix('import-export')->name('import-export.')->group(function () {
    Route::get('/', [ImportExportController::class, 'index'])->middleware('permission:export_data|import_data')->name('index');
    Route::get('/export-products', [ImportExportController::class, 'exportProducts'])->middleware('permission:export_data')->name('export-products');
    Route::get('/export-orders', [ImportExportController::class, 'exportOrders'])->middleware('permission:export_data')->name('export-orders');
    // The user list (emails, roles) is only for people who may view users.
    Route::get('/export-users', [ImportExportController::class, 'exportUsers'])->middleware(['permission:export_data', 'permission:view_users'])->name('export-users');
    Route::get('/downloads/{dataExport}', [ImportExportController::class, 'download'])->middleware('permission:export_data')->name('download');
    Route::get('/download-template', [ImportExportController::class, 'downloadTemplate'])->middleware('permission:import_data')->name('download-template');
    Route::post('/import-products', [ImportExportController::class, 'importProducts'])->middleware('permission:import_data')->name('import-products');
    // Order import creates orders (and customers), so it needs create_orders too.
    Route::get('/download-order-template', [ImportExportController::class, 'downloadOrderTemplate'])->middleware(['permission:import_data', 'permission:create_orders'])->name('download-order-template');
    Route::post('/import-orders', [ImportExportController::class, 'importOrders'])->middleware(['permission:import_data', 'permission:create_orders'])->name('import-orders');
    // User import creates accounts, so it needs create_users too.
    Route::get('/download-user-template', [ImportExportController::class, 'downloadUserTemplate'])->middleware(['permission:import_data', 'permission:create_users'])->name('download-user-template');
    Route::post('/import-users', [ImportExportController::class, 'importUsers'])->middleware(['permission:import_data', 'permission:create_users'])->name('import-users');
});
