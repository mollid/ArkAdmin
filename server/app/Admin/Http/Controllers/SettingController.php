<?php

namespace App\Admin\Http\Controllers;

use App\Support\Http\Traits\ApiResponse;
use App\Support\Settings\SettingException;
use App\Support\Settings\SettingStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SettingController extends Controller
{
    use ApiResponse;

    public function __construct(protected SettingStore $store) {}

    /** schema 声明 + 当前值（settings 系统插件管理页消费） */
    public function index(): JsonResponse
    {
        return $this->success($this->store->all());
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate(['values' => 'required|array|max:100']);
        try {
            $this->store->setMany((array) $request->input('values'));
        } catch (SettingException $e) {
            return $this->fail(1, $e->getMessage());
        }

        return $this->success(null, '保存成功');
    }
}
