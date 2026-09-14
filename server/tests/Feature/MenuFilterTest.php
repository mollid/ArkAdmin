<?php

use App\Admin\Models\Admin;
use App\Admin\Seeds\MenuSeeder;
use App\Admin\Seeds\RbacSeeder;
use App\Admin\Services\MenuService;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    (new RbacSeeder)->run();
    (new MenuSeeder)->run();
});

it('super admin sees all menus', function () {
    $tree = (new MenuService)->treeFor(Admin::where('username', 'admin')->first());
    expect(collect($tree)->pluck('name'))->toContain('dashboard', 'system');
});

it('admin without permission sees no system menus', function () {
    $u = Admin::create(['username' => 'noperm', 'password' => 'x123456', 'status' => 1]);
    $tree = (new MenuService)->treeFor($u);
    expect(collect($tree)->pluck('name'))->toContain('dashboard')
        ->and(collect($tree)->pluck('name'))->not->toContain('system');
});

it('admin with role permission sees only permitted children', function () {
    $u = Admin::create(['username' => 'half', 'password' => 'x123456', 'status' => 1]);
    $role = Role::create(['name' => 'admin_mgr', 'guard_name' => 'admin']);
    $role->syncPermissions(['system.admin.index', 'system.admin.store']);
    $u->assignRole($role);

    $tree = (new MenuService)->treeFor($u);
    $sys = collect($tree)->firstWhere('name', 'system');
    expect($sys)->not->toBeNull()
        ->and(collect($sys['children'])->pluck('name')->all())->toEqual(['system.admin']);
});
