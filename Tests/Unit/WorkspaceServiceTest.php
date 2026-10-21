<?php

namespace App\Apps\CmsproWebos\Tests\Unit;

use App\Apps\CmsproWebos\Models\WebosWorkspace;
use App\Apps\CmsproWebos\Services\WorkspaceService;
use App\Apps\CmsproWebos\Tests\WebosTestCase;
use InvalidArgumentException;

class WorkspaceServiceTest extends WebosTestCase
{
    public function test_it_creates_a_default_workspace_for_each_admin(): void
    {
        $admin = $this->actingAdmin();

        $workspace = app(WorkspaceService::class)->getForAdmin($admin->id);

        $this->assertSame($admin->id, $workspace->admin_user_id);
        $this->assertSame([], $workspace->desktop_items);
        $this->assertSame([], $workspace->taskbar_items);
        $this->assertSame('webos-default', $workspace->preferences['wallpaper']);
        $this->assertSame('left', $workspace->preferences['taskbar_alignment']);
        $this->assertSame('bottom', $workspace->preferences['taskbar_position']);
    }

    public function test_it_sanitizes_taskbar_items_independently_from_desktop(): void
    {
        $admin = $this->actingAdmin();

        $workspace = app(WorkspaceService::class)->saveForAdmin($admin->id, [
            'desktop_items' => [[
                'id' => 'menu-88',
                'title' => '内容管理',
                'path' => '/admin/content',
                'icon' => 'fa fa-file-text',
                'x' => 0,
                'y' => 0,
            ]],
            'taskbar_items' => [[
                'id' => 'menu-99',
                'menu_id' => 99,
                'app_id' => 'cmspro.demo',
                'title' => '报表中心',
                'path' => '/admin/report',
                'icon' => 'fa fa-bar-chart',
                'group_title' => '报表',
                'x' => 5,
                'y' => 6,
            ]],
        ]);

        // 任务栏固定项独立存储，且坐标字段被剔除
        $this->assertSame('/admin/report', $workspace->taskbar_items[0]['path']);
        $this->assertSame('cmspro.demo', $workspace->taskbar_items[0]['app_id']);
        $this->assertArrayNotHasKey('x', $workspace->taskbar_items[0]);
        $this->assertArrayNotHasKey('y', $workspace->taskbar_items[0]);
        // 桌面项不受任务栏保存影响
        $this->assertSame('/admin/content', $workspace->desktop_items[0]['path']);

        // 保存桌面布局（不带 taskbar_items）不应清空已固定的任务栏项
        $kept = app(WorkspaceService::class)->saveForAdmin($admin->id, [
            'desktop_items' => $workspace->desktop_items,
        ]);
        $this->assertSame('/admin/report', $kept->taskbar_items[0]['path']);
    }

