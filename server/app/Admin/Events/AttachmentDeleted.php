<?php

namespace App\Admin\Events;

use App\Admin\Models\Attachment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * §5.5 框架公开埋点：素材删除后派发（模型行已删，实例保留原字段供插件反查引用）。
 * 插件可监听以清理自身对素材的引用（如 CMS 文章封面）。删除/改名视为破坏性变更。
 *
 * 仅作同步消费：模型行此时已不存在，监听器若排入队列，`SerializesModels` 还原实例会因查不到行而失败；
 * 需要异步处理时请改用标量载荷（path/disk/id）或自行在监听器内投影后入队。
 */
class AttachmentDeleted
{
    use Dispatchable, SerializesModels;

    public function __construct(public Attachment $attachment)
    {
    }
}
