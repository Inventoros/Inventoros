<?php

declare(strict_types=1);

use App\Http\Controllers\Reports\ReportBuilderController;
use App\Http\Controllers\Reports\ReportController;
use Illuminate\Support\Facades\Route;

/*
 * Reports + custom report builder. Loaded inside the `auth` group in
 * routes/web.php.
 */

Route::prefix('reports')->name('reports.')->middleware('permission:view_reports')->group(function () {
    Route::get('/', [ReportController::class, 'index'])->name('index');
    Route::get('/inventory-valuation', [ReportController::class, 'inventoryValuation'])->name('inventory-valuation');
    Route::get('/stock-movement', [ReportController::class, 'stockMovement'])->name('stock-movement');
    Route::get('/sales-analysis', [ReportController::class, 'salesAnalysis'])->name('sales-analysis');
    Route::get('/low-stock', [ReportController::class, 'lowStock'])->name('low-stock');
    Route::get('/category-performance', [ReportController::class, 'categoryPerformance'])->name('category-performance');

    // Custom Report Builder
    Route::prefix('builder')->name('builder.')->group(function () {
        Route::get('/', [ReportBuilderController::class, 'index'])->name('index');
        Route::get('/create', [ReportBuilderController::class, 'create'])->name('create');
        Route::post('/', [ReportBuilderController::class, 'store'])->name('store');
        Route::post('/preview', [ReportBuilderController::class, 'preview'])->name('preview');
        Route::get('/{saved_report}', [ReportBuilderController::class, 'show'])->name('show');
        Route::get('/{saved_report}/edit', [ReportBuilderController::class, 'edit'])->name('edit');
        Route::put('/{saved_report}', [ReportBuilderController::class, 'update'])->name('update');
        Route::delete('/{saved_report}', [ReportBuilderController::class, 'destroy'])->name('destroy');
        Route::get('/{saved_report}/export', [ReportBuilderController::class, 'export'])->name('export');
    });
});
