<?php

namespace Addons\cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $table = 'cms_categories';

    protected $fillable = ['parent_id', 'name', 'description', 'sort', 'is_show'];

    protected $casts = [
        'parent_id' => 'integer',
        'sort' => 'integer',
        'is_show' => 'boolean',
    ];

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort')->orderBy('id');
    }
}
