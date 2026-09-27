<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Install\InstallerController;
use App\Support\PluginPageRoutes;
use App\Http\Controllers\Webhooks\EasyPostWebhookController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
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

// EasyPost tracking webhooks. Public (EasyPost cannot log in), so the route
// carries an unguessable per-organization token and every request must carry
// a valid HMAC signature; CSRF does not apply to a server-to-server POST.
Route::post('/webhooks/easypost/{token}', EasyPostWebhookController::class)
    ->where('token', '[A-Za-z0-9]{32,64}')
    ->middleware('throttle:120,1')
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('webhooks.easypost');

// Authenticated application routes, split by module. Each file declares its
// routes with Route:: and is required inside this group, inheriting `auth`.
Route::middleware('auth')->group(function () {
    require __DIR__.'/web/account.php';
    require __DIR__.'/web/inventory.php';
    require __DIR__.'/web/sales.php';
    require __DIR__.'/web/admin.php';
    require __DIR__.'/web/reports.php';
    require __DIR__.'/web/approvals.php';
    require __DIR__.'/web/cycle-counts.php';
});

require __DIR__.'/auth.php';

// Pages registered by active plugins with register_page(). Added last so a
// plugin can never shadow a core route.
PluginPageRoutes::register();
