<?php

namespace App\Admin\Events;

use App\Admin\Models\Admin;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * §5.5 框架公开埋点：管理端写操作与登录成败（harness 规格 §3.3）。
 * 由 LogAdminOperation 中间件自动派发（写方法），登录成败由 AuthController 显式派发；
 * admin 为 null 表示未认证上下文（登录失败/未登录写请求）。
 * 参数已按遮蔽表脱敏。删改本事件字段视为破坏性变更。
 */
class AdminOperationLogged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ?Admin $admin,
        public string $method,
        public string $path,
        public int $status,
        public float $durationMs,
        public array $params,
        public ?string $ip,
    ) {}
}
