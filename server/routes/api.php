<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

/*
 * 管理端路由组：统一挂载 /api/admin 前缀。
 * 登录/鉴权于 Task 8 重写（Sanctum + spatie-permission）。
 */
Route::prefix('admin')->group(function () {
    // placeholder: Task 4 探针路由与 Task 8 管理端路由在此注册
});
