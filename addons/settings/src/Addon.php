<?php

namespace Addons\settings;

use App\Admin\Models\Setting;
use App\Support\Addon\Contracts\Lifecycle;

class Addon implements Lifecycle
{
    public function install(): void
    {
    }

    /** 卸载清理本插件自有的设置行（addon_key=settings）；scope=system 的共用键保留（值随重装复用） */
    public function uninstall(): void
    {
        Setting::query()->where('addon_key', 'settings')->delete();
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
