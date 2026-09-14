<?php

namespace App\Admin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Menu extends Model
{
    protected $fillable = [
        'parent_id', 'name', 'title', 'icon', 'route_path',
        'view_path', 'permission', 'addon_key', 'sort', 'is_show',
    ];

    protected function casts(): array
    {
        return ['is_show' => 'boolean'];
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort');
    }
}
