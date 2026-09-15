<?php

namespace App\Admin\Events;

use App\Admin\Models\Admin;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** §5.5 框架公开埋点：管理员登录成功。插件在 provider 的 $listen 中监听本事件 */
class AdminLoginSuccessed
{
    use Dispatchable, SerializesModels;

    public function __construct(public Admin $admin)
    {
    }
}
