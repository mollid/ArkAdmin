<?php

use Addons\cms\Http\Controllers\ArticleController;
use Addons\cms\Http\Controllers\CategoryController;
use Illuminate\Support\Facades\Route;

// 相对路径，由 AddonServiceProvider 基类包上 api/admin/addon/cms 前缀与 auth:admin
Route::get('categories', [CategoryController::class, 'index'])->middleware('permission:addon.cms.category.index');
Route::post('categories', [CategoryController::class, 'store'])->middleware('permission:addon.cms.category.store');
Route::put('categories/{id}', [CategoryController::class, 'update'])->middleware('permission:addon.cms.category.update');
Route::delete('categories/{id}', [CategoryController::class, 'destroy'])->middleware('permission:addon.cms.category.destroy');

Route::get('articles', [ArticleController::class, 'index'])->middleware('permission:addon.cms.article.index');
Route::get('articles/{id}', [ArticleController::class, 'show'])->middleware('permission:addon.cms.article.index');
Route::post('articles', [ArticleController::class, 'store'])->middleware('permission:addon.cms.article.store');
Route::put('articles/{id}', [ArticleController::class, 'update'])->middleware('permission:addon.cms.article.update');
Route::delete('articles/{id}', [ArticleController::class, 'destroy'])->middleware('permission:addon.cms.article.destroy');

// ark:crud:cms_notices:start
Route::get('notices', [\Addons\cms\Http\Controllers\NoticeController::class, 'index'])->middleware('permission:addon.cms.notice.index');
Route::get('notices/{id}', [\Addons\cms\Http\Controllers\NoticeController::class, 'show'])->middleware('permission:addon.cms.notice.index');
Route::post('notices', [\Addons\cms\Http\Controllers\NoticeController::class, 'store'])->middleware('permission:addon.cms.notice.store');
Route::put('notices/{id}', [\Addons\cms\Http\Controllers\NoticeController::class, 'update'])->middleware('permission:addon.cms.notice.update');
Route::delete('notices/{id}', [\Addons\cms\Http\Controllers\NoticeController::class, 'destroy'])->middleware('permission:addon.cms.notice.destroy');
// ark:crud:cms_notices:end
