<?php

namespace App\Support\Addon\Console;

use App\Support\Addon\AddonManager;
use App\Support\Addon\Models\Addon;
use Illuminate\Console\Command;

class AddonListCommand extends Command
{
    protected $signature = 'addon:list';

    protected $description = '列出磁盘与注册表中的插件';

    public function handle(AddonManager $manager): int
    {
        $scan = $manager->scan();

        // addons 表缺失（手工回滚等异常态）时降级为只列磁盘插件，不致命
        $records = collect();
        try {
            $records = Addon::all()->keyBy('name');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->warn('addons 注册表不可读：'.$e->getMessage());
        }

        $rows = [];
        foreach ($scan as $info) {
            $record = $records->get($info->name);
            $rows[] = [
                $info->name, $info->title, $info->version, $info->supportVersion,
                $record ? '是' : '否', $record?->enabled ? '是' : '否',
            ];
        }
        // 注册表里有、磁盘上没有的（异常态，提示排查）
        foreach ($records->reject(fn ($r, $name) => isset($scan[$name])) as $record) {
            $rows[] = [$record->name, $record->title, $record->version, '-', '磁盘缺失', $record->enabled ? '是' : '否'];
        }
        $this->table(['name', 'title', 'version', 'support', '已安装', '已启用'], $rows);

        return self::SUCCESS;
    }
}
