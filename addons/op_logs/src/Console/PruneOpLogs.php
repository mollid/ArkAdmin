<?php

namespace Addons\op_logs\Console;

use Addons\op_logs\Models\OpLog;
use Illuminate\Console\Command;

/** 日志保留策略（规格 §3.3）：默认保留 90 天，超期清理 */
class PruneOpLogs extends Command
{
    protected $signature = 'op-logs:prune {--days=90 : 保留天数}';

    protected $description = '清理超期的操作日志';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cut = now()->subDays($days);

        $deleted = OpLog::query()->where('created_at', '<', $cut)->delete();
        $this->info("已清理 {$deleted} 条 {$days} 天前的操作日志（早于 {$cut->toDateTimeString()}）");

        return self::SUCCESS;
    }
}
