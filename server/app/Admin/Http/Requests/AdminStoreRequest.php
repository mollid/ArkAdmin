<?php

namespace App\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdminStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => 'required|string|max:64|unique:admins,username',
            'name' => 'nullable|string|max:64',
            'password' => 'required|string|min:6|max:64',
            'status' => 'required|integer|in:0,1',
            'roles' => 'nullable|array',
            'roles.*' => 'integer|exists:roles,id',
        ];
    }
}
