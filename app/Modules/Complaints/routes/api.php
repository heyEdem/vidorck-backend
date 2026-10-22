<?php

use App\Modules\Complaints\Http\Controllers\PublicComplaintController;
use Illuminate\Support\Facades\Route;

Route::post('complaints', [PublicComplaintController::class, 'store'])->middleware('throttle:contact-submissions');
