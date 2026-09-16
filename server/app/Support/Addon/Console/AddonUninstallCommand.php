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

        // 源插件带前端时，产物已被删除，但已构建的 dist 里仍有该插件的旧 chunk
        if (is_dir(rtrim((string) config('arkadmin.addon_path'), '/')."/{$name}/admin")) {
            $this->line('生产环境请执行 cd admin && npm run build 重新构建，以移除该插件的前端页面');
        }

        return self::SUCCESS;
    }
}
