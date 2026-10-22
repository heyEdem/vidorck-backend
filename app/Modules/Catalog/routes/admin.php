<?php

use App\Modules\Catalog\Http\Controllers\Admin\BundleController;
use App\Modules\Catalog\Http\Controllers\Admin\CategoryController;
use App\Modules\Catalog\Http\Controllers\Admin\HairTypeController;
use App\Modules\Catalog\Http\Controllers\Admin\ImageController;
use App\Modules\Catalog\Http\Controllers\Admin\ProductController;
use App\Modules\Catalog\Http\Controllers\Admin\VariantController;
use Illuminate\Support\Facades\Route;

Route::get('products', [ProductController::class, 'index'])->middleware('can:view-catalogue');
Route::post('products', [ProductController::class, 'store'])->middleware('can:manage-catalogue');
Route::get('products/{product}', [ProductController::class, 'show'])->middleware('can:view-catalogue');
Route::patch('products/{product}', [ProductController::class, 'update'])->middleware('can:manage-catalogue');
Route::post('products/{product}/publish', [ProductController::class, 'publish'])->middleware('can:publish-catalogue');
Route::post('products/{product}/unpublish', [ProductController::class, 'unpublish'])->middleware('can:publish-catalogue');
Route::get('products/{product}/variants', [VariantController::class, 'index'])->middleware('can:view-catalogue');
Route::post('products/{product}/variants', [VariantController::class, 'store'])->middleware('can:manage-catalogue');
Route::patch('products/{product}/variants/{variant}', [VariantController::class, 'update'])->middleware('can:manage-catalogue');
Route::post('products/{product}/image', [ImageController::class, 'product'])->middleware('can:manage-images');

Route::get('categories', [CategoryController::class, 'index'])->middleware('can:view-catalogue');
Route::post('categories', [CategoryController::class, 'store'])->middleware('can:manage-catalogue');
Route::patch('categories/{category}', [CategoryController::class, 'update'])->middleware('can:manage-catalogue');
Route::get('hair-types', [HairTypeController::class, 'index'])->middleware('can:view-catalogue');

Route::get('bundles', [BundleController::class, 'index'])->middleware('can:view-catalogue');
Route::post('bundles', [BundleController::class, 'store'])->middleware('can:manage-catalogue');
Route::get('bundles/{bundle}', [BundleController::class, 'show'])->middleware('can:view-catalogue');
Route::patch('bundles/{bundle}', [BundleController::class, 'update'])->middleware('can:manage-catalogue');
Route::post('bundles/{bundle}/publish', [BundleController::class, 'publish'])->middleware('can:publish-catalogue');
Route::post('bundles/{bundle}/unpublish', [BundleController::class, 'unpublish'])->middleware('can:publish-catalogue');
Route::post('bundles/{bundle}/image', [ImageController::class, 'bundle'])->middleware('can:manage-images');
