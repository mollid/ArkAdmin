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

        // 以同步的真实结果为准（路径来自 admin_path 配置，失败时不谎报成功）
        if ($installer->lastSyncedFrontend !== null) {
            $this->info("前端已同步至 {$installer->lastSyncedFrontend}");
            $this->line('生产环境请执行 cd admin && npm run build 完成后台构建');
        } elseif (is_dir(rtrim((string) config('arkadmin.addon_path'), '/')."/{$name}/admin")) {
            $this->warn('插件带前端但同步失败，请检查 arkadmin.admin_path 配置与目录权限（详见日志）');
        }

        return self::SUCCESS;
    }
}
