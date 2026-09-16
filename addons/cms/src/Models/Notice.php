<?php

namespace Addons\cms\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ark:crud 生成（表 cms_notices）：可再手工修改，重新生成需 --force 覆盖。
 */
class Notice extends Model
{
    protected $table = 'cms_notices';

    protected $fillable = [
        'title',
        'body',
        'is_pinned',
    ];

    protected $casts = [
        'is_pinned' => 'boolean',
    ];
}
