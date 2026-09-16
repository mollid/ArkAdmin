<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 插件迁移用匿名类：addons/ 不在 composer classmap，避免与框架迁移类名冲突
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_id')->default(0)->index();
            $table->string('name', 64);
            $table->string('description', 255)->default('');
            $table->integer('sort')->default(0);
            $table->boolean('is_show')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_categories');
    }
};
