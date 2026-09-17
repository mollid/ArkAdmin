<?php

namespace Addons\op_logs\Models;

use App\Admin\Models\Admin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpLog extends Model
{
    public $timestamps = false;   // 只有 created_at（埋点时间），由监听器显式写入

    protected $table = 'admin_op_logs';

    protected $fillable = [
        'admin_id', 'route', 'method', 'params', 'status_code', 'duration_ms', 'ip', 'created_at',
    ];

    protected $casts = [
        'admin_id' => 'integer',
        'params' => 'array',
        'status_code' => 'integer',
        'duration_ms' => 'float',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class)->withDefault();
    }
}
