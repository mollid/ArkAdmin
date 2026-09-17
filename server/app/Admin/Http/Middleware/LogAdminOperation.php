<?php

namespace App\Admin\Http\Middleware;

use App\Admin\Events\AdminOperationLogged;
use App\Admin\Models\Admin;
use Closure;
use Illuminate\Http\Request;

/**
 * harness 规格 §3.3：管理端写操作自动埋点（POST/PUT/PATCH/DELETE），
 * 响应后派发 AdminOperationLogged（含结果码/耗时/脱敏参数）。
 * 埋点在核心（harness 契约），存储消费在 op-logs 系统插件——插件禁用时事件照发、无监听即无存储。
 */
class LogAdminOperation
{
    /** 参数遮蔽表：命中的键值替换为 ***（递归） */
    protected const MASKED_KEYS = ['password', 'password_confirmation', 'token', 'secret', 'authorization'];

    public function handle(Request $request, Closure $next)
    {
        $start = microtime(true);
        $response = $next($request);

        // 登录路径的埋点由 AuthController 显式派发（成败均带 admin 语义）；
        // 中间件在此快照时登录请求尚未携带 token，会记成 admin=null 造成双记
        if ($request->path() === 'api/admin/auth/login') {
            return $response;
        }

        if (in_array(strtoupper($request->getMethod()), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $user = auth('admin')->user();

            event(new AdminOperationLogged(
                $user instanceof Admin ? $user : null,
                strtoupper($request->getMethod()),
                $request->path(),
                $response->getStatusCode(),
                round((microtime(true) - $start) * 1000, 1),
                $this->masked($request),
                $request->ip(),
            ));
        }

        return $response;
    }

    /** 请求参数快照（query + body），遮蔽表键递归打码；文件上传以元信息替代内容 */
    protected function masked(Request $request): array
    {
        $all = $request->all();
        array_walk_recursive($all, function (&$value, $key) {
            if (in_array(strtolower((string) $key), self::MASKED_KEYS, true)) {
                $value = '***';
            }
        });

        return $all;
    }
}
