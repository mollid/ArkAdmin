<?php

namespace Addons\cms\Services;

use Addons\cms\Models\Article;
use Addons\cms\Support\RichTextSanitizer;
use App\Support\Http\PgLike;
use Illuminate\Pagination\LengthAwarePaginator;

class ArticleService
{
    public function __construct(protected CategoryService $categories) {}

    /** @param array{keyword?:string,category_id?:int,status?:int,date_from?:string,date_to?:string,per_page?:int} $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $keyword = (string) ($filters['keyword'] ?? '');

        return Article::query()->with('category:id,name')
            ->when($keyword !== '', fn ($q) => $q->where('title', 'ilike', PgLike::wrap($keyword)))
            // 按栏目过滤含子栏目，与栏目树 article_count（含子孙）语义一致
            ->when(! empty($filters['category_id']), function ($q) use ($filters) {
                $q->whereIn('category_id', $this->categories->subtreeIds((int) $filters['category_id']));
            })
            ->when(isset($filters['status']) && $filters['status'] !== '', fn ($q) => $q->where('status', (int) $filters['status']))
            ->when(! empty($filters['date_from']), fn ($q) => $q->whereDate('created_at', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn ($q) => $q->whereDate('created_at', '<=', $filters['date_to']))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function store(array $data): Article
    {
        return Article::create($this->normalizeForStore($data));
    }

    public function update(Article $article, array $data): void
    {
        $article->update($this->normalizeForUpdate($article, $data));
    }

    /** 新建：全量语义（tags 缺省 []，发布即落发布时间，content 净化） */
    protected function normalizeForStore(array $data): array
    {
        $data['tags'] = array_values($data['tags'] ?? []);
        $status = (int) ($data['status'] ?? 0);
        $data['published_at'] = $status === 1 ? ($data['published_at'] ?? now()) : null;
        if (array_key_exists('content', $data)) {
            $data['content'] = RichTextSanitizer::clean((string) $data['content']);
        }

        return $data;
    }

    /**
     * 更新：PUT 部分语义，仅处理显式提交的字段——
     * 不传 tags 不清空；status=1 仅在文章从未发布过时落发布时间（编辑已发布文章不刷新时间）；回草稿清空。
     */
    protected function normalizeForUpdate(Article $article, array $data): array
    {
        if (array_key_exists('tags', $data)) {
            // 显式 tags=null 被 nullable 规则放行，语义按清空处理（与新建路径一致）；
            // 注意不能直接 array_values($data['tags'])——null 会抛 TypeError 变 500
            $data['tags'] = array_values((array) ($data['tags'] ?? []));
        }
        if (array_key_exists('content', $data)) {
            $data['content'] = RichTextSanitizer::clean((string) $data['content']);
        }
        if (array_key_exists('status', $data)) {
            $status = (int) $data['status'];
            if ($status === 1 && $article->published_at === null) {
                $data['published_at'] = now();
            }
            if ($status !== 1) {
                $data['published_at'] = null;
            }
        }

        return $data;
    }
}
