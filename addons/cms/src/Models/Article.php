<?php

namespace Addons\cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Article extends Model
{
    protected $table = 'cms_articles';

    protected $fillable = [
        'category_id', 'title', 'summary', 'content', 'cover', 'tags', 'status', 'published_at',
    ];

    protected $casts = [
        'category_id' => 'integer',
        // §5.4：多值字段用 PG 原生类型（jsonb），禁止逗号分隔字符串
        'tags' => 'array',
        'status' => 'integer',
        'published_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
