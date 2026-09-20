<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// make_crud_addon / create_items_table 共享助手在 tests/Pest.php（M5 生成器测试通用）

beforeEach(function () {
    config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')]);
    make_crud_addon('fx');
    create_items_table();
});

it('ark:crud 生成全套模块，追加块幂等落位，PHP 全部语法合法', function () {
    $this->artisan('ark:crud', ['--table' => 'fx_items'])->assertExitCode(0);

    $dir = storage_path('framework/addon-fixture/fx');
    foreach ([
        'src/Models/Item.php',
        'src/Services/ItemService.php',
        'src/Http/Controllers/ItemController.php',
        'src/Http/Requests/ItemStoreRequest.php',
        'src/Http/Requests/ItemUpdateRequest.php',
        'admin/api/item.ts',
        'admin/views/item/index.vue',
    ] as $rel) {
        expect(is_file($dir.'/'.$rel))->toBeTrue("缺少 {$rel}");
    }

    // 生成的 PHP 全部语法合法（防止模板错误产出废码）
    $phpFiles = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir.'/src'));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $phpFiles[] = $f->getPathname();
        }
    }
    expect($phpFiles)->not->toBeEmpty();
    foreach ($phpFiles as $file) {
        exec('php -l '.escapeshellarg($file), $out, $code);
        expect($code)->toBe(0, "语法错误：{$file}\n".implode("\n", $out));
    }

    $model = file_get_contents($dir.'/src/Models/Item.php');
    expect($model)->toContain("protected \$table = 'fx_items'")
        ->toContain("'title',")
        ->toContain("'views' => 'integer'");

    // 追加块：路由/权限/菜单/语言包
    $routes = file_get_contents($dir.'/routes/admin.php');
    expect($routes)->toContain('// ark:crud:fx_items:start')
        ->toContain('permission:addon.fx.item.index')
        ->toContain('\\Addons\\fx\\Http\\Controllers\\ItemController::class');

    $menus = file_get_contents($dir.'/database/menus.php');
    expect($menus)->toContain("'name' => 'fx.item'")
        ->toContain("'view_path' => 'item/index'")
        ->toContain("'title' => 'Items'");   // 菜单标题 = headline(表名去前缀)，列注释标签在 vue 页

    $permissions = file_get_contents($dir.'/database/permissions.php');
    expect($permissions)->toContain("'addon.fx.item.index'")
        ->toContain("'addon.fx.item.destroy'");

    $lang = file_get_contents($dir.'/admin/lang/zh-cn.ts');
    // 语言包键是不带插件前缀的内层键：带点的 "fx.item" 既是非法 JS 标识符，
    // 加引号也会落在 zh-cn.fx["fx.item"] 错误命名空间层级（页面 t('fx.item.title') 解析不到）
    expect($lang)->toContain("  item: {")
        ->not->toContain('fx.item');

    // PUT 部分语义：update 规则一律 sometimes、无 required（M4 I1 教训）
    $update = file_get_contents($dir.'/src/Http/Requests/ItemUpdateRequest.php');
    expect($update)->toContain("'sometimes'")
        ->not->toContain("'required'");

    // 前端页面消费生成物约定
    $vue = file_get_contents($dir.'/admin/views/item/index.vue');
    expect($vue)->toContain('v-permission="\'addon.fx.item.store\'"')
        ->toContain("t('fx.item.title')");

    $api = file_get_contents($dir.'/admin/api/item.ts');
    expect($api)->toContain('/addon/fx/items')
        ->toContain('is_top: boolean');
});

it('重复生成需 --force；--force 幂等且标记对不重复', function () {
    $this->artisan('ark:crud', ['--table' => 'fx_items'])->assertExitCode(0);

    $this->artisan('ark:crud', ['--table' => 'fx_items'])
        ->expectsOutputToContain('--force')
        ->assertExitCode(1);

    $this->artisan('ark:crud', ['--table' => 'fx_items', '--force' => true])->assertExitCode(0);

    $routes = file_get_contents(storage_path('framework/addon-fixture/fx/routes/admin.php'));
    expect(substr_count($routes, '// ark:crud:fx_items:start'))->toBe(1);

    $menus = file_get_contents(storage_path('framework/addon-fixture/fx/database/menus.php'));
    expect(substr_count($menus, "'name' => 'fx.item'"))->toBe(1);
});

