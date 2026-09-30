<?php

namespace App\Apps\CmsproWebos\Tests\Unit;

use App\Apps\CmsproWebos\Models\WebosWorkspace;
use App\Apps\CmsproWebos\Services\WorkspaceService;
use App\Apps\CmsproWebos\Tests\WebosTestCase;

class WorkspaceServiceTest extends WebosTestCase
{
    public function test_it_creates_a_default_workspace_for_each_admin(): void
    {
        $admin = $this->actingAdmin();

        $workspace = app(WorkspaceService::class)->getForAdmin($admin->id);

        $this->assertSame($admin->id, $workspace->admin_user_id);
        $this->assertSame([], $workspace->desktop_items);
        $this->assertSame('webos-default', $workspace->preferences['wallpaper']);
        $this->assertSame('left', $workspace->preferences['taskbar_alignment']);
        $this->assertSame('bottom', $workspace->preferences['taskbar_position']);
    }

    public function test_it_sanitizes_desktop_items_and_merges_preferences(): void
    {
        $admin = $this->actingAdmin();

        $workspace = app(WorkspaceService::class)->saveForAdmin($admin->id, [
            'desktop_items' => [
                [
                    'id' => 'menu-88',
                    'menu_id' => 88,
                    'app_id' => 'cmspro.demo',
                    'title' => '内容管理',
                    'path' => '/admin/content',
                    'icon' => 'fa fa-file-text',
                    'group_title' => '内容中心',
                    'x' => 3,
                    'y' => 7,
                ],
                [
                    'id' => 'menu-89',
                    'title' => '系统设置',
                    'path' => '/admin/system/config',
                    'icon' => 'fa fa-cog',
                    'app_id' => '非法 app_id!',
                    'x' => 0,
                    'y' => 1,
                ],
            ],
            'preferences' => [
                'clock_format' => '12h',
                'show_seconds' => true,
                'taskbar_position' => 'right',
                'window_width' => 90,
                'window_height' => 30,
                'unknown_setting' => 'ignored',
            ],
        ]);

        $this->assertSame('/admin/content', $workspace->desktop_items[0]['path']);
        $this->assertSame(3, $workspace->desktop_items[0]['x']);
        $this->assertSame('cmspro.demo', $workspace->desktop_items[0]['app_id']);
        $this->assertSame('app_id', $workspace->desktop_items[1]['app_id']);
        $this->assertSame('12h', $workspace->preferences['clock_format']);
        $this->assertTrue($workspace->preferences['show_seconds']);
        $this->assertSame('right', $workspace->preferences['taskbar_position']);
        $this->assertSame(90, $workspace->preferences['window_width']);
        $this->assertSame(40, $workspace->preferences['window_height']);
        $this->assertArrayNotHasKey('unknown_setting', $workspace->preferences);
        $this->assertSame(1, WebosWorkspace::query()->count());
    }

    public function test_it_sanitizes_window_size_preferences(): void
    {
        $admin = $this->actingAdmin();
        $workspace = app(WorkspaceService::class)->getForAdmin($admin->id);

        $this->assertSame(78, $workspace->preferences['window_width']);
        $this->assertSame(80, $workspace->preferences['window_height']);

        $invalid = app(WorkspaceService::class)->saveForAdmin($admin->id, [
            'preferences' => [
                'window_width' => 'large',
                'window_height' => 130,
            ],
        ]);

        $this->assertSame(78, $invalid->preferences['window_width']);
        $this->assertSame(100, $invalid->preferences['window_height']);
    }

    public function test_it_falls_back_to_bottom_for_an_invalid_taskbar_position(): void
    {
        $admin = $this->actingAdmin();

        $workspace = app(WorkspaceService::class)->saveForAdmin($admin->id, [
            'preferences' => ['taskbar_position' => 'diagonal'],
        ]);

        $this->assertSame('bottom', $workspace->preferences['taskbar_position']);
    }

    public function test_it_keeps_only_local_wallpaper_urls(): void
    {
        $admin = $this->actingAdmin();

        $workspace = app(WorkspaceService::class)->saveForAdmin($admin->id, [
            'preferences' => [
                'wallpaper_url' => 'apps/cmspro.webos/wallpapers/admin_1_20260929120000_abc123.png',
            ],
        ]);
        $this->assertSame(
            'apps/cmspro.webos/wallpapers/admin_1_20260929120000_abc123.png',
            $workspace->preferences['wallpaper_url']
        );

        $injected = app(WorkspaceService::class)->saveForAdmin($admin->id, [
            'preferences' => [
                'wallpaper_url' => 'https://evil.example.com/wallpaper.png',
            ],
        ]);
        $this->assertSame('', $injected->preferences['wallpaper_url']);
    }

    public function test_it_sanitizes_usage_statistics_for_the_common_menu(): void
    {
        $admin = $this->actingAdmin();

        $workspace = app(WorkspaceService::class)->saveForAdmin($admin->id, [
            'preferences' => [
                'usage_stats' => [
                    'app:cmspro.demo' => [
                        'count' => 7,
                        'last_opened_at' => '2026-09-29 10:20:30',
                    ],
                    '无效 键' => [
                        'count' => 3,
                        'last_opened_at' => 'not-a-date',
                    ],
                ],
            ],
        ]);

        $this->assertSame(7, $workspace->preferences['usage_stats']['app:cmspro.demo']['count']);
        $this->assertSame(
            '2026-09-29 10:20:30',
            $workspace->preferences['usage_stats']['app:cmspro.demo']['last_opened_at']
        );
        $this->assertArrayNotHasKey('无效 键', $workspace->preferences['usage_stats']);
    }

    public function test_it_rejects_external_desktop_paths(): void
    {
        $admin = $this->actingAdmin();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('桌面入口路径必须是站内路径');

        app(WorkspaceService::class)->saveForAdmin($admin->id, [
            'desktop_items' => [[
                'id' => 'unsafe',
                'title' => '外部站点',
                'path' => 'https://example.com',
                'icon' => 'fa fa-link',
                'x' => 0,
                'y' => 0,
            ]],
        ]);
    }
}
