<?php

use App\Http\Controllers\ApprovalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Approvals
|--------------------------------------------------------------------------
|
| The pending approvals queue, and approve / reject for purchase orders,
| stock adjustments and stock transfers. {type} is purchase_order,
| stock_adjustment or stock_transfer. The page is open to every signed-in
| user (it also lists their own requests); who may decide is checked per
| type by ApprovalService.
|
*/

Route::get('/approvals', [ApprovalController::class, 'index'])->name('approvals.index');
Route::post('/approvals/{type}/{id}/approve', [ApprovalController::class, 'approve'])
    ->whereIn('type', \App\Services\ApprovalService::TYPES)->whereNumber('id')
    ->name('approvals.approve');
Route::post('/approvals/{type}/{id}/reject', [ApprovalController::class, 'reject'])
    ->whereIn('type', \App\Services\ApprovalService::TYPES)->whereNumber('id')
    ->name('approvals.reject');
