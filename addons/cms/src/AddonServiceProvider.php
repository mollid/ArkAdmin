<?php

namespace Addons\cms;

use Addons\cms\Listeners\ClearDeletedCover;
use App\Admin\Events\AttachmentDeleted;
use App\Support\Addon\AddonServiceProvider as BaseProvider;

class AddonServiceProvider extends BaseProvider
{
    /** 监听框架公开埋点：素材删除时清空文章封面引用 */
    protected array $listen = [
        AttachmentDeleted::class => [
            ClearDeletedCover::class,
        ],
    ];
}
