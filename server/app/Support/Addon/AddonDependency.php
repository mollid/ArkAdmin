<?php

namespace App\Support\Addon;

use App\Support\Addon\Models\Addon;

/**
 * 依赖拓扑校验（harness §4.3）：依赖图以磁盘清单（AddonManager::scan()）为准，
 * 启用态以注册表为准。dependencies 维持 string[]，不做版本约束。
 */
class AddonDependency
{
    public function __construct(protected AddonManager $manager) {}

    /** 安装校验：依赖须已安装（启用态不要求）+ 清单无环 */
    public function assertInstallable(AddonInfo $info): void
    {
        foreach ($info->dependencies as $dep) {
            if (Addon::find($dep) === null) {
                throw new AddonException("依赖插件 [{$dep}] 未安装");
            }
        }
        $this->assertAcyclic($info);
    }

    /** 启用校验：依赖须已安装且已启用 + 清单无环 */
    public function assertEnableable(AddonInfo $info): void
    {
        foreach ($info->dependencies as $dep) {
            $record = Addon::find($dep);
            if ($record === null || ! $record->enabled) {
                throw new AddonException("依赖插件 [{$dep}] 未安装或未启用，无法启用 [{$info->name}]");
            }
        }
        $this->assertAcyclic($info);
    }

    /** @return array<string> 已安装且清单声明依赖 $name 的插件（$enabledOnly=false 时含禁用态） */
    public function dependents(string $name, bool $enabledOnly = true): array
    {
        $scan = $this->manager->scan();
        $out = [];
        foreach (Addon::query()
            ->when($enabledOnly, fn ($q) => $q->where('enabled', true))
            ->get() as $record) {
            $info = $scan[$record->name] ?? null;
            if ($info !== null && in_array($name, $info->dependencies, true)) {
                $out[] = $record->name;
            }
        }

        return $out;
    }

    /** 从 $info 出发沿磁盘清单深搜，回到自身即环（清单互相引用在安装期就该炸，而不是引导期静默错乱） */
    protected function assertAcyclic(AddonInfo $info): void
    {
        $scan = $this->manager->scan();
        $stack = [[$info->name, [$info->name]]];
        while ($stack !== []) {
            [$current, $path] = array_pop($stack);
            foreach ($scan[$current]->dependencies ?? [] as $dep) {
                if ($dep === $info->name) {
                    throw new AddonException('依赖成环：'.implode(' → ', [...$path, $dep]));
                }
                if (isset($scan[$dep]) && ! in_array($dep, $path, true)) {
                    $stack[] = [$dep, [...$path, $dep]];
                }
            }
        }
    }
}
