<?php

namespace App\Apps\CmsproWebos\Tests\Unit;

use App\Apps\CmsproWebos\Services\WallpaperService;
use App\Apps\CmsproWebos\Tests\WebosTestCase;
use Illuminate\Http\UploadedFile;

class WallpaperServiceTest extends WebosTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/webos-wallpapers-' . uniqid());
        mkdir($this->directory, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);

        parent::tearDown();
    }

    /**
     * 测试专用实例：把壁纸目录指向临时目录，避免污染已发布的 public 资源。
     */
    private function service(): WallpaperService
    {
        return new WallpaperService($this->directory);
    }

    /**
     * 在临时目录写入一个壁纸文件，可指定修改时间用于排序断言。
     */
    private function putWallpaper(string $filename, ?int $modifiedAt = null): void
    {
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . $filename, 'wallpaper-bytes');

        if ($modifiedAt !== null) {
            touch($this->directory . DIRECTORY_SEPARATOR . $filename, $modifiedAt);
        }
    }

    public function test_it_lists_only_the_current_admin_wallpapers(): void
    {
        $this->putWallpaper('admin_7_20260929100000_aaaaaa.png');
        $this->putWallpaper('admin_8_20260929100000_bbbbbb.png');
        $this->putWallpaper('not-a-wallpaper.txt');

        $wallpapers = $this->service()->listFor(7);

        $this->assertCount(1, $wallpapers);
        $this->assertSame('apps/cmspro.webos/wallpapers/admin_7_20260929100000_aaaaaa.png', $wallpapers[0]['url']);
        $this->assertSame(15, $wallpapers[0]['size']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $wallpapers[0]['uploaded_at']);
    }

    public function test_it_lists_wallpapers_from_newest_to_oldest(): void
    {
        $this->putWallpaper('admin_7_20260929100000_old001.png', strtotime('2026-09-29 10:00:00'));
        $this->putWallpaper('admin_7_20260929120000_new001.png', strtotime('2026-09-29 12:00:00'));

        $wallpapers = $this->service()->listFor(7);

        $this->assertSame(
            ['admin_7_20260929120000_new001.png', 'admin_7_20260929100000_old001.png'],
            array_map(static fn (array $item): string => basename($item['url']), $wallpapers)
        );
    }

    public function test_it_returns_an_empty_list_when_no_wallpaper_exists(): void
    {
        $this->assertSame([], $this->service()->listFor(7));
    }

    public function test_it_stores_wallpaper_under_an_admin_prefixed_relative_path(): void
    {
        $url = $this->service()->store(UploadedFile::fake()->image('desktop.png'), 7);

        $this->assertMatchesRegularExpression(
            '#^apps/cmspro\.webos/wallpapers/admin_7_\d{14}_[A-Za-z0-9]{6}\.png$#',
            $url
        );
        $this->assertFileExists($this->directory . DIRECTORY_SEPARATOR . basename($url));
        $this->assertSame([basename($url)], array_map(
            static fn (array $item): string => basename($item['url']),
            $this->service()->listFor(7)
        ));
    }

    public function test_it_deletes_the_current_admin_wallpaper(): void
    {
        $this->putWallpaper('admin_7_20260929100000_aaaaaa.png');

        $deleted = $this->service()->delete('apps/cmspro.webos/wallpapers/admin_7_20260929100000_aaaaaa.png', 7);

        $this->assertTrue($deleted);
        $this->assertFileDoesNotExist($this->directory . '/admin_7_20260929100000_aaaaaa.png');
    }

    public function test_it_rejects_deleting_another_admin_wallpaper(): void
    {
        $this->putWallpaper('admin_8_20260929100000_bbbbbb.png');

        $deleted = $this->service()->delete('apps/cmspro.webos/wallpapers/admin_8_20260929100000_bbbbbb.png', 7);

        $this->assertFalse($deleted);
        $this->assertFileExists($this->directory . '/admin_8_20260929100000_bbbbbb.png');
    }

    public function test_it_rejects_deleting_a_path_outside_the_wallpaper_directory(): void
    {
        $this->putWallpaper('admin_7_20260929100000_aaaaaa.png');

        $rejected = [
            '../admin_7_20260929100000_aaaaaa.png',
            'apps/cmspro.webos/wallpapers/../../index.php',
            '/etc/passwd',
            'https://evil.example.com/admin_7_20260929100000_aaaaaa.png',
            'apps/cmspro.webos/wallpapers/admin_7_20260929100000_aaaaaa.sh',
        ];

        foreach ($rejected as $path) {
            $this->assertFalse($this->service()->delete($path, 7), $path);
        }

        $this->assertFileExists($this->directory . '/admin_7_20260929100000_aaaaaa.png');
    }

    public function test_delete_returns_false_when_the_file_is_missing(): void
    {
        $this->assertFalse(
            $this->service()->delete('apps/cmspro.webos/wallpapers/admin_7_20260929100000_gone00.png', 7)
        );
    }
}
