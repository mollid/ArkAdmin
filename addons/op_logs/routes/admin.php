<?php

use Addons\op_logs\Http\Controllers\OpLogController;
use Illuminate\Support\Facades\Route;

// 相对路径，由 AddonServiceProvider 基类包上 api/admin/addon/op_logs 前缀与 auth:admin
Route::get('logs', [OpLogController::class, 'index'])->middleware('permission:addon.op_logs.index');
Route::get('today', [OpLogController::class, 'today'])->middleware('permission:addon.op_logs.index');
