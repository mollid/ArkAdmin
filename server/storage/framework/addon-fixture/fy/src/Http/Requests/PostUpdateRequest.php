<?php

namespace Addons\fy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ark:crud 生成（表 fy_posts）。PUT 部分语义：所有字段一律 sometimes（M4 I1 教训）。
 */
class PostUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
