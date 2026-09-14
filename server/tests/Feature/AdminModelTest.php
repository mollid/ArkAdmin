<?php

use App\Admin\Models\Admin;
use Illuminate\Support\Facades\Hash;

it('creates admin with hashed password', function () {
    $a = Admin::create(['username' => 't1', 'password' => 'secret123', 'name' => 'T', 'status' => 1]);
    expect(Hash::check('secret123', $a->password))->toBeTrue()
        ->and($a->password)->not->toBe('secret123');
});

it('hides password in array', function () {
    $a = Admin::create(['username' => 't2', 'password' => 'secret123', 'status' => 1]);
    expect($a->toArray())->not->toHaveKey('password');
});
