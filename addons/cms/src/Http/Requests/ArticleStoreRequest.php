<?php

namespace Addons\cms\Http\Requests;

use Addons\cms\Models\Article;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ArticleStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'min:0', Rule::exists('cms_categories', 'id')],
            'title' => 'required|string|max:191',
            'summary' => 'nullable|string|max:255',
            'content' => 'nullable|string|max:'.Article::CONTENT_MAX,
            'cover' => 'nullable|string|max:191',
            'tags' => 'nullable|array|max:10',
            'tags.*' => 'string|max:32',
            'status' => 'required|integer|in:0,1',
        ];
    }
}
