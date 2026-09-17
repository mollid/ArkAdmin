<?php

use App\Admin\Http\Middleware\LogAdminOperation;
use App\Admin\Models\Admin;
use App\Providers\AddonBootServiceProvider;
use App\Support\Addon\Console\AddonCacheCommand;
use App\Support\Addon\Console\AddonClearCommand;
use App\Support\Addon\Console\AddonDisableCommand;
use App\Support\Addon\Console\AddonEnableCommand;
use App\Support\Addon\Console\AddonInstallCommand;
use App\Support\Addon\Console\AddonListCommand;
use App\Support\Addon\Console\AddonUpgradeCommand;
use App\Support\Addon\Console\AddonUninstallCommand;
use App\Support\Addon\Console\ArkSyncCommand;
use App\Support\Crud\Console\ArkCrudCommand;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withProviders([AddonBootServiceProvider::class])
    ->withCommands([
        AddonListCommand::class,
        AddonInstallCommand::class,
        AddonUninstallCommand::class,
        AddonEnableCommand::class,
        AddonDisableCommand::class,
        AddonCacheCommand::class,
        AddonClearCommand::class,
        AddonUpgradeCommand::class,
        ArkSyncCommand::class,
        ArkCrudCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // API-only：未认证请求不做 web 登录跳转（默认 redirectGuestsTo(route('login')) 会因无该路由抛 500）
        $middleware->redirectGuestsTo(null);
        // harness 规格 §3.3：写操作自动埋点（全局栈，事件消费在 op-logs 系统插件）
        $middleware->append(LogAdminOperation::class);
        $middleware->alias([
            'permission' => PermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $envelope = fn (int $code, string $msg, $data = null) => response()->json(
            ['code' => $code, 'data' => $data, 'msg' => $msg], $code === 401 ? 401 : ($code === 422 ? 422 : 200)
        );
        $exceptions->render(function (AuthenticationException $e, $request) use ($envelope) {
            return $envelope(401, $e->getMessage() ?: '未登录或登录已过期');
        });
        $exceptions->render(function (ValidationException $e, $request) use ($envelope) {
            return $envelope(422, $e->getMessage(), $e->errors());
        });
        $exceptions->render(function (HttpExceptionInterface $e, $request) use ($envelope) {
            return $envelope($e->getStatusCode(), $e->getMessage() ?: '请求错误');
        });
    })->create();

// 超管旁路：super_admin 角色通过任何 Gate 检查（Laravel v13 无 withGate，以 booting 钩子等效注册）
$app->booting(function () {
    Gate::before(function ($user, string $ability) {
        return $user instanceof Admin
            && $user->hasRole(config('arkadmin.super_role', 'super_admin')) ? true : null;
    });
});

return $app;
