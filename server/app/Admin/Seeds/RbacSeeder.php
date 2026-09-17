<?php

namespace App\Admin\Seeds;

use App\Admin\Models\Admin;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RbacSeeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $matrix = ['admin', 'role', 'menu', 'attachment'];
        foreach ($matrix as $m) {
            foreach (['index', 'store', 'update', 'destroy'] as $act) {
                Permission::firstOrCreate(
                    ['name' => "system.{$m}.{$act}", 'guard_name' => 'admin'],
                    ['module' => 'system']
                );
            }
        }
        // settings 基建只有读/写两个动作（管理页在 settings 系统插件）
        foreach (['index', 'update'] as $act) {
            Permission::firstOrCreate(
                ['name' => "system.setting.{$act}", 'guard_name' => 'admin'],
                ['module' => 'system']
            );
        }

        $super = Role::firstOrCreate(
            ['name' => config('arkadmin.super_role', 'super_admin'), 'guard_name' => 'admin']
        );
        $super->syncPermissions(Permission::where('guard_name', 'admin')->pluck('name'));

        Admin::firstOrCreate(
            ['username' => 'admin'],
            ['name' => '超级管理员', 'password' => '123456', 'status' => 1]
        )->assignRole($super);
    }
}
