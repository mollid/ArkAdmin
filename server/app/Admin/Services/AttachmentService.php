<?php

namespace App\Admin\Services;

use App\Admin\Events\AttachmentDeleted;
use App\Admin\Events\AttachmentSaved;
use App\Admin\Models\Admin;
use App\Admin\Models\Attachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AttachmentService
{
    /** 文件内容嗅探 mime（finfo，不信任客户端 mime/文件名），嗅探失败返回空串 */
    public static function sniffedMime(UploadedFile $file): string
    {
        try {
            $path = $file->getPathname();
            if ($path === '' || ! is_file($path)) {
                return '';
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

            return is_string($mime) ? strtolower($mime) : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * 存储上传文件并登记素材（§5.4）。
     * 磁盘走 config('arkadmin.attachment.disk')（默认 public：storage/app/public + public/storage 软链）。
     */
    public function store(UploadedFile $file, ?Admin $admin = null): Attachment
    {
        $disk = (string) config('arkadmin.attachment.disk', 'public');
        // mime 以 finfo 内容嗅探为准（与控制器校验同一来源）；扩展名由 mime 白名单映射得出，
        // 绝不使用客户端文件名/客户端 mime（均可伪造）
        $mime = self::sniffedMime($file);
        $ext = (string) (config("arkadmin.attachment.mimes.$mime") ?? 'bin');
        // 随机文件名防覆盖/防路径穿越；原始文件名只入 name 列，不进磁盘路径
        $path = $file->storeAs('attachments/'.date('Ym'), Str::random(32).'.'.$ext, $disk);

        // 宽高仅本地磁盘可取（path() 对 s3 等远端适配器无意义）；非图片或读取失败 → false
        $driver = (string) (config("filesystems.disks.$disk.driver") ?? '');
        $info = $driver === 'local'
            ? @getimagesize(Storage::disk($disk)->path($path))
            : false;

        $attachment = Attachment::create([
            'name' => mb_substr($file->getClientOriginalName(), 0, 191),
            'path' => $path,
            'disk' => $disk,
            'mime' => $mime,   // 内容嗅探 mime（客户端 mime 不入库）
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
        if (! Storage::disk($attachment->disk)->delete($attachment->path)) {
            // 磁盘故障时行已删会留孤儿文件：记警告便于巡检，不影响响应
            logger()->warning("附件文件删除失败：disk={$attachment->disk} path={$attachment->path}");
        }
        $attachment->delete();

        // §5.5 埋点：素材已删除（框架公开接口，插件据此清理自身引用）
        event(new AttachmentDeleted($attachment));
    }
}
