<?php

use App\Modules\Catalog\Http\Controllers\Public\BundleController;
use App\Modules\Catalog\Http\Controllers\Public\CategoryController;
use App\Modules\Catalog\Http\Controllers\Public\HairTypeController;
use App\Modules\Catalog\Http\Controllers\Public\ProductController;
use Illuminate\Support\Facades\Route;

Route::get('products', [ProductController::class, 'index']);
Route::get('products/{slug}', [ProductController::class, 'show']);
Route::get('bundles', [BundleController::class, 'index']);
Route::get('bundles/{slug}', [BundleController::class, 'show']);
Route::get('categories', [CategoryController::class, 'index']);
Route::get('hair-types', [HairTypeController::class, 'index']);
