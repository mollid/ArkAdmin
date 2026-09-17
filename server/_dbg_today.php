<?php
use Addons\op_logs\Models\OpLog;
use Illuminate\Support\Facades\DB;
$configTz = config('app.timezone');
$now = now()->toDateTimeString();
$rows = OpLog::query()->selectRaw('created_at, created_at::date as d')->limit(3)->get();
echo "tz=$configTz now=$now\n";
foreach ($rows as $r) echo "row created_at={$r->created_at} date={$r->d}\n";
echo "count whereDate today=".OpLog::query()->whereDate('created_at', today())->count()."\n";
echo "count whereDate now-toDateString=".OpLog::query()->whereDate('created_at', now()->toDateString())->count()."\n";
