<?php

namespace App\Apps\CmsproWebos\Tests\Feature;

use App\Apps\CmsproWebos\Services\WallpaperService;
use App\Apps\CmsproWebos\Tests\WebosTestCase;
use App\Models\AdminUser;
use Illuminate\Http\UploadedFile;

class WebosWallpaperApiTest extends WebosTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/webos-wallpapers-' . uniqid());
        mkdir($this->directory, 0755, true);

        // 把壁纸目录指向临时目录，避免测试污染已发布的 public 资源
        $this->app->instance(WallpaperService::class, new WallpaperService($this->directory));
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
     * 在临时目录写入一个壁纸文件，模拟已上传的壁纸。
     */
    private function putWallpaper(int $adminId, string $filename): string
    {
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . $filename, 'wallpaper-bytes');

        return WallpaperService::DIRECTORY . '/' . $filename;
    }

    public function test_wallpaper_api_requires_admin_authentication(): void
    {
        $this->get('/admin/cmspro/webos/api/wallpapers')
            ->assertRedirect('/admin/login');
    }

    public function test_admin_can_upload_wallpaper_and_receive_relative_path(): void
    {
        $admin = $this->actingAdmin();

        $response = $this->postJson('/admin/cmspro/webos/api/wallpaper', [
            'wallpaper' => UploadedFile::fake()->image('desktop.png'),
        ])->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('message', '壁纸已上传');

        $url = $response->json('data.url');

        $this->assertMatchesRegularExpression(
            '#^apps/cmspro\.webos/wallpapers/admin_' . $admin->id . '_\d{14}_[A-Za-z0-9]{6}\.png$#',
            $url
        );
        $this->assertFileExists($this->directory . DIRECTORY_SEPARATOR . basename($url));
    }

    public function test_upload_wallpaper_rejects_non_image_file(): void
    {
        $this->actingAdmin();

        $this->postJson('/admin/cmspro/webos/api/wallpaper', [
            'wallpaper' => UploadedFile::fake()->create('shell.php', 12, 'application/x-php'),
        ])->assertUnprocessable()
            ->assertJsonPath('code', 40201);
    }

    public function test_admin_only_lists_own_wallpapers(): void
    {
        $admin = $this->actingAdmin();
        $other = AdminUser::create([
            'username' => 'webos_other_' . uniqid(),
            'password' => bcrypt('123456'),
            'name' => '其他管理员',
            'status' => 1,
        ]);

        $own = $this->putWallpaper($admin->id, 'admin_' . $admin->id . '_20260929100000_aaaaaa.png');
        $this->putWallpaper($other->id, 'admin_' . $other->id . '_20260929100000_bbbbbb.png');

        $this->getJson('/admin/cmspro/webos/api/wallpapers')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.url', $own)
            ->assertJsonPath('data.0.size', 15);
    }

    public function test_wallpaper_list_is_empty_when_nothing_uploaded(): void
    {
        $this->actingAdmin();

        $this->getJson('/admin/cmspro/webos/api/wallpapers')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data', []);
    }

    public function test_admin_can_delete_own_wallpaper(): void
    {
        $admin = $this->actingAdmin();
        $filename = 'admin_' . $admin->id . '_20260929100000_aaaaaa.png';
        $url = $this->putWallpaper($admin->id, $filename);

        $this->deleteJson('/admin/cmspro/webos/api/wallpaper', ['url' => $url])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('message', '壁纸已删除');

        $this->assertFileDoesNotExist($this->directory . DIRECTORY_SEPARATOR . $filename);
    }

    public function test_destroy_wallpaper_rejects_foreign_and_invalid_paths(): void
    {
        $admin = $this->actingAdmin();
        $other = AdminUser::create([
            'username' => 'webos_other_' . uniqid(),
            'password' => bcrypt('123456'),
            'name' => '其他管理员',
            'status' => 1,
        ]);

        $ownFilename = 'admin_' . $admin->id . '_20260929100000_aaaaaa.png';
        $this->putWallpaper($admin->id, $ownFilename);
        $this->putWallpaper($other->id, 'admin_' . $other->id . '_20260929100000_bbbbbb.png');

        $rejected = [
            WallpaperService::DIRECTORY . '/admin_' . $other->id . '_20260929100000_bbbbbb.png',
            'https://evil.example.com/' . $ownFilename,
            '../' . $ownFilename,
        ];

        foreach ($rejected as $url) {
            $this->deleteJson('/admin/cmspro/webos/api/wallpaper', ['url' => $url])
                ->assertJsonPath('code', 40302)
                ->assertJsonPath('message', '壁纸不存在或无权删除');
        }

        $this->assertFileExists($this->directory . DIRECTORY_SEPARATOR . $ownFilename);
        $this->assertFileExists($this->directory . DIRECTORY_SEPARATOR . 'admin_' . $other->id . '_20260929100000_bbbbbb.png');
    }

    public function test_destroy_wallpaper_requires_url(): void
    {
        $this->actingAdmin();

        $this->deleteJson('/admin/cmspro/webos/api/wallpaper', [])
            ->assertUnprocessable()
            ->assertJsonPath('code', 40201);
    }

    public function test_background_settings_renders_wallpaper_library_beside_preview(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');
        $view = file_get_contents(dirname(__DIR__, 2) . '/Views/Admin/desktop/index.blade.php');

        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        $this->assertIsString($view);
        $this->assertStringContainsString(
            'data-wallpapers-url="{{ url(\'/admin/cmspro/webos/api/wallpapers\') }}"',
            $view
        );
        $this->assertStringContainsString('wallpapers: null', $script);
        $this->assertStringContainsString('function loadWallpapers()', $script);
        $this->assertStringContainsString('function renderWallpaperLibrary()', $script);
        $this->assertStringContainsString('data-wallpaper-library', $script);
        $this->assertStringContainsString('data-wallpaper-use', $script);
        $this->assertStringContainsString('data-wallpaper-delete', $script);
        $this->assertStringContainsString('data-wallpaper-delete-confirm', $script);
        $this->assertStringContainsString('data-wallpaper-delete-cancel', $script);
        $this->assertStringContainsString('class="wallpaper-current"', $script);
        $this->assertStringContainsString('.wallpaper-current {', $stylesheet);
        $this->assertStringContainsString('.wallpaper-library {', $stylesheet);
        $this->assertStringContainsString('.wallpaper-library-item.is-active', $stylesheet);
        $this->assertStringContainsString('.wallpaper-library-item.is-confirming', $stylesheet);
    }
}
