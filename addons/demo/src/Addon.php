<?php

namespace Addons\demo;

use App\Support\Addon\Contracts\Lifecycle;

/** M2 验收空插件：生命周期钩子暂无自定义逻辑，保持显式空实现作为插件模板 */
class Addon implements Lifecycle
{
    public function install(): void
    {
    }

    public function uninstall(): void
    {
    }

    public function enable(): void
    {
    }

    public function disable(): void
    {
    }

    public function upgrade(string $fromVersion): void
    {
    }
}
