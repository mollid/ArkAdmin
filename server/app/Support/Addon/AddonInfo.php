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
        // 长度上限与 create_addons_table 列宽对齐（AUDIT-B1a）：超长值漏到 DB 层才炸
        // 会以裸 SQLSTATE 告终，违反「无效清单不进入安装流程」的报错口径
        foreach (['name' => 64, 'title' => 255, 'version' => 32, 'support_version' => 32] as $key => $max) {
            if (mb_strlen($json[$key]) > $max) {
                throw new AddonException("info.json 字段 {$key} 超长（上限 {$max} 字符）：{$file}");
            }
        }
        // description 不落库（仅列表接口透传），上限防几 MB 文本撑大 HTTP 响应
        if (mb_strlen((string) ($json['description'] ?? '')) > 1000) {
            throw new AddonException('info.json 字段 description 超长（上限 1000 字符）：'.$file);
        }
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $json['name'])) {
            // \$ 必须转义：$ 后接全角字符的字节会被双引号串解析为变量
            throw new AddonException("插件名必须匹配 ^[a-z][a-z0-9_]*\$：{$json['name']}");
        }
        if ($json['name'] !== basename($dir)) {
            // 目录名即身份：install/boot 均以 addons/<name>/ 定位，错位意味着注册到找不到的目录
            throw new AddonException(
                "清单 name [{$json['name']}] 与目录名 [".basename($dir)."] 不一致：{$file}"
            );
        }
        foreach (['version', 'support_version'] as $key) {
            // version_compare 对 'v1.0'、'1.0'、'1.0 beta' 的比较结果不可靠（M2 评审遗留容忍项，M8 收口）
            if (! preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $json[$key])) {
                throw new AddonException(
                    "info.json 字段 {$key} 版本号格式无效 [{$json[$key]}]（须为 x.y.z[-prerelease]）：{$file}"
                );
            }
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
        foreach ($dependencies as $dep) {
            if (! is_string($dep) || $dep === '') {
                // 静默丢弃会让坏清单混进生命周期，与"无效清单不进入安装流程"的契约冲突
                throw new AddonException("dependencies 含非字符串或空项：{$file}");
            }
        }

        return new self(
            $json['name'],
            $json['title'],
            (string) ($json['description'] ?? ''),
            $json['version'],
            $json['type'],
            $json['support_version'],
            array_values($dependencies),
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
