<?php

use App\Support\Addon\Models\Addon;
use Illuminate\Support\Facades\Schema;

it('创建 addons 注册表', function () {
    expect(Schema::hasTable('addons'))->toBeTrue();
});

it('注册表模型以 name 为主键并正确 cast', function () {
    $record = Addon::create([
        'name' => 'alpha', 'title' => 'A', 'version' => '0.1.0',
        'config' => ['k' => 'v'], 'enabled' => true, 'install_time' => now(),
    ]);
    expect($record->getKey())->toBe('alpha')
        ->and($record->enabled)->toBeTrue()
        ->and($record->config)->toBe(['k' => 'v'])
        ->and($record->install_time)->toBeInstanceOf(Illuminate\Support\Carbon::class);
});

it('框架版本配置就绪', function () {
    expect(config('arkadmin.version'))->toBeString()
        ->and(config('arkadmin.version'))->not->toBe('')
        ->and(is_dir(base_path('addons')))->toBeTrue()
        ->and(is_dir(base_path('addons')) && realpath(base_path('addons')) !== false)->toBeTrue();
});
