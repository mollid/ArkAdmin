<?php

namespace App\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 简报原文为 required，但简报验收测试 update 不传 username（只传 name/status）必 422——以测试为准改为 sometimes（不传则不改，与空密码语义对齐）
            'username' => ['sometimes', 'string', 'max:64', Rule::unique('admins', 'username')->ignore($this->route('admin'))],
            'name' => 'nullable|string|max:64',
            'password' => 'nullable|string|min:6|max:64',
            'status' => 'required|integer|in:0,1',
            'roles' => 'nullable|array',
            'roles.*' => 'integer|exists:roles,id',
        ];
    }
}
