<?php

use App\Support\Addon\AddonException;
use App\Support\Addon\AddonInfo;

afterEach(function () {
    remove_dir(storage_path('framework/addon-fixture'));
});

it('合法版本号通过（含预发布后缀）', function (string $version) {
    $dir = make_addon_dir('verx', ['version' => $version]);
    expect(AddonInfo::fromDir($dir)->version)->toBe($version);
})->with(['0.1.0', '1.2.3', '10.20.30', '1.0.0-beta.1', '2.0.0-rc.1']);

it('非法版本号在清单解析期被拒', function (string $version) {
    $dir = make_addon_dir('very', ['version' => $version]);
    try {
        AddonInfo::fromDir($dir);
        $this->fail('应当拒绝');
    } catch (AddonException $e) {
        expect($e->getMessage())->toContain('version')->toContain('格式无效');
    }
})->with(['v1.0.0', '1.0', '1', '1.0.0.0', 'abc', '1.0.0 beta']);

it('support_version 同样校验', function () {
    $dir = make_addon_dir('verz', ['support_version' => '^1.0']);
    try {
        AddonInfo::fromDir($dir);
        $this->fail('应当拒绝');
    } catch (AddonException $e) {
        expect($e->getMessage())->toContain('support_version')->toContain('格式无效');
    }
});

it('捆绑插件清单全部合规（回归护栏）', function () {
    foreach (glob(base_path('addons/*'), GLOB_ONLYDIR) as $dir) {
        expect(AddonInfo::fromDir($dir))->toBeInstanceOf(AddonInfo::class);
    }
});
