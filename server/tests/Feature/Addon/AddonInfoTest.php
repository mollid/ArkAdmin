<?php

use App\Support\Addon\AddonException;
use App\Support\Addon\AddonInfo;

afterEach(function () {
    remove_dir(storage_path('framework/addon-fixture'));
});

it('解析合法 info.json 并提供路径/类名派生', function () {
    $info = AddonInfo::fromDir(make_addon_dir('alpha'));
    expect($info->name)->toBe('alpha')
        ->and($info->title)->toBe('测试插件')
        ->and($info->version)->toBe('0.1.0')
        ->and($info->supportVersion)->toBe('0.1.0')
        ->and($info->dependencies)->toBe([])
        ->and($info->providerClass())->toBe('Addons\alpha\AddonServiceProvider')
        ->and($info->addonClass())->toBe('Addons\alpha\Addon')
        ->and($info->routeFile())->toBe($info->dir.'/routes/admin.php')
        ->and($info->migrationPath())->toBe($info->dir.'/database/migrations')
        ->and($info->menusFile())->toBe($info->dir.'/database/menus.php')
        ->and($info->permissionsFile())->toBe($info->dir.'/database/permissions.php');
});

it('support_version 与框架版本比较', function () {
    $info = AddonInfo::fromDir(make_addon_dir('alpha', ['support_version' => '0.2.0']));
    expect($info->isSupported('0.2.0'))->toBeTrue()
        ->and($info->isSupported('0.1.0'))->toBeFalse()
        ->and($info->isSupported('1.0.0'))->toBeTrue();
});

it('缺失 info.json 抛异常', function () {
    $dir = storage_path('framework/addon-fixture/bare_'.uniqid());
    mkdir($dir, 0777, true);
    expect(fn () => AddonInfo::fromDir($dir))->toThrow(AddonException::class);
});

it('插件名必须匹配 ^[a-z][a-z0-9_]*$', function () {
    expect(fn () => AddonInfo::fromDir(make_addon_dir('alpha', ['name' => 'Bad-Name'])))->toThrow(AddonException::class)
        ->and(fn () => AddonInfo::fromDir(make_addon_dir('alpha', ['name' => ''])))->toThrow(AddonException::class);
});

it('type 当前仅支持 app', function () {
    expect(fn () => AddonInfo::fromDir(make_addon_dir('alpha', ['type' => 'theme'])))->toThrow(AddonException::class);
});

it('缺少必填字段抛异常', function () {
    expect(fn () => AddonInfo::fromDir(make_addon_dir('alpha', ['title' => null])))->toThrow(AddonException::class)
        ->and(fn () => AddonInfo::fromDir(make_addon_dir('alpha', ['support_version' => null])))->toThrow(AddonException::class);
});

it('缺少 src 目录抛异常', function () {
    expect(fn () => AddonInfo::fromDir(make_addon_dir('alpha', withSrc: false)))->toThrow(AddonException::class);
});

it('dependencies 非数组抛异常', function () {
    expect(fn () => AddonInfo::fromDir(make_addon_dir('alpha', ['dependencies' => 'cms'])))->toThrow(AddonException::class);
});
