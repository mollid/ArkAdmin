<?php

namespace App\Admin\Events;

use App\Admin\Models\Attachment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * §5.5 框架公开埋点：素材保存后派发，插件可监听。
 * 事件清单属于框架公开接口，删除/改名视为破坏性变更。
 */
class AttachmentSaved
{
    use Dispatchable, SerializesModels;

    public function __construct(public Attachment $attachment)
    {
    }
}
