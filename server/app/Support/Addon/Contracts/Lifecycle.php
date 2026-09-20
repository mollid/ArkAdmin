<?php

namespace App\Support\Addon\Contracts;

/**
 * 插件生命周期钩子（§6.3）。全部钩子在对应迁移/注册表操作之后由框架调用。
 *
 * 钩子内禁忌（AUDIT-A8/A10）：
 * - 禁止调用 AddonInstaller 任何公开方法或 addon:* 命令：生命周期互斥锁不可重入，
 *   自调用 3 秒轮询后抛「正在被另一操作处理」且重试永远失败；install/upgrade 钩子
 *   处于 DB 事务内，自重入抛错会连带回滚整个安装/升级事务。
 * - install/upgrade 钩子内避免非 DB 外部副作用（写文件/发 HTTP）：事务回滚不撤销它们。
 */
interface Lifecycle
{
    /** 迁移后执行：种子数据、初始化配置 */
    public function install(): void;

    /** 迁移回滚后执行：清理（--keep-data 卸载时同样调用） */
    public function uninstall(): void;

    public function enable(): void;

    public function disable(): void;

    public function upgrade(string $fromVersion): void;
}
