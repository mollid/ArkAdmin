<?php

namespace App\Support\Addon\Console;

use App\Support\Addon\AddonException;
use App\Support\Addon\AddonInstaller;
use Illuminate\Console\Command;

class AddonDisableCommand extends Command
{
    protected $signature = 'addon:disable {name : 插件名}';

    protected $description = '禁用插件（保留数据与代码，不加载路由/事件/菜单）';

    public function handle(AddonInstaller $installer): int
    {
        $name = $this->argument('name');
        try {
            $installer->disable($name);
        } catch (AddonException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("插件 [{$name}] 已禁用");

        return self::SUCCESS;
    }
}
