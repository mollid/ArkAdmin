<?php

namespace Addons\cms\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ark:crud 生成（表 cms_notices）。PUT 部分语义：所有字段一律 sometimes（M4 I1 教训）。
 */
class NoticeUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:100'],
            'body' => ['sometimes', 'nullable', 'string'],
            'is_pinned' => ['sometimes', 'nullable', 'boolean'],
        ];
    }
}
