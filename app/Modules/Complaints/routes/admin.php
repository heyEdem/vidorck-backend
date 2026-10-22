<?php

use App\Modules\Complaints\Http\Controllers\Admin\ComplaintController;
use Illuminate\Support\Facades\Route;

Route::get('complaints', [ComplaintController::class, 'index'])->middleware('can:view-complaints');
Route::get('complaints/{reference}', [ComplaintController::class, 'show'])->middleware('can:view-complaints');
Route::post('complaints/{reference}/claim', [ComplaintController::class, 'claim'])->middleware('can:handle-complaints');
Route::post('complaints/{reference}/notes', [ComplaintController::class, 'note'])->middleware('can:handle-complaints');
Route::post('complaints/{reference}/status', [ComplaintController::class, 'status'])->middleware('can:handle-complaints');
