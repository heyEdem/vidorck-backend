<?php

use App\Modules\Checkout\Http\Controllers\CheckoutStatusController;
use App\Modules\Checkout\Http\Controllers\PaystackWebhookController;
use App\Modules\Checkout\Http\Controllers\Public\CheckoutController;
use App\Modules\Checkout\Http\Controllers\Public\CheckoutRetryController;
use App\Modules\Checkout\Http\Controllers\Public\DeliveryAreaController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

Route::post('checkout', [CheckoutController::class, 'store'])->middleware('throttle:checkout-start');
Route::post('checkout/{reference}/retry', CheckoutRetryController::class)->middleware('throttle:checkout-start');
Route::get('delivery-areas', [DeliveryAreaController::class, 'index']);
Route::get('checkout/{reference}', CheckoutStatusController::class)->middleware('throttle:checkout-status');
Route::post('webhooks/paystack', PaystackWebhookController::class)->withoutMiddleware(EnsureFrontendRequestsAreStateful::class);
