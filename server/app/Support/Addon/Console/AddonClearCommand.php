<?php

namespace App\Support\Addon\Console;

use App\Support\Addon\AddonManager;
use Illuminate\Console\Command;

class AddonClearCommand extends Command
{
    protected $signature = 'addon:clear';

    protected $description = '清除插件编译缓存';

    public function handle(AddonManager $manager): int
    {
        $manager->flushCompiled();
        $this->info('插件缓存已清除');

        return self::SUCCESS;
    }
}
