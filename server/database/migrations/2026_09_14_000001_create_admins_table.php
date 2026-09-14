<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $t) {
            $t->id();
            $t->string('username', 64)->unique();
            $t->string('name', 64)->default('');
            $t->string('password');
            $t->unsignedSmallInteger('status')->default(1); // 1启用 0禁用
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admins');
    }
};
