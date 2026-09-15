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

        return self::SUCCESS;
    }
}
