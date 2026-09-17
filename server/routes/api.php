<?php

use App\Admin\Http\Controllers\AddonController;
use App\Admin\Http\Controllers\AdminController;
use App\Admin\Http\Controllers\AttachmentController;
use App\Admin\Http\Controllers\AuthController;
use App\Admin\Http\Controllers\MenuController;
use App\Admin\Http\Controllers\RoleController;
use App\Admin\Http\Controllers\SettingController;
use App\Admin\Http\Controllers\WidgetController;
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

        // §5.4 素材库（框架级）
        Route::get('attachments', [AttachmentController::class, 'index'])->middleware('permission:system.attachment.index');
        Route::post('attachments', [AttachmentController::class, 'store'])->middleware('permission:system.attachment.store');
        Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy'])->middleware('permission:system.attachment.destroy');

        // M6 harness：settings 基建（管理页在 settings 系统插件）与 widget 清单
        Route::get('settings', [SettingController::class, 'index'])->middleware('permission:system.setting.index');
        Route::put('settings', [SettingController::class, 'update'])->middleware('permission:system.setting.update');
        Route::get('widgets', [WidgetController::class, 'index']);

        // M7 插件管理（harness §4.1）；单数 addon/<key> 为插件动态路由，与此处复数不冲突
        Route::get('addons', [AddonController::class, 'index'])->middleware('permission:system.addon.index');
        Route::post('addons', [AddonController::class, 'store'])->middleware('permission:system.addon.store');
        Route::put('addons/{addon}', [AddonController::class, 'update'])->middleware('permission:system.addon.update');
        Route::delete('addons/{addon}', [AddonController::class, 'destroy'])->middleware('permission:system.addon.destroy');
    });
});
