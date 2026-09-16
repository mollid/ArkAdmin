<?php

namespace Addons\cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Article extends Model
{
    protected $table = 'cms_articles';

    /**
     * 正文长度上限（字符，策略值）：正文插图走素材库引用、不内联 base64，
     * 上限只用于挡低权编辑者灌入超大载荷（写库前还要过 RichTextSanitizer 的 DOM 解析）。
     * 放宽需同步评估净化开销，故与两个 FormRequest 共用此处常量。
     */
    public const CONTENT_MAX = 200000;

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
            ? $this->attachmentDisk()->url($this->cover)
            : '';
    }

    /**
     * 正文读取期归一插图域名：content 里存的是写入时的绝对地址（含当时的 APP_URL/主机名），
     * 换环境（dev `localhost:8080` → 生产域名）后按当前素材盘前缀重写，历史正文插图才不会集体失效。
     * 只改写含 `/storage/` 的本站地址，外链图片原样返回；写入路径（净化/存储）不受影响。
     */
    public function getContentAttribute(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }
        $prefix = rtrim($this->attachmentDisk()->url(''), '/').'/';

        return (string) preg_replace('#(?:https?:)?//[^/"\']+/storage/#', $prefix, $value);
    }

    protected function attachmentDisk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk((string) config('arkadmin.attachment.disk', 'public'));
    }
}
