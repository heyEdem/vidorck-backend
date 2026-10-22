<?php

use App\Modules\Orders\Http\Controllers\CancellationRequestController;
use App\Modules\Orders\Http\Controllers\Public\TrackingController;
use Illuminate\Support\Facades\Route;

Route::post('orders/cancellation-requests', [CancellationRequestController::class, 'store'])
    ->middleware('throttle:cancellation-requests');
Route::post('orders/track', [TrackingController::class, 'track'])->middleware('throttle:order-tracking');
Route::get('orders/confirmation/{trackingToken}', [TrackingController::class, 'confirmation'])
    ->middleware('throttle:order-confirmation');
