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
        if (! $manager->flushCompiled()) {
            $this->error('插件缓存清除失败：'.$manager->compiledFile());

            return self::FAILURE;
        }
        $this->info('插件缓存已清除');

        return self::SUCCESS;
    }
}
