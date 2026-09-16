<?php

namespace Addons\fy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ark:crud 生成（表 fy_posts）。
 */
class PostStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
        ];
    }
}
