<?php

namespace App\Support\Addon\Models;

use Illuminate\Database\Eloquent\Model;

/** addons 注册表（§5.4）。name 即插件目录名/info.json name */
class Addon extends Model
{
    protected $table = 'addons';

    protected $primaryKey = 'name';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['name', 'title', 'version', 'config', 'enabled', 'install_time'];

    protected function casts(): array
    {
        return ['config' => 'array', 'enabled' => 'boolean', 'install_time' => 'datetime'];
    }
}
