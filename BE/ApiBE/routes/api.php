<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\BlogController;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Controllers\Api\CategoryController;
// Đăng ký và đăng nhập

Route::post('/login', [AuthController::class, 'login']);

// API công khai: xem sản phẩm và blog
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{product}', [ProductController::class, 'show']);
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/{category}', [CategoryController::class, 'show']);
Route::get('/blogs', [BlogController::class, 'index']);
Route::get('/blogs/{blog}', [BlogController::class, 'show']);

// API yêu cầu đăng nhập
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Chỉ admin được thêm, sửa, xóa
    Route::middleware(AdminMiddleware::class)->group(function () {
    Route::apiResource('products', ProductController::class)
        ->except(['index', 'show']);

    Route::apiResource('blogs', BlogController::class)
        ->except(['index', 'show']);

    // Quản lý danh mục: chỉ admin
    Route::apiResource('categories', CategoryController::class)
        ->except(['index', 'show']);
});
});