<?php

namespace Addons\op_logs\Listeners;

use Addons\op_logs\Models\OpLog;
use App\Admin\Events\AdminOperationLogged;
use Illuminate\Support\Facades\Log;

/** 埋点事件 → 落库。日志失败绝不影响业务请求（吞异常记警告） */
class StoreAdminOperation
{
    public function handle(AdminOperationLogged $event): void
    {
        try {
            OpLog::create([
                'admin_id' => $event->admin?->id,
                'route' => $event->path,
                'method' => $event->method,
                'params' => $event->params,
                'status_code' => $event->status,
                'duration_ms' => $event->durationMs,
                'ip' => $event->ip,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('操作日志写入失败：'.$e->getMessage());
        }
    }
}
