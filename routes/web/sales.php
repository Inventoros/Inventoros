<?php

declare(strict_types=1);

use App\Http\Controllers\CustomerController;
use App\Http\Controllers\Inventory\SupplierController;
use App\Http\Controllers\Order\InvoiceController;
use App\Http\Controllers\Order\OrderController;
use App\Http\Controllers\Order\ReturnOrderController;
use App\Http\Controllers\Purchasing\PurchaseOrderController;
use App\Http\Controllers\Purchasing\PurchaseOrderInvoiceController;
use Illuminate\Support\Facades\Route;

/*
 * Suppliers, customers, purchasing, orders, and returns. Loaded inside the
 * `auth` group in routes/web.php.
 */

// Supplier Management - Permission based
Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index')->middleware('permission:view_suppliers');
Route::get('/suppliers/create', [SupplierController::class, 'create'])->name('suppliers.create')->middleware('permission:create_suppliers');
Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store')->middleware('permission:create_suppliers');
Route::get('/suppliers/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show')->middleware('permission:view_suppliers');
Route::get('/suppliers/{supplier}/edit', [SupplierController::class, 'edit'])->name('suppliers.edit')->middleware('permission:edit_suppliers');
Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update')->middleware('permission:edit_suppliers');
Route::patch('/suppliers/{supplier}', [SupplierController::class, 'update'])->middleware('permission:edit_suppliers');
Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('suppliers.destroy')->middleware('permission:delete_suppliers');

// Customer Management - Permission based
Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index')->middleware('permission:view_customers');
Route::get('/customers/create', [CustomerController::class, 'create'])->name('customers.create')->middleware('permission:create_customers');
Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store')->middleware('permission:create_customers');
Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show')->middleware('permission:view_customers');
Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit')->middleware('permission:edit_customers');
Route::put('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update')->middleware('permission:edit_customers');
Route::patch('/customers/{customer}', [CustomerController::class, 'update'])->middleware('permission:edit_customers');
Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy')->middleware('permission:delete_customers');

// Purchase Order Management - Permission based
Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index')->middleware('permission:view_purchase_orders');
Route::get('/purchase-orders/create', [PurchaseOrderController::class, 'create'])->name('purchase-orders.create')->middleware('permission:create_purchase_orders');
Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase-orders.store')->middleware('permission:create_purchase_orders');
Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->name('purchase-orders.show')->middleware('permission:view_purchase_orders');
Route::get('/purchase-orders/{purchaseOrder}/edit', [PurchaseOrderController::class, 'edit'])->name('purchase-orders.edit')->middleware('permission:edit_purchase_orders');
Route::put('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->name('purchase-orders.update')->middleware('permission:edit_purchase_orders');
Route::patch('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->middleware('permission:edit_purchase_orders');
Route::delete('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy'])->name('purchase-orders.destroy')->middleware('permission:delete_purchase_orders');
Route::get('/purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive'])->name('purchase-orders.receive')->middleware('permission:receive_purchase_orders');
Route::post('/purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'processReceiving'])->name('purchase-orders.process-receiving')->middleware('permission:receive_purchase_orders');
Route::post('/purchase-orders/{purchaseOrder}/send', [PurchaseOrderController::class, 'sendToSupplier'])->name('purchase-orders.send')->middleware('permission:edit_purchase_orders');
Route::post('/purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel')->middleware('permission:edit_purchase_orders');

// Purchase Order Invoice PDF
Route::get('/purchase-orders/{purchaseOrder}/invoice/download', [PurchaseOrderInvoiceController::class, 'download'])->name('purchase-orders.invoice.download')->middleware('permission:view_purchase_orders');
Route::get('/purchase-orders/{purchaseOrder}/invoice/preview', [PurchaseOrderInvoiceController::class, 'preview'])->name('purchase-orders.invoice.preview')->middleware('permission:view_purchase_orders');

// Order Management - Permission based
Route::get('/orders', [OrderController::class, 'index'])->name('orders.index')->middleware('permission:view_orders');
Route::get('/orders/create', [OrderController::class, 'create'])->name('orders.create')->middleware('permission:create_orders');
Route::post('/orders', [OrderController::class, 'store'])->name('orders.store')->middleware('permission:create_orders');
Route::get('/orders/{order}/edit', [OrderController::class, 'edit'])->name('orders.edit')->middleware('permission:edit_orders');
Route::put('/orders/{order}', [OrderController::class, 'update'])->name('orders.update')->middleware('permission:edit_orders');
Route::patch('/orders/{order}', [OrderController::class, 'update'])->middleware('permission:edit_orders');
Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show')->middleware('permission:view_orders');
Route::delete('/orders/{order}', [OrderController::class, 'destroy'])->name('orders.destroy')->middleware('permission:delete_orders');
Route::post('/orders/{order}/approve', [OrderController::class, 'approve'])->name('orders.approve')->middleware('permission:approve_orders');
Route::post('/orders/{order}/reject', [OrderController::class, 'reject'])->name('orders.reject')->middleware('permission:approve_orders');

// Order Invoice PDF
Route::get('/orders/{order}/invoice/download', [InvoiceController::class, 'download'])->name('orders.invoice.download')->middleware('permission:view_orders');
Route::get('/orders/{order}/invoice/preview', [InvoiceController::class, 'preview'])->name('orders.invoice.preview')->middleware('permission:view_orders');

// Return Orders (RMA) - Permission based
Route::get('/returns', [ReturnOrderController::class, 'index'])->name('returns.index')->middleware('permission:manage_returns');
Route::get('/returns/create', [ReturnOrderController::class, 'create'])->name('returns.create')->middleware('permission:manage_returns');
Route::post('/returns', [ReturnOrderController::class, 'store'])->name('returns.store')->middleware('permission:manage_returns');
Route::get('/returns/{returnOrder}', [ReturnOrderController::class, 'show'])->name('returns.show')->middleware('permission:manage_returns');
Route::post('/returns/{returnOrder}/approve', [ReturnOrderController::class, 'approve'])->name('returns.approve')->middleware('permission:manage_returns');
Route::post('/returns/{returnOrder}/receive', [ReturnOrderController::class, 'receive'])->name('returns.receive')->middleware('permission:manage_returns');
Route::post('/returns/{returnOrder}/complete', [ReturnOrderController::class, 'complete'])->name('returns.complete')->middleware('permission:manage_returns');
Route::post('/returns/{returnOrder}/reject', [ReturnOrderController::class, 'reject'])->name('returns.reject')->middleware('permission:manage_returns');
