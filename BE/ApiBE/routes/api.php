<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\BlogController;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\SitemapController;

// Đăng nhập
Route::post('/login', [AuthController::class, 'login']);
///sitemap
Route::get('/sitemap.xml', [SitemapController::class, 'index']);
// API công khai: khách có thể xem tranh, danh mục và bài viết.
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{identifier}', [ProductController::class, 'show']);
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/{category}', [CategoryController::class, 'show']);
Route::get('/blogs', [BlogController::class, 'index']);
Route::get('/blogs/{identifier}', [BlogController::class, 'show']);

// API yêu cầu đăng nhập.
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Chỉ Admin được quản lý nội dung và tải ảnh trong bài viết.
    Route::middleware(AdminMiddleware::class)->group(function () {
        Route::post('/blog-content-images', [BlogController::class, 'uploadContentImage']);

        Route::apiResource('products', ProductController::class)
            ->except(['index', 'show']);

        Route::apiResource('blogs', BlogController::class)
            ->except(['index', 'show']);

        Route::apiResource('categories', CategoryController::class)
            ->except(['index', 'show']);
    });
});
