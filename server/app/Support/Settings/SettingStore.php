<?php

namespace App\Support\Settings;

use App\Admin\Models\Setting;
use App\Support\Addon\AddonManager;

/**
 * settings 存取基建（harness 规格 §3.2）：被消费的基建在核心，管理界面在 settings 系统插件。
 * 归属模型：schema 声明即白名单；scope=plugin 时所有权 = 声明插件，scope=system 时所有权 = system（共用只读给插件）。
 * 插件代码经 setFor() 写入（归属校验收口）；管理页经 setMany() 写入（仅 schema 白名单，管理员受权限保护）。
 */
class SettingStore
{
    public function __construct(protected AddonManager $manager) {}

    /** @return array<string, array> 全量 schema（key => entry，含 addon 归属与 scope） */
    public function schemaMap(): array
    {
        return $this->manager->settingSchemas();
    }

    /** 管理页视图：schema 声明 + 当前值（无行回退声明默认值） */
    public function all(): array
    {
        $rows = Setting::query()->pluck('value', 'key');

        return array_map(function (array $entry) use ($rows) {
            $entry['value'] = $rows->has($entry['key'])
                ? $this->decode($rows->get($entry['key']))
                : ($entry['default'] ?? null);

            return $entry;
        }, array_values($this->schemaMap()));
    }

    /** 读单键：无行时回退 schema 默认，再回退调用方默认 */
    public function get(string $key, mixed $fallback = null): mixed
    {
        $row = Setting::query()->where('key', $key)->first();
        if ($row !== null) {
            return $this->decode($row->value);
        }

        return $this->schemaMap()[$key]['default'] ?? $fallback;
    }

    /** 插件代码入口：归属校验收口——只能写自己拥有的键 */
    public function setFor(string $addon, array $values): void
    {
        $schema = $this->schemaMap();
        $this->assertWritable($addon, $schema, $values);
        foreach ($values as $key => $value) {
            $owner = $this->ownerOf($schema, $key);
            $this->set($key, $value, $owner);
        }
    }

    /** 管理页入口：管理员受 system.setting.update 权限保护，仅校验 schema 白名单 */
    public function setMany(array $values): void
    {
        $schema = $this->schemaMap();
        foreach ($values as $key => $value) {
            if (! isset($schema[$key])) {
                throw new SettingException("设置键 [{$key}] 未在任何插件 schema 中声明");
            }
        }
        foreach ($values as $key => $value) {
            $this->set($key, $value, $this->ownerOf($schema, $key));
        }
    }

    /** 核心内部直写（无白名单），addon_key 由调用方给定 */
    public function set(string $key, mixed $value, string $addonKey): void
    {
        Setting::updateOrCreate(['key' => $key], [
            'value' => json_encode($value),
            'addon_key' => $addonKey,
        ]);
    }

    protected function assertWritable(string $addon, array $schema, array $values): void
    {
        foreach ($values as $key => $value) {
            if (! isset($schema[$key])) {
                throw new SettingException("设置键 [{$key}] 未在任何插件 schema 中声明");
            }
            $owner = $this->ownerOf($schema, $key);
            if ($owner !== $addon) {
                throw new SettingException("插件 [{$addon}] 无权写入归属 [{$owner}] 的设置 [{$key}]");
            }
        }
    }

    protected function ownerOf(array $schema, string $key): string
    {
        return ($schema[$key]['scope'] ?? 'plugin') === 'system' ? 'system' : (string) $schema[$key]['addon'];
    }

    protected function decode(mixed $raw): mixed
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
        }

        return $raw;
    }
}
