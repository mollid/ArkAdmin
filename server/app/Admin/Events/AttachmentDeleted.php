<?php

namespace App\Admin\Events;

use App\Admin\Models\Attachment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * §5.5 框架公开埋点：素材删除后派发（模型行已删，实例保留原字段供插件反查引用）。
 * 插件可监听以清理自身对素材的引用（如 CMS 文章封面）。删除/改名视为破坏性变更。
 */
class AttachmentDeleted
{
    use Dispatchable, SerializesModels;

    public function __construct(public Attachment $attachment)
    {
    }
}
