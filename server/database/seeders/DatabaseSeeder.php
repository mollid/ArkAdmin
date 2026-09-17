<?php

namespace Database\Seeders;

use App\Support\Setup\FrameworkSync;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** 委托 FrameworkSync（与 ark:sync 共用）：权限 + 菜单 + 捆绑系统插件，全幂等 */
    public function run(): void
    {
        $result = app(FrameworkSync::class)->run();
        foreach ($result['installed'] as $addon) {
            $this->command?->info("系统插件 [{$addon}] 已安装");
        }
        foreach ($result['skipped'] as $addon => $reason) {
            $this->command?->line("系统插件 [{$addon}] 跳过：{$reason}");
        }
    }
}
