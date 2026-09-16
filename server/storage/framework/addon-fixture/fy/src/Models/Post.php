<?php

namespace Addons\fy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ark:crud 生成（表 fy_posts）：可再手工修改，重新生成需 --force 覆盖。
 */
class Post extends Model
{
    protected $table = 'fy_posts';

    protected $fillable = [
        'title',
    ];

    protected $casts = [
        // 无需 cast 的列
    ];
}
