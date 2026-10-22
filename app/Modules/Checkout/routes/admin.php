<?php

use App\Modules\Checkout\Http\Controllers\Admin\DeliveryAreaController;
use App\Modules\Checkout\Http\Controllers\Admin\ReconciliationController;
use Illuminate\Support\Facades\Route;

Route::get('delivery-areas', [DeliveryAreaController::class, 'index'])->middleware('can:view-catalogue');
Route::post('delivery-areas', [DeliveryAreaController::class, 'store'])->middleware('can:manage-catalogue');
Route::patch('delivery-areas/{deliveryArea}', [DeliveryAreaController::class, 'update'])->middleware('can:manage-catalogue');
Route::get('payments/reconciliation', [ReconciliationController::class, 'index'])->middleware('can:view-reconciliation');
