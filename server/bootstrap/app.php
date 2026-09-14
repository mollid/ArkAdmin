<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Gate;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API-only：未认证请求不做 web 登录跳转（默认 redirectGuestsTo(route('login')) 会因无该路由抛 500）
        $middleware->redirectGuestsTo(null);
        $middleware->alias([
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $envelope = fn (int $code, string $msg, $data = null) => response()->json(
            ['code' => $code, 'data' => $data, 'msg' => $msg], $code === 401 ? 401 : ($code === 422 ? 422 : 200)
        );
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) use ($envelope) {
            return $envelope(401, $e->getMessage() ?: '未登录或登录已过期');
        });
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, $request) use ($envelope) {
            return $envelope(422, $e->getMessage(), $e->errors());
        });
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e, $request) use ($envelope) {
            return $envelope($e->getStatusCode(), $e->getMessage() ?: '请求错误');
        });
    })->create();

// 超管旁路：super_admin 角色通过任何 Gate 检查（Laravel v13 无 withGate，以 booting 钩子等效注册）
$app->booting(function () {
    Gate::before(function ($user, string $ability) {
        return $user instanceof \App\Admin\Models\Admin && $user->hasRole('super_admin') ? true : null;
    });
});

return $app;
