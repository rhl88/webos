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
    }

    public function test_it_sanitizes_desktop_items_and_merges_preferences(): void
    {
        $admin = $this->actingAdmin();

        $workspace = app(WorkspaceService::class)->saveForAdmin($admin->id, [
            'desktop_items' => [
                [
                    'id' => 'menu-88',
                    'menu_id' => 88,
                    'title' => '内容管理',
                    'path' => '/admin/content',
                    'icon' => 'fa fa-file-text',
                    'group_title' => '内容中心',
                    'x' => 3,
                    'y' => 7,
                ],
            ],
            'preferences' => [
                'clock_format' => '12h',
                'show_seconds' => true,
                'unknown_setting' => 'ignored',
            ],
        ]);

        $this->assertSame('/admin/content', $workspace->desktop_items[0]['path']);
        $this->assertSame(3, $workspace->desktop_items[0]['x']);
        $this->assertSame('12h', $workspace->preferences['clock_format']);
        $this->assertTrue($workspace->preferences['show_seconds']);
        $this->assertArrayNotHasKey('unknown_setting', $workspace->preferences);
        $this->assertSame(1, WebosWorkspace::query()->count());
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
