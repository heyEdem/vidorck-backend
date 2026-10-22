<?php

use App\Modules\Customers\Http\Controllers\Admin\CustomerController;
use Illuminate\Support\Facades\Route;

// Both roles (product-spec §6.8). Notes are append-only.
Route::get('customers', [CustomerController::class, 'index'])->middleware('can:view-customers');
Route::get('customers/{publicId}', [CustomerController::class, 'show'])->middleware('can:view-customers');
Route::post('customers/{publicId}/notes', [CustomerController::class, 'note'])->middleware('can:add-customer-notes');
