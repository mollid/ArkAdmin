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
