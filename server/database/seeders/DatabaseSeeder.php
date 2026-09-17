<?php

namespace Database\Seeders;

use App\Admin\Seeds\MenuSeeder;
use App\Admin\Seeds\RbacSeeder;
use App\Support\Addon\AddonException;
use App\Support\Addon\AddonInstaller;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    // 两个 seeder 均为幂等 upsert/firstOrCreate，可安全重复执行
    public function run(): void
    {
        (new RbacSeeder)->run();
        (new MenuSeeder)->run();
        $this->installSystemAddons();
    }

    /** 捆绑系统插件（config arkadmin.system_addons）随种子自动安装：幂等，已装跳过 */
    protected function installSystemAddons(): void
    {
        $installer = app(AddonInstaller::class);
        foreach ((array) config('arkadmin.system_addons', ['settings', 'op_logs']) as $addon) {
            try {
                $installer->install((string) $addon);
                $this->command?->info("系统插件 [{$addon}] 已安装");
            } catch (AddonException $e) {
                $this->command?->line("系统插件 [{$addon}] 跳过：".$e->getMessage());
            }
        }
    }
}
