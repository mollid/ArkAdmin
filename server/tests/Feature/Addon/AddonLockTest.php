<?php

use App\Support\Addon\AddonException;
use App\Support\Addon\AddonInstaller;
use App\Support\Addon\AddonManager;
use App\Support\Addon\Models\Addon;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')]);
});

afterEach(function () {
    app(AddonManager::class)->flushCompiled();
    remove_dir(storage_path('framework/addon-fixture'));
});

it('持锁期间同插件操作被拒，锁释放后恢复', function () {
    make_addon_dir('locka');
    $lock = Cache::lock('arkadmin:addon:locka', 60);
    expect($lock->get())->toBeTrue();

    try {
        app(AddonInstaller::class)->install('locka');
        $this->fail('应当拒绝');
    } catch (AddonException $e) {
        expect($e->getMessage())->toContain('另一操作');
    }
    expect(Addon::find('locka'))->toBeNull();

    $lock->release();
    app(AddonInstaller::class)->install('locka');
    expect(Addon::find('locka'))->not->toBeNull();
});

it('操作失败后锁被释放（可立即重试）', function () {
    make_addon_dir('lockb', ['dependencies' => ['ghost_dep']]);
    try {
        app(AddonInstaller::class)->install('lockb');
        $this->fail('应当拒绝');
    } catch (AddonException) {
        // 依赖缺失被拒：业务错误路径，锁必须在 finally 里已释放
    }
    $reacquire = Cache::lock('arkadmin:addon:lockb', 60);
    expect($reacquire->get())->toBeTrue();
    $reacquire->release();
});

it('不同插件操作互不阻塞', function () {
    make_addon_dir('lockc');
    $lock = Cache::lock('arkadmin:addon:lockd', 60);
    expect($lock->get())->toBeTrue();

    app(AddonInstaller::class)->install('lockc');    // lockd 的锁不挡 lockc
    expect(Addon::find('lockc'))->not->toBeNull();
    $lock->release();
});
