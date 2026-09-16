<?php

namespace App\Admin\Services;

use App\Admin\Events\AttachmentSaved;
use App\Admin\Models\Admin;
use App\Admin\Models\Attachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AttachmentService
{
    /**
     * 存储上传文件并登记素材（§5.4）。
     * 磁盘走 config('arkadmin.attachment.disk')（默认 public：storage/app/public + public/storage 软链）。
     */
    public function store(UploadedFile $file, ?Admin $admin = null): Attachment
    {
        $disk = (string) config('arkadmin.attachment.disk', 'public');
        $ext = strtolower($file->extension() ?: $file->getClientOriginalExtension());
        // 随机文件名防覆盖/防路径穿越；原始文件名只入 name 列，不进磁盘路径
        $path = $file->storeAs('attachments/'.date('Ym'), Str::random(32).'.'.$ext, $disk);

        $info = @getimagesize(Storage::disk($disk)->path($path));   // 非图片或读取失败 → false

        $attachment = Attachment::create([
            'name' => mb_substr($file->getClientOriginalName(), 0, 191),
            'path' => $path,
            'disk' => $disk,
            'mime' => (string) ($file->getMimeType() ?: ''),
            'size' => (int) $file->getSize(),
            'width' => $info === false ? null : (int) $info[0],
            'height' => $info === false ? null : (int) $info[1],
            'uploader_type' => 'admin',
            'uploader_id' => $admin?->id,
        ]);

        // §5.5 埋点：素材已保存（框架公开接口）
        event(new AttachmentSaved($attachment));

        return $attachment;
    }

    public function destroy(Attachment $attachment): void
    {
        Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();
    }
}
