<?php

namespace Database\Seeders;

use App\Admin\Seeds\MenuSeeder;
use App\Admin\Seeds\RbacSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    // 两个 seeder 均为幂等 upsert/firstOrCreate，可安全重复执行
    public function run(): void
    {
        (new RbacSeeder)->run();
        (new MenuSeeder)->run();
    }
}
