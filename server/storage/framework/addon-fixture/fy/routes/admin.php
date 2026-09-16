<?php

use Illuminate\Support\Facades\Route;

// ark:crud:fy_posts:start
Route::get('posts', [\Addons\fy\Http\Controllers\PostController::class, 'index'])->middleware('permission:addon.fy.post.index');
Route::get('posts/{id}', [\Addons\fy\Http\Controllers\PostController::class, 'show'])->middleware('permission:addon.fy.post.index');
Route::post('posts', [\Addons\fy\Http\Controllers\PostController::class, 'store'])->middleware('permission:addon.fy.post.store');
Route::put('posts/{id}', [\Addons\fy\Http\Controllers\PostController::class, 'update'])->middleware('permission:addon.fy.post.update');
Route::delete('posts/{id}', [\Addons\fy\Http\Controllers\PostController::class, 'destroy'])->middleware('permission:addon.fy.post.destroy');
// ark:crud:fy_posts:end
