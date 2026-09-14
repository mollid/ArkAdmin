<?php

use App\Admin\Models\Admin;
use App\Admin\Seeds\RbacSeeder;
use Illuminate\Support\Facades\Gate;

it('super admin passes any gate', function () {
    (new RbacSeeder)->run();
    $super = Admin::where('username', 'admin')->first();
    expect(Gate::forUser($super)->allows('system.menu.index'))->toBeTrue();
});

it('seeder is idempotent', function () {
    (new RbacSeeder)->run();
    (new RbacSeeder)->run();
    expect(Admin::where('username', 'admin')->count())->toBe(1)
        ->and(\Spatie\Permission\Models\Role::where('name', 'super_admin')->count())->toBe(1);
});
