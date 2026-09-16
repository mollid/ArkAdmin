<?php
/** M5 验收临时脚本：真实 HTTP 验证生成模块的菜单渲染与 CRUD 闭环，跑完即删 */
$login = Illuminate\Support\Facades\Http::post('http://nginx/api/admin/auth/login',
    ['username' => 'admin', 'password' => '123456'])->json();
$token = $login['data']['token'] ?? '';
echo "login ".($token !== '' ? 'ok' : 'FAIL')."\n";
$h = ['Authorization' => "Bearer $token"];

// 菜单树出现公告管理
$me = Illuminate\Support\Facades\Http::withHeaders($h)->get('http://nginx/api/admin/auth/me')->json();
$names = [];
array_walk_recursive($me['data']['menus'] ?? [], function ($v, $k) use (&$names) {
    if ($k === 'name') {
        $names[] = $v;
    }
});
echo 'menu contains cms.notice: '.(in_array('cms.notice', $names) ? 'YES' : 'NO')."\n";

// CRUD 闭环
$r = Illuminate\Support\Facades\Http::withHeaders($h)->post('http://nginx/api/admin/addon/cms/notices',
    ['title' => 'M5 生成器验收公告', 'body' => '<p>生成器产出的模块。</p>', 'is_pinned' => true])->json();
echo 'store code='.($r['code'] ?? '-')."\n";
$id = $r['data']['id'] ?? 0;

$list = Illuminate\Support\Facades\Http::withHeaders($h)->get('http://nginx/api/admin/addon/cms/notices',
    ['keyword' => '验收'])->json();
echo 'list total='.($list['data']['total'] ?? '-').' is_pinned='.
    var_export($list['data']['list'][0]['is_pinned'] ?? null, true)."\n";

$upd = Illuminate\Support\Facades\Http::withHeaders($h)->put("http://nginx/api/admin/addon/cms/notices/{$id}",
    ['title' => 'M5 改名'])->json();
echo 'update(PUT 部分语义) code='.($upd['code'] ?? '-')."\n";

$show = Illuminate\Support\Facades\Http::withHeaders($h)->get("http://nginx/api/admin/addon/cms/notices/{$id}")->json();
echo 'body kept after PUT: '.(($show['data']['body'] ?? '') !== '' ? 'YES' : 'NO')."\n";

$del = Illuminate\Support\Facades\Http::withHeaders($h)->delete("http://nginx/api/admin/addon/cms/notices/{$id}")->json();
echo 'destroy code='.($del['code'] ?? '-')."\n";
