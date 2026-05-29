<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Install\InstallerController;
use Illuminate\Support\Facades\Route;

/*
 * Web routes.
 *
 * Authenticated application routes are split by module under routes/web/ and
 * required inside the single `auth` group below, so every module route is
 * authenticated. Public routes (installer, landing redirect) stay here.
 */

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

// Authenticated application routes, split by module. Each file declares its
// routes with Route:: and is required inside this group, inheriting `auth`.
Route::middleware('auth')->group(function () {
    require __DIR__.'/web/account.php';
    require __DIR__.'/web/inventory.php';
    require __DIR__.'/web/sales.php';
    require __DIR__.'/web/admin.php';
    require __DIR__.'/web/reports.php';
});

require __DIR__.'/auth.php';
