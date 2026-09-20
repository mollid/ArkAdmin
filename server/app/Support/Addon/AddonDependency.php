<?php

namespace App\Support\Addon;

use App\Support\Addon\Models\Addon;

/**
 * 依赖拓扑校验（harness §4.3）：依赖图以磁盘清单（AddonManager::scan()）为准，
 * 启用态以注册表为准。dependencies 维持 string[]，不做版本约束。
 * 「已安装」统一口径 = 注册表存在且磁盘健在：目录被删的插件无法加载，
 * 把它当依赖通过等于装出「已启用但依赖不可加载」的脏状态。
 */
class AddonDependency
{
    public function __construct(protected AddonManager $manager) {}

    /** 安装校验：依赖须已安装（注册表 + 磁盘健在，启用态不要求）+ 清单无环 */
    public function assertInstallable(AddonInfo $info): void
    {
        $scan = $this->manager->scan();
        foreach ($info->dependencies as $dep) {
            if (Addon::find($dep) === null || ! isset($scan[$dep])) {
                throw new AddonException("依赖插件 [{$dep}] 未安装");
            }
        }
        $this->assertAcyclic($info, $scan);
    }

    /** 启用校验：依赖须已安装且已启用 + 清单无环 */
    public function assertEnableable(AddonInfo $info): void
    {
        $scan = $this->manager->scan();
        foreach ($info->dependencies as $dep) {
            $record = Addon::find($dep);
            if ($record === null || ! $record->enabled || ! isset($scan[$dep])) {
                throw new AddonException("依赖插件 [{$dep}] 未安装或未启用，无法启用 [{$info->name}]");
            }
        }
        $this->assertAcyclic($info, $scan);
    }

    /**
     * @param  array<string, AddonInfo>|null  $scan  可传入复用的磁盘扫描（列表页 N 行共用一次 scan）
     * @return array<string> 已安装且清单声明依赖 $name 的插件（$enabledOnly=false 时含禁用态）
     *                       已知局限：磁盘缺失的已装插件清单不可读，其依赖声明无从核验，不参与判定
     */
    public function dependents(string $name, bool $enabledOnly = true, ?array $scan = null): array
    {
        $scan ??= $this->manager->scan();
        $out = [];
        foreach (Addon::query()
            ->when($enabledOnly, fn ($q) => $q->where('enabled', true))
            ->get() as $record) {
            if ($record->name === $name) {
                // AUDIT-B1b：事后篡改清单给已装插件加自依赖时，没有这行会把「先卸载依赖方」
                // 指向自身形成死锁（install 有未安装卡口、enable/upgrade 有环检测，唯独卸载裸查）
                continue;
            }
            $info = $scan[$record->name] ?? null;
            if ($info !== null && in_array($name, $info->dependencies, true)) {
                $out[] = $record->name;
            }
        }

        return $out;
    }

    /**
     * 列表页批量口径：一次查询注册表构建反向依赖映射（AUDIT-D1，避免逐行 dependents() 的 N+1）。
     * 语义与逐键调 dependents($name, $enabledOnly, $scan) 完全一致：
     * 值只含已安装（$enabledOnly 时须已启用）且磁盘清单声明依赖该键的插件名。
     *
     * @param  array<string, AddonInfo>  $scan
     * @return array<string, list<string>>
     */
    public function dependentsMap(bool $enabledOnly, array $scan): array
    {
        $wanted = [];   // 依赖声明反向索引：依赖方 => 其声明的依赖集合（磁盘清单为准）
        foreach ($scan as $info) {
            foreach ($info->dependencies as $dep) {
                $wanted[$info->name][$dep] = true;
            }
        }
        $out = [];
        foreach (Addon::query()
            ->when($enabledOnly, fn ($q) => $q->where('enabled', true))
            ->get() as $record) {
            foreach ($wanted[$record->name] ?? [] as $dep => $_) {
                $out[$dep][] = $record->name;
            }
        }

        return $out;
    }

    /** 从 $info 出发沿磁盘清单深搜，回到自身即环（清单互相引用在安装期就该炸，而不是引导期静默错乱） */
    protected function assertAcyclic(AddonInfo $info, array $scan): void
    {
        $stack = [[$info->name, [$info->name]]];
        $visited = [];  // 已确认无环回溯过的节点直接剪枝，避免菱形依赖图重复展开
        while ($stack !== []) {
            [$current, $path] = array_pop($stack);
            foreach ($scan[$current]->dependencies ?? [] as $dep) {
                if ($dep === $info->name) {
                    throw new AddonException('依赖成环：'.implode(' → ', [...$path, $dep]));
                }
                if (isset($scan[$dep]) && ! in_array($dep, $path, true) && ! isset($visited[$dep])) {
                    $visited[$dep] = true;
                    $stack[] = [$dep, [...$path, $dep]];
                }
            }
        }
    }
}
