<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AccountSettingsController;
use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\OrganizationSettingsController;
use App\Http\Controllers\Admin\PluginController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UpdateController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Users, roles, plugins, settings, webhooks, activity log, and system update.
 * Loaded inside the `auth` group in routes/web.php.
 */

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
        Route::get('/', [OrganizationSettingsController::class, 'index'])->name('index');
        Route::patch('/general', [OrganizationSettingsController::class, 'updateGeneral'])->middleware('permission:manage_organization')->name('update.general');
        Route::patch('/regional', [OrganizationSettingsController::class, 'updateRegional'])->middleware('permission:manage_organization')->name('update.regional');

        // User management within organization settings (admin only)
        Route::middleware('permission:manage_organization')->group(function () {
            Route::get('/users', [OrganizationSettingsController::class, 'users'])->name('users.index');
            Route::post('/users', [OrganizationSettingsController::class, 'storeUser'])->name('users.store');
            Route::patch('/users/{user}', [OrganizationSettingsController::class, 'updateUser'])->name('users.update');
            Route::delete('/users/{user}', [OrganizationSettingsController::class, 'destroyUser'])->name('users.destroy');
        });
    });

    // Dashboard Widget Preferences (accessible by all authenticated users)
    Route::patch('/dashboard-widgets', [DashboardController::class, 'updateWidgets'])
        ->name('dashboard-widgets.update');

    // Account Settings (accessible by all authenticated users)
    Route::prefix('account')->name('account.')->group(function () {
        Route::get('/', [AccountSettingsController::class, 'index'])->name('index');
        Route::patch('/profile', [AccountSettingsController::class, 'updateProfile'])->name('update.profile');
        Route::patch('/password', [AccountSettingsController::class, 'updatePassword'])->name('update.password');
        Route::patch('/notifications', [AccountSettingsController::class, 'updateNotifications'])->name('update.notifications');
        Route::patch('/preferences', [AccountSettingsController::class, 'updatePreferences'])->name('update.preferences');
    });

    // Email Settings (admin only)
    Route::middleware('permission:manage_organization')->group(function () {
        Route::get('/email', [SettingsController::class, 'index'])->name('email.index');
        Route::post('/email', [SettingsController::class, 'updateEmail'])->name('email.update');
        Route::post('/email/test', [SettingsController::class, 'testEmail'])->name('email.test');
    });
});

// Webhooks
Route::middleware('permission:manage_organization')->group(function () {
    Route::resource('webhooks', WebhookController::class);
    Route::post('webhooks/{webhook}/regenerate-secret', [WebhookController::class, 'regenerateSecret'])->name('webhooks.regenerate-secret');
    Route::post('webhooks/{webhook}/test', [WebhookController::class, 'test'])->name('webhooks.test');
    Route::post('webhook-deliveries/{delivery}/retry', [WebhookController::class, 'retryDelivery'])->name('webhook-deliveries.retry');
});

// Activity Log - Permission based
Route::get('/activity-log', [ActivityLogController::class, 'index'])
    ->middleware('permission:view_activity_log')
    ->name('activity-log.index');
Route::get('/activity-log/export', [ActivityLogController::class, 'export'])
    ->middleware('permission:view_activity_log')
    ->name('activity-log.export');

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
