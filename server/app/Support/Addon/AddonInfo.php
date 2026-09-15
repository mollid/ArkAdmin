<?php

namespace App\Support\Addon;

/**
 * info.json 清单 VO（§6.2）。所有插件操作以它为唯一入口，
 * 校验失败的插件不允许进入安装/引导流程。
 */
class AddonInfo
{
    public function __construct(
        public readonly string $name,
        public readonly string $title,
        public readonly string $description,
        public readonly string $version,
        public readonly string $type,
        public readonly string $supportVersion,
        public readonly array $dependencies,
        public readonly string $dir,
    ) {
    }

    public static function fromDir(string $dir): self
    {
        $dir = rtrim($dir, '/');
        $file = $dir.'/info.json';
        if (! is_file($file)) {
            throw new AddonException("插件目录缺少 info.json：{$dir}");
        }
        $json = json_decode((string) file_get_contents($file), true);
        if (! is_array($json)) {
            throw new AddonException("info.json 不是合法 JSON 对象：{$file}");
        }
        foreach (['name', 'title', 'version', 'type', 'support_version'] as $key) {
            if (! isset($json[$key]) || ! is_string($json[$key]) || $json[$key] === '') {
                throw new AddonException("info.json 缺少必填字段 {$key}：{$file}");
            }
        }
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $json['name'])) {
            // \$ 必须转义：$ 后接全角字符的字节会被双引号串解析为变量
            throw new AddonException("插件名必须匹配 ^[a-z][a-z0-9_]*\$：{$json['name']}");
        }
        if ($json['type'] !== 'app') {
            throw new AddonException("暂不支持的插件类型 [{$json['type']}]：{$file}");
        }
        if (! is_dir($dir.'/src')) {
            throw new AddonException("插件缺少 src 目录：{$dir}");
        }
        $dependencies = $json['dependencies'] ?? [];
        if (! is_array($dependencies)) {
            throw new AddonException("dependencies 必须为字符串数组：{$file}");
        }

        return new self(
            $json['name'],
            $json['title'],
            (string) ($json['description'] ?? ''),
            $json['version'],
            $json['type'],
            $json['support_version'],
            array_values(array_filter($dependencies, 'is_string')),
            $dir,
        );
    }

    public function providerClass(): string
    {
        return "Addons\\{$this->name}\\AddonServiceProvider";
    }

    public function addonClass(): string
    {
        return "Addons\\{$this->name}\\Addon";
    }

    /** support_version：插件要求的框架最低版本（§6.2） */
    public function isSupported(string $frameworkVersion): bool
    {
        return version_compare($frameworkVersion, $this->supportVersion, '>=');
    }

    public function routeFile(): string
    {
        return $this->dir.'/routes/admin.php';
    }

    public function migrationPath(): string
    {
        return $this->dir.'/database/migrations';
    }

    public function menusFile(): string
    {
        return $this->dir.'/database/menus.php';
    }

    public function permissionsFile(): string
    {
        return $this->dir.'/database/permissions.php';
    }
}
