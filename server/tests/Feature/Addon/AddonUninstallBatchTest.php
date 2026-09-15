<?php

use App\Support\Addon\AddonInstaller;
use App\Support\Addon\AddonManager;
use Illuminate\Support\Facades\Schema;

afterEach(function () {
    app(AddonManager::class)->flushCompiled();
    remove_dir(storage_path('framework/addon-fixture'));
});

/** 生成只有迁移（自建业务表）的 fixture 插件，无需 provider/菜单 */
function make_table_addon(string $name): string
{
    $dir = make_addon_dir($name);
    mkdir($dir.'/database/migrations', 0777, true);
    file_put_contents($dir."/database/migrations/2026_09_15_000001_create_{$name}_things_table.php", <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{$name}_things', function (Blueprint \$table) {
            \$table->id();
            \$table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$name}_things');
    }
};
PHP);

    return $dir;
}

it('uninstall rolls back migrations even when not in the most recent batch', function () {
    config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')]);
    make_table_addon('batcha');
    make_table_addon('batchb');

    $installer = app(AddonInstaller::class);
    $installer->install('batcha');
    // batchb 后装，其迁移批次号更大 → batcha 不在"最后一批"
    $installer->install('batchb');
    expect(Schema::hasTable('batcha_things'))->toBeTrue()
        ->and(Schema::hasTable('batchb_things'))->toBeTrue();

    $installer->disable('batcha');
    $installer->uninstall('batcha');

    expect(Schema::hasTable('batcha_things'))->toBeFalse()
        ->and(Schema::hasTable('batchb_things'))->toBeTrue();
});

it('uninstall cleans up the orphaned migrations table rows', function () {
    config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')]);
    make_table_addon('batcha');
    make_table_addon('batchb');

    $installer = app(AddonInstaller::class);
    $installer->install('batcha');
    $installer->install('batchb');
    $installer->disable('batcha');
    $installer->uninstall('batcha');

    $leftover = DB::table('migrations')->where('migration', 'like', '%_create_batcha_things_table')->count();
    expect($leftover)->toBe(0)
        ->and(DB::table('migrations')->where('migration', 'like', '%_create_batchb_things_table')->count())->toBe(1);
});
