<?php

use App\Modules\Orders\Http\Controllers\Admin\DashboardController;
use App\Modules\Orders\Http\Controllers\Admin\ManualOrderController;
use App\Modules\Orders\Http\Controllers\Admin\OrderController;
use App\Modules\Orders\Http\Controllers\Admin\RefundController;
use Illuminate\Support\Facades\Route;

// Role matrix: product-spec §6.8 / website-spec §17.1. Delivery edit is Admin-only; corrections are Admin-only in the state machine.
Route::get('orders', [OrderController::class, 'index'])->middleware('can:view-orders');
Route::get('orders/{number}', [OrderController::class, 'show'])->middleware('can:view-orders');
Route::post('orders/{number}/transitions', [OrderController::class, 'transition'])->middleware('can:update-order-status');
Route::post('orders/{number}/notes', [OrderController::class, 'note'])->middleware('can:add-order-notes');
Route::patch('orders/{number}/delivery', [OrderController::class, 'delivery'])->middleware('can:edit-order-delivery');
Route::patch('orders/{number}/contents', [OrderController::class, 'contents'])->middleware('can:edit-order-contents');
Route::post('orders/manual', [ManualOrderController::class, 'store'])->middleware('can:create-manual-orders');
Route::get('dashboard', [DashboardController::class, 'show'])->middleware('can:view-dashboard');
Route::get('refunds', [RefundController::class, 'index'])->middleware('can:view-refunds');
Route::get('refunds/{id}', [RefundController::class, 'show'])->whereNumber('id')->middleware('can:view-refunds');
Route::post('refunds/{id}/notes', [RefundController::class, 'note'])->whereNumber('id')->middleware('can:add-refund-notes');
Route::post('refunds/{id}/decision', [RefundController::class, 'decision'])->whereNumber('id')->middleware('can:decide-refunds');
Route::post('refunds/{id}/manual-completion', [RefundController::class, 'manualCompletion'])->whereNumber('id')->middleware('can:process-refunds');
Route::post('refunds/{id}/retry', [RefundController::class, 'retry'])->whereNumber('id')->middleware('can:decide-refunds');
