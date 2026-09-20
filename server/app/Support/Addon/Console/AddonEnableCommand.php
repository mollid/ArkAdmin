<?php

namespace App\Support\Addon\Console;

use App\Support\Addon\AddonException;
use App\Support\Addon\AddonInstaller;
use Illuminate\Console\Command;

class AddonEnableCommand extends Command
{
    protected $signature = 'addon:enable {name : 插件名}';

    protected $description = '启用已安装的插件';

    public function handle(AddonInstaller $installer): int
    {
        $name = $this->argument('name');
        try {
            $installer->enable($name);
        } catch (AddonException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("插件 [{$name}] 已启用");
        // enable 会幂等重同步前端产物（自愈/更新），与 install/upgrade 同款构建提示（AUDIT-C5a）
        if ($installer->lastSyncedFrontend !== null) {
            $this->line('生产环境请执行 cd admin && npm run build 完成后台构建');
        }

        return self::SUCCESS;
    }
}