it('AUDIT-D5 追加类文件缺失时生成合法骨架而非裸块', function () {
    $dir = storage_path('framework/addon-fixture/fx');
    // 文档合法形态：只有 menus.php（含通用标记对）是必配，permissions/routes/lang 均可缺省
    @unlink($dir.'/database/permissions.php');
    @unlink($dir.'/routes/admin.php');
    @unlink($dir.'/admin/lang/zh-cn.ts');

    $this->artisan('ark:crud', ['--table' => 'fx_items'])->assertExitCode(0);

    // permissions.php：裸块（无 <?php/return）会被 install/upgrade 的「必须返回字符串数组」卡死
    $perm = file_get_contents($dir.'/database/permissions.php');
    expect($perm)->toContain('<?php')
        ->toContain('return [')
        ->toContain("'addon.fx.item.index'");
    exec('php -l '.escapeshellarg($dir.'/database/permissions.php'), $out, $code);
    expect($code)->toBe(0, "骨架语法错误：\n".implode("\n", $out));

    $routes = file_get_contents($dir.'/routes/admin.php');
    expect($routes)->toContain('<?php')
        ->toContain('use Illuminate\Support\Facades\Route;')
        ->toContain('permission:addon.fx.item.index');

    $lang = file_get_contents($dir.'/admin/lang/zh-cn.ts');
    expect($lang)->toContain('export default {')
        ->toContain('  item: {');
});

it('表名必须以插件前缀开头', function () {
    $this->artisan('ark:crud', ['--table' => 'other_items', '--addon' => 'fx'])
        ->expectsOutputToContain('前缀')
        ->assertExitCode(1);
});

it('menus.php 缺通用标记对时报错且不留下半生成状态', function () {
    $dir = make_crud_addon('fy', withMenuMarkers: false);
    Schema::dropIfExists('fy_posts');
    Schema::create('fy_posts', function (Blueprint $t) {
        $t->id();
        $t->string('title');
        $t->timestamps();
    });

    $this->artisan('ark:crud', ['--table' => 'fy_posts', '--addon' => 'fy'])
        ->expectsOutputToContain('ark:crud:menus:start')
        ->assertExitCode(1);

    // 防半生成：前置校验必须先于任何文件写入
    expect(is_file($dir.'/src/Models/Post.php'))->toBeFalse()
        ->and(is_file($dir.'/admin/api/post.ts'))->toBeFalse()
        ->and(is_file($dir.'/routes/admin.php'))->toBeTrue();
});

it('AUDIT-D5b snake 归一撞车时 --force 拒绝静默覆盖前表模块', function () {
    Schema::dropIfExists('fx_article');
    Schema::create('fx_article', function (Blueprint $t) {
        $t->id();
        $t->string('title');
        $t->timestamps();
    });
    Schema::dropIfExists('fx_articles');
    Schema::create('fx_articles', function (Blueprint $t) {
        $t->id();
        $t->string('title');
        $t->timestamps();
    });

    $this->artisan('ark:crud', ['--table' => 'fx_article'])->assertExitCode(0);

    // fx_articles 与 fx_article 归一为同一模型 Article：无 --force 被文件存在守卫挡，
    // --force 必须被撞车检测挡（否则前表整套模块连同菜单/权限串被静默归并）
    $this->artisan('ark:crud', ['--table' => 'fx_articles'])
        ->expectsOutputToContain('--force')
        ->assertExitCode(1);
    $this->artisan('ark:crud', ['--table' => 'fx_articles', '--force' => true])
        ->expectsOutputToContain('归一')
        ->assertExitCode(1);
    expect(file_get_contents(storage_path('framework/addon-fixture/fx').'/src/Models/Article.php'))
        ->toContain("protected \$table = 'fx_article'");
});

it('AUDIT-D6a 写盘失败时明确报错而非谎报已生成', function () {
    $dir = storage_path('framework/addon-fixture/fx');

    // 目录无法创建：src/ 只读 → src/Models 建不出来（0555 须落在新建目录的直接父级）
    chmod($dir.'/src', 0555);
    try {
        $this->artisan('ark:crud', ['--table' => 'fx_items'])
            ->expectsOutputToContain('无法创建')
            ->assertExitCode(1);
    } finally {
        chmod($dir.'/src', 0777);
    }

    // 文件写入失败：目标目录存在但只读 → 模型文件写不进去
    @mkdir($dir.'/src/Models', 0777, true);
    chmod($dir.'/src/Models', 0555);
    try {
        $this->artisan('ark:crud', ['--table' => 'fx_items'])
            ->expectsOutputToContain('写入失败')
            ->assertExitCode(1);
    } finally {
        chmod($dir.'/src/Models', 0777);
    }
    expect(is_file($dir.'/src/Models/Item.php'))->toBeFalse();
});
