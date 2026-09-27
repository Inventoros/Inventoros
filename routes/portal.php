<?php

declare(strict_types=1);

use App\Http\Controllers\Portal\Auth\PortalInvitationController;
use App\Http\Controllers\Portal\Auth\PortalLoginController;
use App\Http\Controllers\Portal\Auth\PortalPasswordResetController;
use App\Http\Controllers\Portal\PortalDashboardController;
use App\Http\Controllers\Portal\PortalOrderController;
use App\Http\Controllers\Portal\PortalReturnController;
use Illuminate\Support\Facades\Route;

/*
 * Customer portal routes. Registered in bootstrap/app.php under the `portal`
 * middleware group with the prefix /portal/{organization} and the name
 * prefix `portal.`. The group resolves {organization} (404 when unknown or
 * the portal is off) and switches the default guard to `customer`.
 */

Route::middleware('portal.guest')->group(function () {
    Route::get('login', [PortalLoginController::class, 'create'])->name('login');
    Route::post('login', [PortalLoginController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('login.store');

    Route::get('forgot-password', [PortalPasswordResetController::class, 'request'])->name('password.request');
    Route::post('forgot-password', [PortalPasswordResetController::class, 'email'])
        ->middleware('throttle:5,1')
        ->name('password.email');
    Route::get('reset-password/{token}', [PortalPasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('reset-password', [PortalPasswordResetController::class, 'update'])
        ->middleware('throttle:5,1')
        ->name('password.store');
});

// Invitation links are signed and expiring; the controller checks the
// signature itself so it can refuse with a 403 on both GET and POST.
Route::get('invitation/{contact}', [PortalInvitationController::class, 'show'])
    ->whereNumber('contact')
    ->middleware('throttle:30,1')
    ->name('invitation.show');
Route::post('invitation/{contact}', [PortalInvitationController::class, 'accept'])
    ->whereNumber('contact')
    ->middleware('throttle:10,1')
    ->name('invitation.accept');

Route::middleware('portal.auth')->group(function () {
    Route::get('/', PortalDashboardController::class)->name('dashboard');

    Route::get('orders', [PortalOrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{order}', [PortalOrderController::class, 'show'])->whereNumber('order')->name('orders.show');
    Route::get('orders/{order}/invoice', [PortalOrderController::class, 'invoice'])->whereNumber('order')->name('orders.invoice');

    Route::get('orders/{order}/return', [PortalReturnController::class, 'create'])->whereNumber('order')->name('returns.create');
    Route::post('orders/{order}/return', [PortalReturnController::class, 'store'])
        ->whereNumber('order')
        ->middleware('throttle:10,1')
        ->name('returns.store');
    Route::get('returns', [PortalReturnController::class, 'index'])->name('returns.index');
    Route::get('returns/{returnOrder}', [PortalReturnController::class, 'show'])->whereNumber('returnOrder')->name('returns.show');

    Route::post('logout', [PortalLoginController::class, 'destroy'])->name('logout');
});
