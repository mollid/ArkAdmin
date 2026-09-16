<?php

namespace Addons\cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

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

    protected $appends = ['cover_url'];

    /** 封面展示地址：cover 存 attachments.path，url 推导与框架 Attachment::url 同规则 */
    public function getCoverUrlAttribute(): string
    {
        return $this->cover !== ''
            ? Storage::disk((string) config('arkadmin.attachment.disk', 'public'))->url($this->cover)
            : '';
    }
}
