<?php

namespace Addons\fy\Services;

use Addons\fy\Models\Post;
use App\Support\Http\PgLike;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * ark:crud 生成（表 fy_posts）。
 */
class PostService
{
    /** @param array{keyword?:string,per_page?:int} $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $keyword = (string) ($filters['keyword'] ?? '');

        return Post::query()
            ->when($keyword !== '', fn ($q) => $q->where('title', 'ilike', PgLike::wrap($keyword)))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function store(array $data): Post
    {
        return Post::create($data);
    }

    public function update(Post $post, array $data): void
    {
        $post->update($data);
    }
}
