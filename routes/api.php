<?php
// routes/api.php

use App\Http\Controllers\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\UserController;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Route đăng nhập không cần xác thực
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout']);
// amdin

Route::prefix('admin')->group(function () {
    Route::get('dashboard', [DashboardController::class, 'currentMonth']);
    Route::get('dashboard/monthly', [DashboardController::class, 'monthlyOne']); // thêm
    Route::get('dashboard/monthly-report', [DashboardController::class, 'monthlyReport']);
    Route::get('dashboard/yearly-report', [DashboardController::class, 'yearlyReport']);
});

Route::get('/admin/categories', [AdminCategoryController::class, 'index']);
Route::post('/admin/categories', [AdminCategoryController::class, 'store']);
Route::get('/admin/categories/{category}', [AdminCategoryController::class, 'show']);
Route::put('/admin/categories/{category}', [AdminCategoryController::class, 'update']);
Route::delete('/admin/categories/{category}', [AdminCategoryController::class, 'destroy']);

Route::get('/admin/products', [ProductController::class, 'index']);
Route::post('/admin/products', [ProductController::class, 'store']);
Route::put('/admin/products/{product}', [ProductController::class, 'update']);
Route::delete('/admin/products/{product}', [ProductController::class, 'destroy']);

//staff
Route::post('/staff/orders', [OrderController::class, 'store']);
// Group các route cần xác thực
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Luồng Admin: Chỉ tài khoản có role là 'admin' mới được truy cập
    Route::middleware('role:admin')->group(function () {
        Route::resource('users', AdminUserController::class);
        // ... Thêm các route quản lý khác chỉ dành cho admin tại đây
    });

    // Luồng Staff: Mọi tài khoản đã đăng nhập đều có thể truy cập
    Route::get('/products', [ProductController::class, 'index']);
    // ... Thêm các route chung cho staff tại đây
});
?>
