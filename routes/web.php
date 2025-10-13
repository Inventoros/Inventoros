<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Install\InstallerController;
use App\Http\Controllers\ProductController;
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

    // Products
    Route::resource('products', ProductController::class);
});

require __DIR__.'/auth.php';
