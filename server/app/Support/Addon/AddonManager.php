<?php

namespace App\Support\Addon;

use App\Support\Addon\Models\Addon;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\QueryException;

/**
 * 插件引导与发现（§6.4）：只读组件——扫描磁盘、读注册表、编译缓存、注册 provider。
 * 注册表（addons 表）的写操作全部在 AddonInstaller。
 */
class AddonManager
{
    protected static bool $autoloaderRegistered = false;

    /** @var array<class-string, true> 本次进程已注册的 provider，保证 boot 幂等 */
    protected array $loaded = [];

    public function __construct(protected Application $app) {}

    public function addonPath(): string
    {
        return rtrim((string) config('arkadmin.addon_path', base_path('addons')), '/');
    }

    public function compiledFile(): string
    {
        return $this->app->bootstrapPath('cache/addons.php');
    }

    public function isCompiled(): bool
    {
        return is_file($this->compiledFile());
    }

    /** @return array<string, AddonInfo> 磁盘插件清单（key = 插件名），坏清单跳过并记日志 */
    public function scan(): array
    {
        $infos = [];
        foreach (glob($this->addonPath().'/*/info.json') ?: [] as $file) {
            try {
                $info = AddonInfo::fromDir(dirname($file));
            } catch (AddonException $e) {
                logger()->warning('跳过无效插件：'.$e->getMessage());

                continue;
            }
            $infos[$info->name] = $info;
        }
        ksort($infos);

        return $infos;
    }

    /** @return array<string, AddonInfo> 注册表中已启用且磁盘健在的插件 */
    public function enabledInfos(): array
    {
        $records = [];
        try {
            // 库未迁移（如 migrate/bootstrap 阶段）时 addons 表可能不存在，降级为空清单
            $records = Addon::query()->where('enabled', true)->get();
        } catch (QueryException $e) {
            logger()->warning('addons 注册表不可读，插件引导跳过：'.$e->getMessage());
        }

        $infos = [];
        foreach ($records as $record) {
            try {
                $infos[$record->name] = AddonInfo::fromDir($this->addonPath().'/'.$record->name);
            } catch (AddonException $e) {
                logger()->warning("已启用插件 [{$record->name}] 无法加载：".$e->getMessage());
            }
        }

        return $infos;
    }

    /** 框架引导入口：注册 Addons\ 自动加载并注册全部已启用插件 provider */
    public function boot(): void
    {
        $this->registerAutoloader();
        foreach ($this->loadableInfos() as $info) {
            $class = $info->providerClass();
            if (isset($this->loaded[$class])) {
                continue;
            }
            if (! class_exists($class)) {
                logger()->warning("插件 [{$info->name}] 缺少 {$class}，跳过加载");

                continue;
            }
            try {
                $this->app->register($class);
                $this->loaded[$class] = true;
            } catch (\Throwable $e) {
                // 单个坏插件不允许拖垮整个后台——否则连禁用它的人口都没有
                logger()->warning("插件 [{$info->name}] 注册失败，已跳过：".$e->getMessage());
            }
        }
    }

    /** 安装/启用后进程内即时注册时登记，保持与 boot() 的幂等记账一致 */
    public function markLoaded(string $providerClass): void
    {
        $this->loaded[$providerClass] = true;
    }

    /** @return list<AddonInfo> 编译缓存优先，缓存缺失时按注册表现算 */
    protected function loadableInfos(): array
    {
        if (! $this->isCompiled()) {
            return array_values($this->enabledInfos());
        }
        $infos = [];
        foreach (require $this->compiledFile() as $entry) {
            try {
                $infos[] = AddonInfo::fromDir($entry['dir']);
            } catch (AddonException $e) {
                logger()->warning('编译缓存中的插件无法加载：'.$e->getMessage());
            }
        }

        return $infos;
    }

    /** Addons\<key>\<Rest> → addons/<key>/src/<Rest>.php（目录名 = 插件名，见计划「设计勘误」） */
    protected function registerAutoloader(): void
    {
        if (static::$autoloaderRegistered) {
            return;
        }
        static::$autoloaderRegistered = true;
        spl_autoload_register(function (string $class): void {
            if (! str_starts_with($class, 'Addons\\')) {
                return;
            }
            $segments = explode('\\', $class);
            if (count($segments) < 3) {
                return;
            }
            $file = $this->addonPath().'/'.$segments[1].'/src/'.implode('/', array_slice($segments, 2)).'.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    /** 编译已启用插件清单（§6.4 addon:cache），生产免每次启动查库。
     *  监听映射不入缓存：监听注册由各插件 provider 在 boot 时完成，缓存里的映射无人消费只会变成说谎的元数据 */
    public function compile(): void
    {
        $this->registerAutoloader();
        $map = [];
        foreach ($this->enabledInfos() as $info) {
            $map[$info->name] = [
                'name' => $info->name,
                'title' => $info->title,
                'version' => $info->version,
                'dir' => $info->dir,
                'provider' => $info->providerClass(),
            ];
        }
        file_put_contents($this->compiledFile(), "<?php\n\nreturn ".var_export($map, true).";\n");
    }

    /**
     * settings schema 声明收集（harness 规格 §3.2）：扫 enabled 插件的 database/settings.php，
     * 每条 = ['key','label','type','default'?,'scope'?]；归属 = 声明插件（scope=system 时所有权为 system）。
     * 键冲突后者覆盖并告警。声明随插件装卸自然生灭，运行时扫描（量级小，暂不入编译缓存）。
     */
    public function settingSchemas(): array
    {
        $out = [];
        foreach ($this->enabledInfos() as $info) {
            $file = $info->dir.'/database/settings.php';
            if (! is_file($file)) {
                continue;
            }
            foreach ((array) require $file as $entry) {
                if (! is_array($entry) || empty($entry['key']) || ! isset($entry['type'], $entry['label'])) {
                    logger()->warning("插件 [{$info->name}] settings.php 存在非法声明，已跳过");

                    continue;
                }
                if (isset($out[$entry['key']])) {
                    logger()->warning("设置键 [{$entry['key']}] 重复声明（{$info->name}），后者覆盖");
                }
                $entry['addon'] = $info->name;
                $entry['scope'] = ($entry['scope'] ?? 'plugin') === 'system' ? 'system' : 'plugin';
                $out[$entry['key']] = $entry;
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * Widget 声明收集（harness 规格 §3.1）：扫 enabled 插件的 database/widgets.php，
     * 每条 = ['key','component','title','permission'?,'sort'?]；key 全局唯一（约定 <addon>.<name>）。
     * 组件文件寻址：/src/addons/<addon>/views/widgets/<component>.vue（前端解析）。
     */
    public function widgets(): array
    {
        $out = [];
        foreach ($this->enabledInfos() as $info) {
            $file = $info->dir.'/database/widgets.php';
            if (! is_file($file)) {
                continue;
            }
            foreach ((array) require $file as $entry) {
                if (! is_array($entry) || empty($entry['key']) || empty($entry['component']) || ! isset($entry['title'])) {
                    logger()->warning("插件 [{$info->name}] widgets.php 存在非法声明，已跳过");

                    continue;
                }
                if (isset($out[$entry['key']])) {
                    logger()->warning("Widget 键 [{$entry['key']}] 重复声明（{$info->name}），后者覆盖");
                }
                $entry['addon'] = $info->name;
                $out[$entry['key']] = $entry;
            }
        }
        ksort($out);

        return array_values($out);
    }

    public function flushCompiled(): void
    {
        @unlink($this->compiledFile());
    }
}