    public function test_it_falls_back_to_first_four_desktop_items_for_legacy_workspaces(): void
    {
        $admin = $this->actingAdmin();
        app(WorkspaceService::class)->getForAdmin($admin->id);

        // 模拟迁移前的旧数据：taskbar_items 列为 null
        WebosWorkspace::query()->where('admin_user_id', $admin->id)->update([
            'desktop_items' => json_encode([
                ['id' => 'menu-1', 'title' => 'A', 'path' => '/a', 'icon' => 'fa fa-a', 'x' => 0, 'y' => 0],
                ['id' => 'menu-2', 'title' => 'B', 'path' => '/b', 'icon' => 'fa fa-b', 'x' => 0, 'y' => 1],
            ]),
            'taskbar_items' => null,
        ]);

        $workspace = app(WorkspaceService::class)->getForAdmin($admin->id);

        $this->assertCount(2, $workspace->taskbar_items);
        $this->assertSame('menu-1', $workspace->taskbar_items[0]['id']);
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

    public function test_it_rejects_desktop_items_beyond_limit_instead_of_truncating(): void
    {
        $admin = $this->actingAdmin();

        // 超限拒绝保存而非静默截断：截断会让数组尾部的项（如文件夹）在响应回填后凭空消失
        $items = [];
        for ($i = 0; $i < 201; $i++) {
            $items[] = [
                'id' => 'menu-' . $i,
                'title' => '入口' . $i,
                'path' => '/admin/content',
                'icon' => 'fa fa-cube',
                'x' => 0,
                'y' => 0,
            ];
        }

        try {
            app(WorkspaceService::class)->saveForAdmin($admin->id, ['desktop_items' => $items]);
            $this->fail('超出桌面项上限时应抛出异常');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('桌面项数量超出上限（200）', $exception->getMessage());
        }

        // 被拒绝的保存不应落库（避免部分写入）
        $workspace = app(WorkspaceService::class)->getForAdmin($admin->id);
        $this->assertSame([], $workspace->desktop_items);

        // 上限之内（200 项）正常保存
        array_pop($items);
        $workspace = app(WorkspaceService::class)->saveForAdmin($admin->id, ['desktop_items' => $items]);
        $this->assertCount(200, $workspace->desktop_items);
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

    /** 「用户桌面」预设（2.1.88）：以 terminal=preset + 用户 ID 0 单独一行存储，与真实账号工作区隔离 */
    public function test_it_stores_the_user_desktop_preset_in_an_isolated_row(): void
    {
        $admin = $this->actingAdmin();
        app(WorkspaceService::class)->getForAdmin($admin->id);

        $preset = app(WorkspaceService::class)->savePresetItems([
            [
                'id' => 'preset-folder',
                'title' => '常用工具',
                'path' => '',
                'icon' => 'fa fa-folder',
                'kind' => 'folder',
                'x' => 0,
                'y' => 0,
            ],
            [
                'id' => 'preset-url',
                'title' => '官网',
                'path' => 'https://www.cmspro.cn',
                'icon' => 'fa fa-globe',
                'kind' => 'url',
                'x' => 1,
                'y' => 0,
            ],
        ]);

        $this->assertSame('preset', $preset->terminal);
        $this->assertSame(0, $preset->admin_user_id);
        // 净化口径与工作区一致：文件夹 path 强制置空、URL 外链原样保留
        $this->assertSame('folder', $preset->desktop_items[0]['kind']);
        $this->assertSame('', $preset->desktop_items[0]['path']);
        $this->assertSame('https://www.cmspro.cn', $preset->desktop_items[1]['path']);

        // 读回一致，且不污染管理员工作区（表中仅两行：管理员工作区 + 预设）
        $this->assertSame($preset->desktop_items, app(WorkspaceService::class)->getPreset()->desktop_items);
        $this->assertSame([], app(WorkspaceService::class)->getForAdmin($admin->id)->desktop_items);
        $this->assertSame(2, WebosWorkspace::query()->count());
    }

    /** 预设净化口径与工作区完全一致：非法站外路径同样被拒绝，保证播种数据必然通过前台保存校验 */
    public function test_it_rejects_external_paths_in_the_user_desktop_preset(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('桌面入口路径必须是站内路径');

        app(WorkspaceService::class)->savePresetItems([[
            'id' => 'preset-unsafe',
            'title' => '外部站点',
            'path' => 'https://example.com',
            'icon' => 'fa fa-link',
            'x' => 0,
            'y' => 0,
        ]]);
    }

    /** 播种（2.1.88）：新用户首次进入用户端 WebOS 时按后台预设初始化桌面图标 */
    public function test_it_seeds_the_preset_into_a_new_user_workspace(): void
    {
        app(WorkspaceService::class)->savePresetItems([[
            'id' => 'preset-menu',
            'menu_id' => 12,
            'title' => '我的订单',
            'path' => '/user/order',
            'icon' => 'fa fa-list',
            'x' => 0,
            'y' => 0,
        ]]);

        $workspace = app(WorkspaceService::class)->getFor('user', 1001);

        $this->assertSame('user', $workspace->terminal);
        $this->assertCount(1, $workspace->desktop_items);
        $this->assertSame('/user/order', $workspace->desktop_items[0]['path']);
        // 播种结果已落库（重新查库仍是预设内容，而非仅内存态）
        $stored = WebosWorkspace::query()->where('terminal', 'user')->where('admin_user_id', 1001)->first();
        $this->assertSame('/user/order', $stored->desktop_items[0]['path']);
    }

    /** 仅新用户首次生效：工作区已存在的老用户与后台管理员终端都不被预设播种覆盖 */
    public function test_it_does_not_seed_the_preset_into_an_existing_workspace(): void
    {
        // 老用户先进入过用户端（工作区已创建、桌面为空）
        app(WorkspaceService::class)->getFor('user', 1002);

        app(WorkspaceService::class)->savePresetItems([[
            'id' => 'preset-menu',
            'title' => '我的订单',
            'path' => '/user/order',
            'icon' => 'fa fa-list',
            'x' => 0,
            'y' => 0,
        ]]);

        $this->assertSame([], app(WorkspaceService::class)->getFor('user', 1002)->desktop_items);

        // 预设不作用于后台管理员终端
        $admin = $this->actingAdmin();
        $this->assertSame([], app(WorkspaceService::class)->getForAdmin($admin->id)->desktop_items);
    }
}
