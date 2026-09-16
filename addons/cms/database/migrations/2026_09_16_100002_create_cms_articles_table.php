<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_articles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id')->default(0)->index();
            $table->string('title', 191);
            $table->string('summary', 255)->default('');
            $table->text('content')->default('');           // 富文本 HTML
            $table->string('cover', 191)->default('');      // 封面 = attachments.path（§5.4 素材库）
            // §5.4：多值字段用 PG 原生类型（jsonb），禁止逗号分隔字符串
            $table->jsonb('tags')->default('[]');
            $table->smallInteger('status')->default(0);     // 0 草稿 / 1 已发布
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_articles');
    }
};
