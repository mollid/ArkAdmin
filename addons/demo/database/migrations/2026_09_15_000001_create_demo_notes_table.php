<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 插件迁移用匿名类：addons/ 不在 composer classmap，避免与框架迁移类名冲突
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id');
            $table->text('content');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_notes');
    }
};
