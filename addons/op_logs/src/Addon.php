<?php

namespace Addons\op_logs;

use App\Support\Addon\Contracts\Lifecycle;

class Addon implements Lifecycle
{
    public function install(): void
    {
    }

    public function uninstall(): void
    {
        // 日志随表回滚（迁移在插件内），无额外清理
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
