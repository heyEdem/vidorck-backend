<?php

use App\Modules\Staff\Http\Controllers\AuditLogController;
use App\Modules\Staff\Http\Controllers\AuthController;
use App\Modules\Staff\Http\Controllers\StaffController;
use App\Modules\Staff\Http\Middleware\EnsureStaffSession;
use App\Modules\Staff\Http\Middleware\LoginThrottle;
use App\Modules\Staff\Http\Middleware\RequirePasswordChange;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', [AuthController::class, 'login'])->name('admin.auth.login')
    ->withoutMiddleware(['auth:sanctum', EnsureStaffSession::class, RequirePasswordChange::class])
    ->middleware(LoginThrottle::class);
Route::get('auth/me', [AuthController::class, 'me'])->name('admin.auth.me');
Route::post('auth/logout', [AuthController::class, 'logout'])->name('admin.auth.logout');
Route::post('auth/password', [AuthController::class, 'password'])->name('admin.auth.password')->middleware('throttle:staff-password-change');

Route::get('staff', [StaffController::class, 'index']);
Route::post('staff', [StaffController::class, 'store']);
Route::patch('staff/{staff}', [StaffController::class, 'update']);
Route::post('staff/{staff}/reset-password', [StaffController::class, 'resetPassword'])
    ->middleware(['can:manage-staff', 'throttle:staff-password-reset']);
Route::get('audit-logs', [AuditLogController::class, 'index']);

// Staff admin routes are implemented in the module feature phase.
