<?php

namespace Addons\cms\Listeners;

use Addons\cms\Models\Article;
use App\Admin\Events\AttachmentDeleted;

/** 素材删除联动：清空以其为封面的文章引用，避免死链（正文插图 URL 无法反查，属已知限制） */
class ClearDeletedCover
{
    public function handle(AttachmentDeleted $event): void
    {
        Article::query()->where('cover', $event->attachment->path)->update(['cover' => '']);
    }
}
