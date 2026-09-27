<?php

use App\Http\Controllers\Inventory\CycleCountScheduleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Scheduled cycle counts
|--------------------------------------------------------------------------
|
| Managed from Stock Audits. Kept under /cycle-counts rather than
| /stock-audits/... so the paths cannot collide with /stock-audits/{id}.
|
*/

Route::get('/cycle-counts', [CycleCountScheduleController::class, 'index'])->name('cycle-counts.index')->middleware('permission:view_stock_audits');
Route::get('/cycle-counts/create', [CycleCountScheduleController::class, 'create'])->name('cycle-counts.create')->middleware('permission:manage_stock_audits');
Route::post('/cycle-counts', [CycleCountScheduleController::class, 'store'])->name('cycle-counts.store')->middleware('permission:manage_stock_audits');
Route::get('/cycle-counts/{cycleCount}/edit', [CycleCountScheduleController::class, 'edit'])->name('cycle-counts.edit')->middleware('permission:manage_stock_audits');
Route::put('/cycle-counts/{cycleCount}', [CycleCountScheduleController::class, 'update'])->name('cycle-counts.update')->middleware('permission:manage_stock_audits');
Route::delete('/cycle-counts/{cycleCount}', [CycleCountScheduleController::class, 'destroy'])->name('cycle-counts.destroy')->middleware('permission:manage_stock_audits');
Route::post('/cycle-counts/{cycleCount}/run', [CycleCountScheduleController::class, 'run'])->name('cycle-counts.run')->middleware('permission:manage_stock_audits');
