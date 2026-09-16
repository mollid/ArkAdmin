<?php

namespace Addons\cms;

use Addons\cms\Models\Article;
use Addons\cms\Models\Category;
use App\Support\Addon\Contracts\Lifecycle;

class Addon implements Lifecycle
{
    /** 迁移已由框架执行完毕；此处做种子数据（§6.3）。幂等：已有数据则不重灌 */
    public function install(): void
    {
        if (Category::count() === 0) {
            Category::create([
                'name' => '默认栏目',
                'description' => '安装 CMS 时自动创建',
                'sort' => 0,
            ]);
        }
        if (Article::count() === 0) {
            Article::create([
                'category_id' => (int) (Category::query()->min('id') ?? 0),
                'title' => '欢迎使用内容管理',
                'summary' => 'CMS 插件安装时生成的示例文章',
                'content' => '<p>这是一篇安装时生成的示例文章，用于验证富文本渲染。</p>'
                    .'<p>你可以在「文章管理」中编辑或删除它。</p>',
                'tags' => ['示例'],
                'status' => 1,
                'published_at' => now(),
            ]);
        }
    }

    /**
     * 清理由迁移回滚负责；--keep-data 卸载时本钩子同样会被调用，
     * 因此这里绝不能删数据，保持空实现（§6.3 语义）。
     */
    public function uninstall(): void {}

    public function enable(): void {}

    public function disable(): void {}

    public function upgrade(string $fromVersion): void {}
}
