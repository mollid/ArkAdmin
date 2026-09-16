<?php

namespace Addons\cms\Services;

use Addons\cms\Models\Notice;
use App\Support\Http\PgLike;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * ark:crud 生成（表 cms_notices）。
 */
class NoticeService
{
    /** @param array{keyword?:string,per_page?:int} $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $keyword = (string) ($filters['keyword'] ?? '');

        return Notice::query()
            ->when($keyword !== '', fn ($q) => $q->where('title', 'ilike', PgLike::wrap($keyword)))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function store(array $data): Notice
    {
        return Notice::create($data);
    }

    public function update(Notice $notice, array $data): void
    {
        $notice->update($data);
    }
}
