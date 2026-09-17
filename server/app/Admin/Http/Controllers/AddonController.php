<?php

namespace App\Admin\Http\Controllers;

use App\Support\Addon\AddonDependency;
use App\Support\Addon\AddonException;
use App\Support\Addon\AddonInfo;
use App\Support\Addon\AddonInstaller;
use App\Support\Addon\AddonManager;
use App\Support\Addon\Models\Addon;
use App\Support\Http\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AddonController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AddonManager $manager,
        protected AddonInstaller $installer,
        protected AddonDependency $dependencies,
    ) {}

    /** 磁盘扫描 ∪ 注册表：管理界面的唯一列表来源（未安装插件也可见） */
    public function index(): JsonResponse
    {
        $scan = $this->manager->scan();
        $records = Addon::all()->keyBy('name');

        $rows = [];
        foreach ($scan as $info) {
            $rows[] = $this->row($info, $records->get($info->name));
        }
        // 注册表有、磁盘缺失（异常态）：只读展示，提示排查
        foreach ($records->reject(fn ($r, $name) => isset($scan[$name])) as $record) {
            $rows[] = [
                'name' => $record->name, 'title' => $record->title, 'description' => '',
                'version' => '', 'installed_version' => $record->version,
                'installed' => true, 'enabled' => (bool) $record->enabled,
                'upgradable' => false, 'system' => false,
                'dependencies' => [], 'missing_dependencies' => [], 'dependents' => [],
                'install_time' => $record->install_time?->toDateTimeString(),
                'disk_missing' => true,
            ];
        }

        return $this->success($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['name' => 'required|string|max:64']);
        try {
            $this->installer->install((string) $request->input('name'));
        } catch (AddonException $e) {
            return $this->fail(1, $e->getMessage());
        }

        return $this->success(
            ['needs_build' => $this->installer->lastSyncedFrontend !== null],
            '安装成功'
        );
    }

    /** action 统一入口：enable/disable/upgrade（权限串 system.addon.update） */
    public function update(Request $request, string $addon): JsonResponse
    {
        $request->validate([
            'action' => 'required|in:enable,disable,upgrade',
            'force' => 'nullable|boolean',
        ]);
        $action = (string) $request->input('action');
        $systemAddons = (array) config('arkadmin.system_addons', []);
        // 前端禁按钮只是提示，后端必须拦：绕过界面打接口的代价是设置页/日志功能损坏
        if ($action === 'disable' && in_array($addon, $systemAddons, true)) {
            return $this->fail(1, "系统插件 [{$addon}] 不能停用，请使用 CLI 操作");
        }
        if ($action === 'upgrade') {
            try {
                $info = $this->installer->upgrade($addon, (bool) $request->boolean('force'));
            } catch (AddonException $e) {
                return $this->fail(1, $e->getMessage());
            }

            return $this->success([
                'upgraded' => $info !== null,
                'needs_build' => $this->installer->lastSyncedFrontend !== null,
            ], $info === null ? '已是最新版本' : "已升级到 {$info->version}");
        }
        try {
            $this->installer->{$action}($addon);
        } catch (AddonException $e) {
            return $this->fail(1, $e->getMessage());
        }

        return $this->success(null, $action === 'enable' ? '已启用' : '已禁用');
    }

    public function destroy(Request $request, string $addon): JsonResponse
    {
        if (in_array($addon, (array) config('arkadmin.system_addons', []), true)) {
            return $this->fail(1, "系统插件 [{$addon}] 不能卸载，请使用 CLI 操作");
        }
        try {
            $this->installer->uninstall($addon, $request->boolean('keep_data'));
        } catch (AddonException $e) {
            return $this->fail(1, $e->getMessage());
        }

        return $this->success(null, '卸载成功');
    }

    protected function row(AddonInfo $info, ?Addon $record): array
    {
        $missing = array_values(array_filter(
            $info->dependencies,
            fn ($dep) => Addon::find($dep) === null
        ));

        return [
            'name' => $info->name,
            'title' => $info->title,
            'description' => $info->description,
            'version' => $info->version,
            'installed_version' => $record?->version,
            'installed' => $record !== null,
            'enabled' => (bool) $record?->enabled,
            'upgradable' => $record !== null
                && version_compare($info->version, (string) $record->version, '>'),
            'system' => in_array($info->name, (array) config('arkadmin.system_addons', []), true),
            'dependencies' => $info->dependencies,
            'missing_dependencies' => $missing,
            'dependents' => $this->dependencies->dependents($info->name, false),
            'install_time' => $record?->install_time?->toDateTimeString(),
        ];
    }
}
