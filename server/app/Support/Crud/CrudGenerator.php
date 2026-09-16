<?php

namespace App\Support\Crud;

use App\Support\Addon\AddonException;
use App\Support\Addon\AddonInfo;
use App\Support\Addon\AddonManager;
use Illuminate\Support\Str;

/**
 * ark:crud 生成器（§10 M5）：读 information_schema，为既有表生成迁移外全套模块。
 * 生成物写入 addons/<key>/ 源码目录；追加类文件用 per-table 标记对做幂等替换；
 * menus.php 结构嵌套，需要一次性植入通用标记对（缺失时报错并给出示例）。
 */
class CrudGenerator
{
    public function __construct(protected AddonManager $manager) {}

    /** @return list<string> 写入的绝对路径 */
    public function generate(string $table, string $addon, bool $force = false): array
    {
        $info = $this->addonInfo($addon);
        $n = $this->names($table, $addon);
        // 前置校验先于任何写入：menus 标记缺失时绝不留下半生成状态
        $this->precheckMenus($info->dir.'/database/menus.php', $table);
        $columns = (new SchemaReader)->columns($table);
        $form = array_values(array_filter($columns, fn (Column $c) => ! $c->skipForm()));

        $mapped = [];
        foreach ($form as $column) {
            $mapped[$column->name] = ['column' => $column, 'map' => FieldMapper::map($column)];
        }
        $searchables = array_values(array_filter($form, fn (Column $c) => FieldMapper::map($c)['searchable']));
        $searchColumn = $searchables[0]->name ?? null;

        $dir = $info->dir;
        $targets = [
            "{$dir}/src/Models/{$n['model']}.php",
            "{$dir}/src/Services/{$n['model']}Service.php",
            "{$dir}/src/Http/Controllers/{$n['model']}Controller.php",
            "{$dir}/src/Http/Requests/{$n['model']}StoreRequest.php",
            "{$dir}/src/Http/Requests/{$n['model']}UpdateRequest.php",
            "{$dir}/admin/api/{$n['viewFile']}.ts",
            "{$dir}/admin/views/{$n['viewDir']}/index.vue",
        ];
        if (! $force) {
            foreach ($targets as $target) {
                if (is_file($target)) {
                    throw new CrudException('文件已存在：'.$target.'（重复生成请加 --force）');
                }
            }
        }

        $tokens = $this->tokens($table, $addon, $n, $mapped, $searchColumn);
        $written = [];
        $write = function (string $path, string $content) use (&$written) {
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, $content);
            $written[] = $path;
        };

        $write("{$dir}/src/Models/{$n['model']}.php", $this->render('model', $tokens));
        $write("{$dir}/src/Services/{$n['model']}Service.php", $this->render('service', $tokens));
        $write("{$dir}/src/Http/Controllers/{$n['model']}Controller.php", $this->render('controller', $tokens));
        $write("{$dir}/src/Http/Requests/{$n['model']}StoreRequest.php", $this->render('store_request', $tokens));
        $write("{$dir}/src/Http/Requests/{$n['model']}UpdateRequest.php", $this->render('update_request', $tokens));
        $write("{$dir}/admin/api/{$n['viewFile']}.ts", $this->render('api_ts', $tokens));
        $write("{$dir}/admin/views/{$n['viewDir']}/index.vue", $this->render('view_vue', $tokens));

        // 追加类文件：本表标记对存在则整块替换；否则按文件形态插入
        $routesFile = "{$dir}/routes/admin.php";
        $this->writeMarkerRegion($routesFile, $table, $this->routesBlock($tokens), appendAtEnd: true);
        $written[] = $routesFile;

        $permissionsFile = "{$dir}/database/permissions.php";
        $this->writeMarkerRegion($permissionsFile, $table, $this->permissionsBlock($tokens), beforeLast: '];');
        $written[] = $permissionsFile;

        $langFile = "{$dir}/admin/lang/zh-cn.ts";
        $this->writeMarkerRegion($langFile, $table, $this->langBlock($tokens), beforeLast: '}');
        $written[] = $langFile;

