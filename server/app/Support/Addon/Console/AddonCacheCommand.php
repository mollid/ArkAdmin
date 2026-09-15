<?php

namespace App\Support\Addon\Console;

use App\Support\Addon\AddonManager;
use Illuminate\Console\Command;

class AddonCacheCommand extends Command
{
    protected $signature = 'addon:cache';

    protected $description = '编译已启用插件清单与监听映射缓存（§6.4）';

    public function handle(AddonManager $manager): int
    {
        $manager->compile();
        $this->info('插件缓存已生成：'.$manager->compiledFile());

        return self::SUCCESS;
    }
}
