<?php

namespace Addons\cms\Services;

use Addons\cms\Models\Article;
use App\Support\Http\PgLike;
use Illuminate\Pagination\LengthAwarePaginator;

class ArticleService
{
    /** @param array{keyword?:string,category_id?:int,status?:int,per_page?:int} $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $keyword = (string) ($filters['keyword'] ?? '');

        return Article::query()->with('category:id,name')
            ->when($keyword !== '', fn ($q) => $q->where('title', 'ilike', PgLike::wrap($keyword)))
            ->when(! empty($filters['category_id']), fn ($q) => $q->where('category_id', (int) $filters['category_id']))
            ->when(isset($filters['status']) && $filters['status'] !== '', fn ($q) => $q->where('status', (int) $filters['status']))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function store(array $data): Article
    {
        return Article::create($this->normalize($data));
    }

    public function update(Article $article, array $data): void
    {
        $article->update($this->normalize($data));
    }

    /**
     * 发布流收口：置为发布且未显式给时间 → now()；回草稿 → 清空发布时间。
     * tags 缺省 []，避免 jsonb 列出现 null。
     */
    protected function normalize(array $data): array
    {
        $data['tags'] = array_values($data['tags'] ?? []);
        $status = (int) ($data['status'] ?? 0);
        if ($status === 1 && empty($data['published_at'])) {
            $data['published_at'] = now();
        }
        if ($status !== 1) {
            $data['published_at'] = null;
        }

        return $data;
    }
}
