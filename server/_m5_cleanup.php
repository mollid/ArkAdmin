<?php
/** M5 演示回退清理：开发库中的 notice 菜单/权限/表与生成的前端产物，跑完即删 */
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 菜单（生成条目）
App\Admin\Models\Menu::where('name', 'cms.notice')->delete();

// 权限（spatie 两张关联表 → 权限行）
$ids = DB::table('permissions')
    ->where('module', 'cms')
    ->where('name', 'like', 'addon.cms.notice.%')
    ->pluck('id');
if ($ids->isNotEmpty()) {
    DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
    DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
    DB::table('permissions')->whereIn('id', $ids)->delete();
}
app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

// 演示表
Schema::dropIfExists('cms_notices');

echo 'menus left='.App\Admin\Models\Menu::where('addon_key', 'cms')->count()
    .' perms left='.DB::table('permissions')->where('module', 'cms')->count()."\n";
