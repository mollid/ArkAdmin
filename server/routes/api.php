<?php

use App\Admin\Http\Controllers\AdminController;
use App\Admin\Http\Controllers\AuthController;
use App\Admin\Http\Controllers\MenuController;
use App\Admin\Http\Controllers\RoleController;
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

        Route::get('roles', [RoleController::class, 'index'])->middleware('permission:system.role.index');
        Route::get('roles/permissions', [RoleController::class, 'permissions'])->middleware('permission:system.role.index');
        Route::post('roles', [RoleController::class, 'store'])->middleware('permission:system.role.store');
        Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('permission:system.role.update');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:system.role.destroy');

        Route::get('menus', [MenuController::class, 'index'])->middleware('permission:system.menu.index');
        Route::post('menus', [MenuController::class, 'store'])->middleware('permission:system.menu.store');
        Route::put('menus/{menu}', [MenuController::class, 'update'])->middleware('permission:system.menu.update');
        Route::delete('menus/{menu}', [MenuController::class, 'destroy'])->middleware('permission:system.menu.destroy');
    });
});
