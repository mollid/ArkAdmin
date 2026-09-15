<?php

use Addons\demo\Http\Controllers\NoteController;
use Illuminate\Support\Facades\Route;

// 相对路径，由 AddonServiceProvider 基类包上 api/admin/addon/demo 前缀与 auth:admin
Route::get('notes', [NoteController::class, 'index'])->middleware('permission:addon.demo.note.index');
Route::post('notes', [NoteController::class, 'store'])->middleware('permission:addon.demo.note.store');
