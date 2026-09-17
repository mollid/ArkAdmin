<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_op_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id')->nullable()->index();   // 登录失败/未登录为 null
            $table->string('route', 191);
            $table->string('method', 10);
            $table->jsonb('params');
            $table->smallInteger('status_code');
            $table->float('duration_ms')->default(0);
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_op_logs');
    }
};
