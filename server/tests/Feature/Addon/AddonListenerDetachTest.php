<?php

use App\Admin\Events\AdminLoginSuccessed;
use App\Support\Addon\AddonInstaller;
use App\Support\Addon\AddonManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

afterEach(function () {
    app(AddonManager::class)->flushCompiled();
    remove_dir(storage_path('framework/addon-fixture'));
});

/** 生成监听 AdminLoginSuccessed 的 fixture 插件：provider $listen + 监听器 + 登录记录表 */
function make_listening_addon(string $name): string
{
    $dir = make_addon_dir($name);
    mkdir($dir.'/database/migrations', 0777, true);
    foreach ([
        "/src/AddonServiceProvider.php" => <<<PHP
        <?php

        namespace Addons\\{$name};

        class AddonServiceProvider extends \App\Support\Addon\AddonServiceProvider
        {
            protected array \$listen = [
                \App\Admin\Events\AdminLoginSuccessed::class => [
                    \Addons\\{$name}\Listeners\RecordLogin::class,
                ],
            ];
        }
        PHP,
        '/src/Listeners/RecordLogin.php' => <<<PHP
        <?php

        namespace Addons\\{$name}\Listeners;

        class RecordLogin
        {
            public function handle(\App\Admin\Events\AdminLoginSuccessed \$event): void
            {
                \Illuminate\Support\Facades\DB::table('{$name}_logins')->insert([
                    'admin_id' => \$event->admin->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
        PHP,
        "/database/migrations/2026_09_15_000001_create_{$name}_logins_table.php" => <<<PHP
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::create('{$name}_logins', function (Blueprint \$table) {
                    \$table->id();
                    \$table->unsignedBigInteger('admin_id');
                    \$table->timestamps();
                });
            }

            public function down(): void
            {
                Schema::dropIfExists('{$name}_logins');
            }
        };
        PHP,
    ] as $file => $content) {
        $path = $dir.$file;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, preg_replace('/^ +/m', '', $content));
    }

    return $dir;
}

it('disable 只摘除自身监听，同事件的兄弟插件监听不受影响', function () {
    (new App\Admin\Seeds\RbacSeeder)->run();
    config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')]);
    make_listening_addon('lista');
    make_listening_addon('listb');

    $installer = app(AddonInstaller::class);
    $installer->install('lista'); // 安装收尾注册 provider，监听在本进程生效
    $installer->install('listb');
    expect(class_exists(\Addons\lista\AddonServiceProvider::class))->toBeTrue();

    event(new AdminLoginSuccessed(super_admin()));
    expect(DB::table('lista_logins')->count())->toBe(1)
        ->and(DB::table('listb_logins')->count())->toBe(1);

    $installer->disable('lista');
    event(new AdminLoginSuccessed(super_admin()));
    // lista 不再记录；listb 必须继续收到同事件
    expect(DB::table('lista_logins')->count())->toBe(1)
        ->and(DB::table('listb_logins')->count())->toBe(2)
        ->and(Schema::hasTable('listb_logins'))->toBeTrue();
});
