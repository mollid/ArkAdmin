<?php

use App\Admin\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth:admin')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::delete('auth/logout', [AuthController::class, 'logout']);
    });
});
