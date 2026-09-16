<?php

namespace App\Admin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Attachment extends Model
{
    protected $fillable = [
        'name', 'path', 'disk', 'mime', 'size', 'width', 'height', 'uploader_type', 'uploader_id',
    ];

    protected $casts = [
        'size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'uploader_id' => 'integer',
    ];

    protected $appends = ['url'];

    /** §5.4：素材 URL 由 disk 配置推导（public → APP_URL + /storage/<path>） */
    public function getUrlAttribute(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }
}
