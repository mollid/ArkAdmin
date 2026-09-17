<?php

namespace App\Support\Setup;

use App\Admin\Seeds\MenuSeeder;
use App\Admin\Seeds\RbacSeeder;
use App\Support\Addon\AddonException;
use App\Support\Addon\AddonInstaller;

/**
 * 把代码里声明的框架内容同步到运行环境（AGENTS「升级/新增框架权限后」）：
 * 权限 + 菜单 + 捆绑系统插件，全幂等，可重复执行。db:seed 与 ark:sync 共用。
 */
class FrameworkSync
{
    public function __construct(protected AddonInstaller $installer) {}

    /** @return array{installed: list<string>, skipped: array<string,string>} */
    public function run(bool $withSystemAddons = true): array
    {
        (new RbacSeeder)->run();
        (new MenuSeeder)->run();

        $installed = [];
        $skipped = [];
        if ($withSystemAddons) {
            foreach ((array) config('arkadmin.system_addons', ['settings', 'op_logs']) as $addon) {
                $name = (string) $addon;
                try {
                    $this->installer->install($name);
                    $installed[] = $name;
                } catch (AddonException $e) {
                    $skipped[$name] = $e->getMessage();
                }
            }
        }

        return ['installed' => $installed, 'skipped' => $skipped];
    }
}
