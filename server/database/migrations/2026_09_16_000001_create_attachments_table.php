<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// §5.4 素材库（框架级）：CMS 等插件消费，插件自身不建附件表
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);                        // 原始文件名
            $table->string('path', 191)->index();               // 相对 disk 的存储路径（插件引用存它）
            $table->string('disk', 32)->default('public');
            $table->string('mime', 128)->default('');
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('uploader_type', 32)->default('admin')->index();
            $table->unsignedBigInteger('uploader_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
