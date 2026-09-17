<?php

namespace App\Admin\Models;

use Illuminate\Database\Eloquent\Model;

/** settings 表（§5.4）：value 的编解码由 SettingStore 负责（jsonb 标量/对象混合存储） */
class Setting extends Model
{
    protected $table = 'settings';

    protected $fillable = ['key', 'value', 'addon_key'];
}
