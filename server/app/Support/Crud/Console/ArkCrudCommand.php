<?php

namespace App\Support\Crud\Console;

use App\Support\Addon\AddonException;
use App\Support\Addon\AddonInstaller;
use App\Support\Crud\CrudException;
use App\Support\Crud\CrudGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * §10 M5：按既有数据表生成迁移外全套 CRUD 模块（FastAdmin crud 的 Laravel 版）。
 * 表名须以插件前缀开头；--addon 缺省按前缀推断；收尾刷新菜单/权限并同步前端产物。
 */
class ArkCrudCommand extends Command
{
    protected $signature = 'ark:crud
        {--table= : 数据表名（须以插件前缀开头，如 cms_articles）}
        {--addon= : 目标插件 key（缺省按表前缀推断）}
        {--force : 覆盖已生成文件（标记对内容整块替换）}';

    protected $description = '按既有数据表生成 CRUD 模块（Model/Service/Controller/Requests/路由/菜单/权限/前端）';

    public function handle(CrudGenerator $generator, AddonInstaller $installer): int
    {
        $table = trim((string) $this->option('table'));
        if ($table === '') {
            $this->error('缺少 --table（如 --table=cms_articles）');

            return self::FAILURE;
        }
        $addon = trim((string) $this->option('addon'));
        if ($addon === '') {
            $addon = Str::before($table, '_');
            $this->line("未指定 --addon，按表前缀推断为 [{$addon}]");
        }

        try {
            $paths = $generator->generate($table, $addon, (bool) $this->option('force'));
        } catch (CrudException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($paths as $path) {
            $this->info('已生成：'.Str::after($path, base_path().DIRECTORY_SEPARATOR));
        }

        // 菜单/权限写入 DB + 前端产物自愈：仅对已安装插件生效（未安装由 install 流程负责）
        try {
            $installer->refreshMenusAndPermissions($addon);
            $this->info('菜单与权限已刷新（未启用插件的菜单待启用后可见）');
            if ($installer->lastSyncedFrontend !== null) {
                $this->line('前端已同步至 '.Str::after($installer->lastSyncedFrontend, base_path().DIRECTORY_SEPARATOR)
                    .'，生产环境请执行 cd admin && npm run build 完成后台构建');
            }
        } catch (AddonException $e) {
            $this->warn('代码已生成，但未刷新菜单/权限：'.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
