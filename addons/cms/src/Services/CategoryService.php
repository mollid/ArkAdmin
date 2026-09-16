<?php

namespace Addons\cms\Services;

use Addons\cms\Models\Article;
use Addons\cms\Models\Category;
use Addons\cms\Support\CmsException;
use Illuminate\Support\Collection;

class CategoryService
{
    /** 全量栏目树（后台管理树：含未显示项、不剪空目录），节点带文章数（含全部子孙） */
    public function tree(): array
    {
        $all = Category::query()->orderBy('sort')->orderBy('id')->get();
        $counts = Article::query()
            ->selectRaw('category_id, count(*) as c')
            ->groupBy('category_id')
            ->pluck('c', 'category_id');
        // 按 parent_id 建索引：nest 只对每个节点递归一次，整体 O(n)
        $byParent = $all->groupBy('parent_id');

        return $this->nest(0, $byParent, $counts);
    }

    protected function nest(int $parentId, Collection $byParent, Collection $counts): array
    {
        $out = [];
        foreach ($byParent->get($parentId, collect()) as $n) {
            $children = $this->nest((int) $n->id, $byParent, $counts);
            $out[] = [
                'id' => $n->id,
                'parent_id' => $n->parent_id,
                'name' => $n->name,
                'description' => $n->description,
                'sort' => $n->sort,
                'is_show' => $n->is_show,
                // 直属 + 全部子孙的文章数（与文章列表"按栏目过滤含子栏目"语义一致）
                'article_count' => (int) ($counts[$n->id] ?? 0)
                    + array_sum(array_column($children, 'article_count')),
                'children' => $children,
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

    /** @return list<int> 栏目自身 + 直接与间接子孙 id（文章列表按栏目过滤含子栏目用） */
    public function subtreeIds(int $categoryId): array
    {
        return [$categoryId, ...$this->descendantIdsById($categoryId)];
    }

    /** @return list<int> 直接与间接子孙 id */
    public function descendantIds(Category $category): array
    {
        return $this->descendantIdsById($category->id);
    }

    /** @return list<int> 直接与间接子孙 id（按 id 迭代，循环次数 = 树深） */
    public function descendantIdsById(int $categoryId): array
    {
        $ids = [];
        $pending = [$categoryId];
        while ($pending !== []) {
            $children = Category::query()
                ->whereIn('parent_id', $pending)->pluck('id')->map(fn ($v) => (int) $v)->all();
            $ids = array_merge($ids, $children);
            $pending = $children;
        }

        return $ids;
    }
}