        $menusFile = "{$dir}/database/menus.php";
        $this->writeMenusEntry($menusFile, $table, $this->menusBlock($tokens));
        $written[] = $menusFile;

        return $written;
    }

    // —— 命名推导（唯一出口） ——

    /** @return array{model:string, var:string, routePath:string, viewDir:string, viewFile:string, menuName:string, perm:string, langKey:string, menuTitle:string} */
    public function names(string $table, string $addon): array
    {
        $prefix = $addon.'_';
        if (! Str::startsWith($table, $prefix) || strlen($table) <= strlen($prefix)) {
            throw new CrudException("表名 [{$table}] 必须以插件前缀 [{$prefix}] 开头（§5.4 表前缀约定）");
        }
        $remainder = substr($table, strlen($prefix));
        $model = Str::studly(Str::singular($remainder));
        $snake = Str::snake($model);

        return [
            'model' => $model,
            'var' => Str::camel($model),
            'routePath' => Str::kebab($remainder),
            'viewDir' => $snake,
            'viewFile' => $snake,
            'menuName' => "{$addon}.{$snake}",
            'perm' => "addon.{$addon}.{$snake}",
            'langKey' => "{$addon}.{$snake}",
            'menuTitle' => Str::headline($remainder),
        ];
    }

    protected function addonInfo(string $addon): AddonInfo
    {
        try {
            return AddonInfo::fromDir($this->manager->addonPath().'/'.$addon);
        } catch (AddonException $e) {
            throw new CrudException("目标插件 [{$addon}] 不存在或清单无效：".$e->getMessage(), 0, $e);
        }
    }

    // —— token 组装 ——

    /** @param array<string, array{column:Column, map:array<string, mixed>}> $mapped */
    protected function tokens(string $table, string $addon, array $n, array $mapped, ?string $searchColumn): array
    {
        $fillable = $casts = $rulesStore = $rulesUpdate = $formItems = $tableColumns = $fields = $defaults = '';
        foreach ($mapped as $name => $pair) {
            $column = $pair['column'];
            $map = $pair['map'];
            $fillable .= "        '{$name}',\n";
            if ($map['cast'] !== null) {
                $casts .= "        '{$name}' => '{$map['cast']}',\n";
            }
            $rulesStore .= '            \''.$name.'\' => ['.implode(', ', array_map(fn ($r) => "'{$r}'", $map['rules']))."],\n";
            $sometimes = array_merge(['sometimes'], array_values(array_filter($map['rules'], fn ($r) => $r !== 'required')));
            $rulesUpdate .= '            \''.$name.'\' => ['.implode(', ', array_map(fn ($r) => "'{$r}'", $sometimes))."],\n";
            $formItems .= $this->formItem($name, FieldMapper::label($column), $map);
            if (! $map['longText']) {
                $tableColumns .= $this->tableColumn($name, FieldMapper::label($column), $map);
            }
            $fields .= "  {$name}: {$map['tsType']}\n";
            $defaults .= $name.': '.$this->defaultOf($map['tsType'], $map['component']).', ';
        }

        return [
            '{{ADDON}}' => $addon,
            '{{TABLE}}' => $table,
            '{{MODEL}}' => $n['model'],
            '{{VAR}}' => $n['var'],
            '{{ROUTE_PATH}}' => $n['routePath'],
            '{{PERM}}' => $n['perm'],
            '{{LANG_KEY}}' => $n['langKey'],
            '{{VIEW_FILE}}' => $n['viewFile'],
            '{{VIEW_DIR}}' => $n['viewDir'],
            // 语言包键是 mergeAddonLangs 并入 zh-cn.<addon> 后的**内层键**（不带插件前缀）：
            // 页面 t('cms.notice.title') 解析路径为 zh-cn → cms → notice → title
            '{{LANG_KEY_INNER}}' => $n['viewDir'],
            '{{MENU_NAME}}' => $n['menuName'],
            '{{MENU_TITLE}}' => $n['menuTitle'],
            '{{FILLABLE}}' => rtrim($fillable, "\n"),
            '{{CASTS}}' => rtrim($casts, "\n") === '' ? '        // 无需 cast 的列' : rtrim($casts, "\n"),
            '{{RULES_STORE}}' => rtrim($rulesStore, "\n"),
            '{{RULES_UPDATE}}' => rtrim($rulesUpdate, "\n"),
            '{{SEARCH_BLOCK}}' => $searchColumn === null ? '' :
                "\n            ->when(\$keyword !== '', fn (\$q) => \$q->where('{$searchColumn}', 'ilike', PgLike::wrap(\$keyword)))",
            '{{FORM_ITEMS}}' => rtrim($formItems, "\n"),
            '{{TABLE_COLUMNS}}' => rtrim($tableColumns, "\n"),
            '{{INTERFACE_FIELDS}}' => rtrim($fields, "\n"),
            '{{FORM_DEFAULTS}}' => rtrim($defaults, ', '),
        ];
    }

    /** 表单控件行（8 空格起，嵌在 el-form 内） */
    protected function formItem(string $name, string $label, array $map): string
    {
        $props = $map['props'] !== '' ? ' '.$map['props'] : '';
        $control = "<{$map['component']}{$props} v-model=\"form.{$name}\" />";

        return "        <el-form-item label=\"{$label}\">\n"
            ."          {$control}\n"
            ."        </el-form-item>\n";
    }

    /** 表格列（6 空格起）；布尔列渲染为标签，日期列定宽 */
    protected function tableColumn(string $name, string $label, array $map): string
    {
        if ($map['component'] === 'el-switch') {
            return "      <el-table-column prop=\"{$name}\" label=\"{$label}\" width=\"90\">\n"
                ."        <template #default=\"{ row }\">\n"
                ."          <el-tag :type=\"row.{$name} ? 'success' : 'info'\">{{ row.{$name} ? '是' : '否' }}</el-tag>\n"
                ."        </template>\n"
                ."      </el-table-column>\n";
        }
        $width = str_contains($map['component'], 'date-picker') ? ' width="170"' : ' min-width="120"';

        return "      <el-table-column prop=\"{$name}\" label=\"{$label}\"{$width} />\n";
    }

    protected function defaultOf(string $tsType, string $component): string
    {
        if ($tsType === 'boolean') {
            return 'false';
        }
        if ($tsType === 'number') {
            return $component === 'el-input-number' ? 'null' : '0';
        }

        return "''";
    }

    // —— 追加块（返回已渲染内容） ——

    protected function routesBlock(array $tokens): string
    {
        $ctrl = '\\Addons\\{{ADDON}}\\Http\\Controllers\\{{MODEL}}Controller';

        return strtr(
            "Route::get('{{ROUTE_PATH}}', [{$ctrl}::class, 'index'])->middleware('permission:{{PERM}}.index');\n"
            ."Route::get('{{ROUTE_PATH}}/{id}', [{$ctrl}::class, 'show'])->middleware('permission:{{PERM}}.index');\n"
            ."Route::post('{{ROUTE_PATH}}', [{$ctrl}::class, 'store'])->middleware('permission:{{PERM}}.store');\n"
            ."Route::put('{{ROUTE_PATH}}/{id}', [{$ctrl}::class, 'update'])->middleware('permission:{{PERM}}.update');\n"
            ."Route::delete('{{ROUTE_PATH}}/{id}', [{$ctrl}::class, 'destroy'])->middleware('permission:{{PERM}}.destroy');",
            $tokens
        );
    }

    protected function permissionsBlock(array $tokens): string
    {
        return strtr(
            "    '{{PERM}}.index',\n    '{{PERM}}.store',\n    '{{PERM}}.update',\n    '{{PERM}}.destroy',",
            $tokens
        );
    }

    protected function langBlock(array $tokens): string
    {
        // 键必须是不带插件前缀的内层键：带点的 "cms.notice" 既是非法 JS 标识符，
        // 加引号后也会落在 zh-cn.cms["cms.notice"] 错误命名空间层级
        return strtr("  {{LANG_KEY_INNER}}: {\n    title: '{{MENU_TITLE}}',\n  },", $tokens);
    }

    protected function menusBlock(array $tokens): string
    {
        return strtr(
            "            [\n"
            ."                'name' => '{{MENU_NAME}}', 'title' => '{{MENU_TITLE}}', 'icon' => 'Document',\n"
            ."                'route_path' => '/{{ADDON}}/{{VIEW_DIR}}', 'view_path' => '{{VIEW_DIR}}/index',\n"
            ."                'permission' => '{{PERM}}.index', 'sort' => 10,\n"
            .'            ],',
            $tokens
        );
    }

    // —— 标记对读写 ——

    protected function markerBlock(string $table, string $renderedBody): string
    {
        return "// ark:crud:{$table}:start\n".rtrim($renderedBody)."\n// ark:crud:{$table}:end";
    }

    /**
     * 追加类文件的幂等写入：已有本表标记对 → 整块替换；无标记对 → 按文件形态插入
     * （routes 追加文件尾；permissions/lang 插入最后一个收尾符前）。
     */
    protected function writeMarkerRegion(string $file, string $table, string $renderedBody, bool $appendAtEnd = false, ?string $beforeLast = null): void
    {
        $content = is_file($file) ? (string) file_get_contents($file) : '';
        $block = $this->markerBlock($table, $renderedBody);
        $start = "// ark:crud:{$table}:start";
        $end = "// ark:crud:{$table}:end";

        if (str_contains($content, $start)) {
            $re = '/'.preg_quote($start, '/').'.*?'.preg_quote($end, '/').'/s';
            file_put_contents($file, (string) preg_replace($re, $block, $content));

            return;
        }
        if (! $appendAtEnd && $beforeLast !== null && ($pos = strrpos($content, $beforeLast)) !== false) {
            file_put_contents($file, substr($content, 0, $pos).$block."\n".substr($content, $pos));

            return;
        }
        file_put_contents($file, rtrim($content)."\n\n".$block."\n");
    }

    /**
     * menus.php：条目必须落在根菜单 children 数组内，结构嵌套不做盲目插入——
     * 需一次性植入通用标记对 `// ark:crud:menus:start/end`（缺失时报错并给出示例）；
     * 本表条目再以 per-table 标记对做幂等替换。
     */
    protected function writeMenusEntry(string $file, string $table, string $renderedBody): void
    {
        $content = (string) file_get_contents($file);
        $start = "// ark:crud:{$table}:start";
        $end = "// ark:crud:{$table}:end";
        $block = $this->markerBlock($table, $renderedBody);

        if (str_contains($content, $start)) {
            $re = '/'.preg_quote($start, '/').'.*?'.preg_quote($end, '/').'/s';
            file_put_contents($file, (string) preg_replace($re, $block, $content));

            return;
        }
        $genericEnd = '// ark:crud:menus:end';
        file_put_contents($file, (string) preg_replace(
            '/'.preg_quote($genericEnd, '/').'/',
            $block."\n".$genericEnd,
            $content,
            1
        ));
    }

    /** menus 标记前置校验（任何文件写入之前调用，防半生成状态） */
    protected function precheckMenus(string $menusFile, string $table): void
    {
        $content = is_file($menusFile) ? (string) file_get_contents($menusFile) : '';
        if (str_contains($content, "// ark:crud:{$table}:start")
            || (str_contains($content, '// ark:crud:menus:start') && str_contains($content, '// ark:crud:menus:end'))) {
            return;
        }
        throw new CrudException(
            "menus.php 缺少生成器标记对。请在根菜单 children 数组内加入：\n"
            ."            // ark:crud:menus:start\n            // ark:crud:menus:end\n然后重新执行 ark:crud"
        );
    }

    protected function render(string $stub, array $tokens): string
    {
        $path = base_path('stubs/crud/'.$stub.'.stub');

        return strtr((string) file_get_contents($path), $tokens);
    }
}
