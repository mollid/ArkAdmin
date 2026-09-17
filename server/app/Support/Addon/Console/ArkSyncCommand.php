<?php

namespace App\Support\Addon\Console;

use App\Support\Addon\FrameworkCache;
use App\Support\Setup\FrameworkSync;
use Illuminate\Console\Command;

class ArkSyncCommand extends Command
{
    protected $signature = 'ark:sync {--no-addons : 只同步框架权限与菜单，不安装系统插件}';

    protected $description = '同步框架声明到运行环境：权限 + 菜单 + 捆绑系统插件（幂等，升级框架/插件后执行）';

    public function handle(FrameworkSync $sync, FrameworkCache $cache): int
    {
        $result = $sync->run(! (bool) $this->option('no-addons'));
        $this->info('框架权限与菜单已同步');
        foreach ($result['installed'] as $addon) {
            $this->info("系统插件 [{$addon}] 已安装");
        }
        foreach ($result['skipped'] as $addon => $reason) {
            $this->line("系统插件 [{$addon}] 跳过：{$reason}");
        }
        $cache->rebuild();
        $this->line('若页面按钮/菜单未更新，请刷新浏览器（前端按 /auth/me 权限串渲染）');

        return self::SUCCESS;
    }
}
