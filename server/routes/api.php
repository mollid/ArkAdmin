<?php

use App\Admin\Http\Controllers\AdminController;
use App\Admin\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth:admin')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::delete('auth/logout', [AuthController::class, 'logout']);

        Route::get('admins', [AdminController::class, 'index'])->middleware('permission:system.admin.index');
        Route::post('admins', [AdminController::class, 'store'])->middleware('permission:system.admin.store');
        Route::put('admins/{admin}', [AdminController::class, 'update'])->middleware('permission:system.admin.update');
        Route::delete('admins/{admin}', [AdminController::class, 'destroy'])->middleware('permission:system.admin.destroy');
    });
});
