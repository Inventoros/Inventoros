<?php

declare(strict_types=1);

use App\Http\Controllers\Reports\AnalyticsReportController;
use App\Http\Controllers\Reports\ReportBuilderController;
use App\Http\Controllers\Reports\ReportController;
use App\Http\Controllers\Reports\ReportScheduleController;
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
    // Outstanding balances: a report (view_reports, from the group) built
    // from payments, so it also needs view_payments.
    Route::get('/receivables', [ReportController::class, 'receivables'])->name('receivables')->middleware('permission:view_payments');

    // Analytics reports. Each also needs the view permission of every data
    // source it reads (the report builder's per-source rule): products for
    // stock and cost, orders for sales. ?export=csv|xlsx|pdf downloads.
    Route::get('/dead-stock', [AnalyticsReportController::class, 'deadStock'])
        ->middleware('permission:view_products|view_orders,all')->name('dead-stock');
    Route::get('/inventory-turnover', [AnalyticsReportController::class, 'inventoryTurnover'])
        ->middleware('permission:view_products|view_orders,all')->name('inventory-turnover');
    Route::get('/profit-margin', [AnalyticsReportController::class, 'profitMargin'])
        ->middleware('permission:view_products|view_orders,all')->name('profit-margin');
    Route::get('/sales-by-location', [AnalyticsReportController::class, 'salesByLocation'])
        ->middleware('permission:view_orders')->name('sales-by-location');
    Route::get('/abc-analysis', [AnalyticsReportController::class, 'abcAnalysis'])
        ->middleware('permission:view_orders')->name('abc-analysis');

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

        // Scheduled email delivery (report owner only; see ReportScheduleController).
        Route::post('/{saved_report}/schedules', [ReportScheduleController::class, 'store'])->name('schedules.store');
        Route::put('/{saved_report}/schedules/{schedule}', [ReportScheduleController::class, 'update'])->name('schedules.update');
        Route::delete('/{saved_report}/schedules/{schedule}', [ReportScheduleController::class, 'destroy'])->name('schedules.destroy');
    });
});
