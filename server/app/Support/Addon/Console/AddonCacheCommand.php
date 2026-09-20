<?php

namespace App\Support\Addon\Console;

use App\Support\Addon\AddonException;
use App\Support\Addon\AddonManager;
use Illuminate\Console\Command;

class AddonCacheCommand extends Command
{
    protected $signature = 'addon:cache';

    protected $description = '编译已启用插件清单与监听映射缓存（§6.4）';

    public function handle(AddonManager $manager): int
    {
        try {
            $manager->compile();
        } catch (AddonException $e) {
            // 写失败（磁盘满/权限）如实报错，绝不假成功（AUDIT-C5b）
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info('插件缓存已生成：'.$manager->compiledFile());

        return self::SUCCESS;
    }
}
