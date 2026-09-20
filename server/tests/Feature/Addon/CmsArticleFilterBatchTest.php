<?php

use Addons\cms\Models\Article;
use Addons\cms\Models\Category;
use App\Support\Addon\AddonInstaller;
use App\Support\Addon\AddonManager;

beforeEach(function () {
    (new App\Admin\Seeds\RbacSeeder)->run();
    (new App\Admin\Seeds\MenuSeeder)->run();
    app(AddonInstaller::class)->install('cms');
    // install 种子文章 created_at=now()，会随运行日期漂入/漂出断言区间——删掉保证日期过滤断言确定性
    Article::query()->delete();
});

afterEach(function () {
    app(AddonManager::class)->flushCompiled();
});

function seed_article(string $title, int $status, string $createdAt): Article
{
    $article = new Article([
        'category_id' => Category::value('id'), 'title' => $title, 'content' => '<p>x</p>',
        'status' => $status, 'tags' => [],
    ]);
    $article->created_at = $createdAt;
    $article->save();

    return $article;
}

it('文章列表按 created_at 日期范围过滤', function () {
    seed_article('早', 1, '2026-09-01 08:00:00');
    seed_article('中', 1, '2026-09-03 08:00:00');
    seed_article('晚', 1, '2026-09-05 23:59:00');

    $rows = $this->getJson('/api/admin/addon/cms/articles?date_from=2026-09-02&date_to=2026-09-04', ['Authorization' => 'Bearer '.admin_token()])
        ->assertOk()->json('data.list');
    expect(collect($rows)->pluck('title')->toArray())->toBe(['中']);
});
