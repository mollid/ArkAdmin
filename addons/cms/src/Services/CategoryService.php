<?php

namespace Addons\cms\Services;

use Addons\cms\Models\Article;
use Addons\cms\Models\Category;
use Addons\cms\Support\CmsException;
use Illuminate\Support\Collection;

class CategoryService
{
    /** 全量栏目树（后台管理树：含未显示项、不剪空目录），节点带文章数 */
    public function tree(): array
    {
        $all = Category::query()->orderBy('sort')->orderBy('id')->get();
        $counts = Article::query()
            ->selectRaw('category_id, count(*) as c')
            ->groupBy('category_id')
            ->pluck('c', 'category_id');

        return $this->nest($all, 0, $counts);
    }

    protected function nest(Collection $nodes, int $parentId, Collection $counts): array
    {
        $out = [];
        foreach ($nodes->where('parent_id', $parentId) as $n) {
            $out[] = [
                'id' => $n->id,
                'parent_id' => $n->parent_id,
                'name' => $n->name,
                'description' => $n->description,
                'sort' => $n->sort,
                'is_show' => $n->is_show,
                'article_count' => (int) ($counts[$n->id] ?? 0) + array_sum(array_map(
                    fn (array $c) => $c['article_count'], $this->nest($nodes, $n->id, $counts)
                )),
                'children' => $this->nest($nodes, $n->id, $counts),
            ];
        }

        return $out;
    }

    public function store(array $data): Category
    {
        return Category::create($data);
    }

    /** @throws CmsException 父级是自己或自己的子孙（成环） */
    public function update(Category $category, array $data): void
    {
        if ((int) ($data['parent_id'] ?? 0) !== 0) {
            $target = (int) $data['parent_id'];
            if ($target === $category->id || in_array($target, $this->descendantIds($category), true)) {
                throw new CmsException('父级栏目不能是自己或自己的子栏目');
            }
        }
        $category->update($data);
    }

    /** @throws CmsException 有子栏目或栏目下有文章 */
    public function destroy(Category $category): void
    {
        if ($category->children()->exists()) {
            throw new CmsException('请先删除子栏目');
        }
        if (Article::query()->where('category_id', $category->id)->exists()) {
            throw new CmsException('该栏目下还有文章，无法删除');
        }
        $category->delete();
    }

    /** @return list<int> 直接与间接子孙 id */
    public function descendantIds(Category $category): array
    {
        $ids = [];
        $pending = [$category->id];
        while ($pending !== []) {
            $children = Category::query()->whereIn('parent_id', $pending)->pluck('id')->all();
            $ids = array_merge($ids, $children);
            $pending = $children;
        }

        return $ids;
    }
}
