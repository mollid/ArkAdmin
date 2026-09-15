<?php

namespace App\Support\Addon\Console;

use App\Support\Addon\AddonException;
use App\Support\Addon\AddonInstaller;
use Illuminate\Console\Command;

class AddonInstallCommand extends Command
{
    protected $signature = 'addon:install {name : 插件名（addons/ 目录名）}';

    protected $description = '安装插件：迁移 → 注册表 → 菜单/权限（§6.3）';

    public function handle(AddonInstaller $installer): int
    {
        $name = $this->argument('name');
        try {
            $installer->install($name);
        } catch (AddonException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("插件 [{$name}] 安装成功：迁移已执行、菜单与权限已写入、插件已启用");

        return self::SUCCESS;
    }
}
