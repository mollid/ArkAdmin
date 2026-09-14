<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menus', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('parent_id')->default(0)->index();
            $t->string('name', 64)->unique();
            $t->string('title', 64);
            $t->string('icon', 64)->default('');
            $t->string('route_path', 191)->default('');
            $t->string('view_path', 191)->default('');
            $t->string('permission', 191)->default('')->index();
            $t->string('addon_key', 64)->default('')->index();
            $t->integer('sort')->default(0);
            $t->boolean('is_show')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menus');
    }
};
