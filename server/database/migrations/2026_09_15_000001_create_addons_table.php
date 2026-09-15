<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addons', function (Blueprint $table) {
            $table->string('name', 64)->primary();
            $table->string('title')->default('');
            $table->string('version', 32)->default('');
            $table->jsonb('config')->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamp('install_time')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addons');
    }
};
