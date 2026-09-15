<?php

namespace App\Support\Addon\Contracts;

/** 插件生命周期钩子（§6.3）。全部钩子在对应迁移/注册表操作之后由框架调用 */
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
