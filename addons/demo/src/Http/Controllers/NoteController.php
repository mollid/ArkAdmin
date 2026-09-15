<?php

namespace Addons\demo\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Http\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NoteController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        return $this->success(['list' => DB::table('demo_notes')->orderByDesc('id')->get()->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['content' => 'required|string|max:500']);
        $id = DB::table('demo_notes')->insertGetId([
            'admin_id' => $request->user('admin')->id,
            'content' => $data['content'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->success(['id' => $id], '已创建');
    }
}
