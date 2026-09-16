<?php

namespace Addons\cms\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ark:crud 生成（表 cms_notices）。
 */
class NoticeStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:100'],
            'body' => ['nullable', 'string'],
            'is_pinned' => ['nullable', 'boolean'],
        ];
    }
}
