<?php

namespace Addons\demo\Listeners;

use App\Admin\Events\AdminLoginSuccessed;
use Illuminate\Support\Facades\DB;

class RecordAdminLogin
{
    /** 登录成功写一条便签：插件监听框架事件的端到端证明 */
    public function handle(AdminLoginSuccessed $event): void
    {
        DB::table('demo_notes')->insert([
            'admin_id' => $event->admin->id,
            'content' => 'login:'.$event->admin->username,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
