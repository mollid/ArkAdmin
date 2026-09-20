<?php

namespace App\Support\Addon\Console;

use App\Support\Addon\AddonInstaller;
use App\Support\Addon\AddonManager;
use App\Support\Addon\Models\Addon;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

class AddonUpgradeCommand extends Command
{
    protected $signature = 'addon:upgrade {name? : 插件名；省略时等价 --all} {--all : 扫描全部已安装插件} {--force : 跳过版本比较并重跑 upgrade 钩子（版本未 bump 但需补跑迁移）}';

    protected $description = '升级插件：执行新增迁移 → upgrade 钩子 → 补菜单/权限 → 写回版本';

    public function handle(AddonInstaller $installer, AddonManager $manager): int
    {
        $name = $this->argument('name');
        if ($name !== null && $this->option('all')) {
            $this->error('不能同时指定插件名与 --all');

            return self::FAILURE;
        }

        $scan = collect($manager->scan())->keys()->flip();
        // 注册表缺失（手工回滚等异常态）降级为空集不崩栈，对齐 addon:list 的 warn 降级（AUDIT-C1）
        try {
            $installed = Addon::query()->pluck('name');
        } catch (QueryException $e) {
            $this->warn('addons 注册表不可读：'.$e->getMessage());
            $installed = collect();
        }
        $targets = ($name !== null && ! $this->option('all'))
            ? collect([(string) $name])
            : $installed->intersect($scan->keys())->values();
        // 磁盘缺失的已装插件显式列出（列表页有 disk_missing 展示，命令行不应静默吞掉）；
        // 单名模式除外——显式目标让它走 upgrade 拿到明确失败，而非与实际矛盾的「跳过」文案（AUDIT-C5c）
        if ($name === null) {
            foreach ($installed->reject(fn ($n) => $scan->has($n)) as $missing) {
                $this->line("插件 [{$missing}] 磁盘缺失，跳过（请手工清理注册表后重装）");
            }
        }

        $upgraded = 0;
        $failed = 0;
        foreach ($targets as $target) {
            try {
                $info = $installer->upgrade($target, (bool) $this->option('force'));
            } catch (\Throwable $e) {
                // 批量升级不因单个坏插件中止整批：迁移 SQL / 插件钩子的原生异常
                // 与 AddonException 同样只记单项失败（AUDIT-C2，对齐 HTTP 侧捕 \Throwable 口径）
                $this->error($e->getMessage());
                $failed++;

                continue;
            }
            if ($info === null) {
                $this->line("插件 [{$target}] 已是最新（如需补跑迁移请加 --force）");
                continue;
            }
            $upgraded++;
            $this->info("插件 [{$target}] 已升级到 {$info->version}");
            if ($installer->lastSyncedFrontend !== null) {
                $this->line('生产环境请执行 cd admin && npm run build 完成后台构建');
            }
        }
        if ($upgraded === 0 && $failed === 0) {
            $this->line('没有需要升级的插件');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
