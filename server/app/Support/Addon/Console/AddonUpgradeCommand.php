<?php

namespace App\Support\Addon\Console;

use App\Support\Addon\AddonException;
use App\Support\Addon\AddonInstaller;
use App\Support\Addon\AddonManager;
use App\Support\Addon\Models\Addon;
use Illuminate\Console\Command;

class AddonUpgradeCommand extends Command
{
    protected $signature = 'addon:upgrade {name? : 插件名；省略时等价 --all} {--all : 扫描全部已安装插件} {--force : 跳过版本比较（版本未 bump 但需补跑迁移）}';

    protected $description = '升级插件：执行新增迁移 → upgrade 钩子 → 补菜单/权限 → 写回版本';

    public function handle(AddonInstaller $installer, AddonManager $manager): int
    {
        $name = $this->argument('name');
        $targets = ($name !== null && ! $this->option('all'))
            ? [(string) $name]
            : collect($manager->scan())->keys()->intersect(Addon::query()->pluck('name')->all())->values()->all();

        $upgraded = 0;
        foreach ($targets as $target) {
            try {
                $info = $installer->upgrade($target, (bool) $this->option('force'));
            } catch (AddonException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
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
        if ($upgraded === 0) {
            $this->line('没有需要升级的插件');
        }

        return self::SUCCESS;
    }
}
