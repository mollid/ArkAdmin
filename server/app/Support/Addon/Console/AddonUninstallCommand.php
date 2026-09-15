<?php

namespace App\Support\Addon\Console;

use App\Support\Addon\AddonException;
use App\Support\Addon\AddonInstaller;
use Illuminate\Console\Command;

class AddonUninstallCommand extends Command
{
    protected $signature = 'addon:uninstall {name : 插件名} {--keep-data : 保留插件业务表数据}';

    protected $description = '卸载插件：清菜单/权限/注册行，默认回滚业务表';

    public function handle(AddonInstaller $installer): int
    {
        $name = $this->argument('name');
        try {
            $installer->uninstall($name, (bool) $this->option('keep-data'));
        } catch (AddonException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("插件 [{$name}] 已卸载".($this->option('keep-data') ? '（业务表已保留）' : '，业务表已回滚'));

        return self::SUCCESS;
    }
}
