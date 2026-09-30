<?php

namespace App\Apps\CmsproWebos\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * 自定义桌面壁纸服务：负责壁纸文件的保存、列举与删除。
 *
 * 约定：壁纸统一存放在应用发布资源目录 `public/apps/cmspro.webos/wallpapers`，
 * 文件名携带管理员标识（`admin_{id}_{YmdHis}_{random6}.{ext}`），数据库与接口
 * 只传递相对路径，展示时由前端拼接站点根路径。
 */
class WallpaperService
{
    /** 壁纸发布目录（相对 public 根目录） */
    public const DIRECTORY = 'apps/cmspro.webos/wallpapers';

    /** 壁纸文件名白名单：管理员前缀 + 上传时间 + 随机串 + 允许的图片扩展名 */
    private const FILENAME_PATTERN = '/^admin_\d+_\d{14}_[A-Za-z0-9]{6}\.(jpg|jpeg|png|gif|webp)$/';

    /**
     * @param  string|null  $directoryOverride  壁纸目录绝对路径，为空时使用应用发布资源目录（测试可指定临时目录）
     */
    public function __construct(private readonly ?string $directoryOverride = null)
    {
    }

    /**
     * 列出指定管理员已上传的自定义壁纸，按上传时间从新到旧排序。
     *
     * @return array<int, array{url: string, size: int, uploaded_at: string}>
     */
    public function listFor(int $adminId): array
    {
        $directory = $this->directory();
        if (! is_dir($directory)) {
            return [];
        }

        $wallpapers = [];
        foreach (scandir($directory) ?: [] as $filename) {
            if (! $this->isOwnWallpaper($filename, $adminId)) {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $filename;
            $modifiedAt = (int) filemtime($path);
            $wallpapers[] = [
                'url' => self::DIRECTORY . '/' . $filename,
                'size' => (int) filesize($path),
                'uploaded_at' => date('Y-m-d H:i:s', $modifiedAt),
            ];
        }

        usort($wallpapers, static fn (array $left, array $right): int => [$right['uploaded_at'], $right['url']]
            <=> [$left['uploaded_at'], $left['url']]);

        return $wallpapers;
    }

    /**
     * 保存上传的壁纸文件，返回可供数据库存储的相对路径。
     */
    public function store(UploadedFile $file, int $adminId): string
    {
        $directory = $this->directory();
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new \RuntimeException('壁纸目录创建失败');
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $filename = $this->prefix($adminId) . date('YmdHis') . '_' . Str::random(6) . '.' . $extension;
        $file->move($directory, $filename);

        return self::DIRECTORY . '/' . $filename;
    }

    /**
     * 删除指定管理员自己的壁纸文件；路径非法、越权或文件不存在时返回 false。
     */
    public function delete(string $url, int $adminId): bool
    {
        $url = trim($url);
        if (! str_starts_with($url, self::DIRECTORY . '/')) {
            return false;
        }

        $filename = basename($url);
        if (! $this->isOwnWallpaper($filename, $adminId)) {
            return false;
        }

        $path = $this->directory() . DIRECTORY_SEPARATOR . $filename;

        return is_file($path) && @unlink($path);
    }

    /**
     * 壁纸目录绝对路径。
     */
    private function directory(): string
    {
        return $this->directoryOverride ?: public_path(self::DIRECTORY);
    }

    /**
     * 文件名归属前缀，确保管理员只能操作自己上传的壁纸。
     */
    private function prefix(int $adminId): string
    {
        return 'admin_' . $adminId . '_';
    }

    /**
     * 文件名是否为该管理员的合法壁纸文件。
     */
    private function isOwnWallpaper(string $filename, int $adminId): bool
    {
        return str_starts_with($filename, $this->prefix($adminId))
            && preg_match(self::FILENAME_PATTERN, $filename) === 1;
    }
}
