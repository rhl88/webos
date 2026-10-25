<?php

namespace App\Apps\CmsproWebos\Tests\Unit;

use App\Apps\CmsproWebos\Models\WebosWorkspace;
use App\Apps\CmsproWebos\Services\WorkspaceService;
use App\Apps\CmsproWebos\Tests\WebosTestCase;
use App\Models\AdminRole;
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

    /** 差异化同步（2.1.89）：新用户首次进入用户端 WebOS 时按后台预设补齐桌面图标并登记已推送 */
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
        $this->assertSame(['preset-menu'], $workspace->preset_sync_ids);
        // 同步结果已落库（重新查库仍是预设内容与推送台账，而非仅内存态）
        $stored = WebosWorkspace::query()->where('terminal', 'user')->where('admin_user_id', 1001)->first();
        $this->assertSame('/user/order', $stored->desktop_items[0]['path']);
        $this->assertSame(['preset-menu'], $stored->preset_sync_ids);
    }

    /** 差异化同步（2.1.89）：老用户桌面缺失的预设项补齐、已存在等价项不重复添加 */
    public function test_it_differential_syncs_the_preset_into_an_existing_workspace(): void
    {
        // 老用户先进入过用户端：桌面已有「我的订单」（与后台预设 A 等价）
        app(WorkspaceService::class)->saveFor('user', 1002, [
            'desktop_items' => [[
                'id' => 'user-order-1',
                'menu_id' => 12,
                'title' => '我的订单',
                'path' => '/user/order',
                'icon' => 'fa fa-list',
                'x' => 3,
                'y' => 4,
            ]],
        ]);

        // 后台预设新增 A（我的订单，用户已有）与 B（我的钱包，用户缺失）
        app(WorkspaceService::class)->savePresetItems([
            [
                'id' => 'preset-order',
                'menu_id' => 12,
                'title' => '我的订单',
                'path' => '/user/order',
                'icon' => 'fa fa-list',
                'x' => 0,
                'y' => 0,
            ],
            [
                'id' => 'preset-wallet',
                'menu_id' => 13,
                'title' => '我的钱包',
                'path' => '/user/wallet',
                'icon' => 'fa fa-credit-card',
                'x' => 0,
                'y' => 1,
            ],
        ]);

        $workspace = app(WorkspaceService::class)->getFor('user', 1002);

        // 仅补齐缺失的 B；A 与用户已有项等价，不重复添加
        $this->assertCount(2, $workspace->desktop_items);
        $this->assertSame('/user/order', $workspace->desktop_items[0]['path']);
        $this->assertSame('user-order-1', $workspace->desktop_items[0]['id']);
        $this->assertSame('/user/wallet', $workspace->desktop_items[1]['path']);
        $this->assertSame('preset-wallet', $workspace->desktop_items[1]['id']);
        // 两项均登记为已推送（A 因已存在仅登记，B 因补齐而登记）
        $this->assertSame(['preset-order', 'preset-wallet'], $workspace->preset_sync_ids);

        // 后台再追加 C（发票管理）→ 下次进入继续补齐 C，已有项不受影响
        app(WorkspaceService::class)->savePresetItems([
            [
                'id' => 'preset-order',
                'menu_id' => 12,
                'title' => '我的订单',
                'path' => '/user/order',
                'icon' => 'fa fa-list',
                'x' => 0,
                'y' => 0,
            ],
            [
                'id' => 'preset-wallet',
                'menu_id' => 13,
                'title' => '我的钱包',
                'path' => '/user/wallet',
                'icon' => 'fa fa-credit-card',
                'x' => 0,
                'y' => 1,
            ],
            [
                'id' => 'preset-invoice',
                'menu_id' => 14,
                'title' => '发票管理',
                'path' => '/user/invoice',
                'icon' => 'fa fa-file-text',
                'x' => 0,
                'y' => 2,
            ],
        ]);

        $workspace = app(WorkspaceService::class)->getFor('user', 1002);
        $this->assertCount(3, $workspace->desktop_items);
        $this->assertSame('preset-invoice', $workspace->desktop_items[2]['id']);
        $this->assertSame(['preset-order', 'preset-wallet', 'preset-invoice'], $workspace->preset_sync_ids);
    }

    /** 已推送语义（2.1.89）：预设项推送过之后，用户删除或修改它都不再回推，后台改预设内容也不覆盖 */
    public function test_it_does_not_re_push_a_preset_item_after_the_user_removes_or_changes_it(): void
    {
        app(WorkspaceService::class)->savePresetItems([[
            'id' => 'preset-a',
            'menu_id' => 21,
            'title' => '我的订单',
            'path' => '/user/order',
            'icon' => 'fa fa-list',
            'x' => 0,
            'y' => 0,
        ]]);

        // 首次进入：推送 preset-a
        $workspace = app(WorkspaceService::class)->getFor('user', 1003);
        $this->assertSame(['preset-a'], $workspace->preset_sync_ids);

        // 用户删除该项：已推送过 → 不回推
        app(WorkspaceService::class)->saveFor('user', 1003, ['desktop_items' => []]);
        $this->assertSame([], app(WorkspaceService::class)->getFor('user', 1003)->desktop_items);

        // 用户改为自定义项（内容与预设不同）：已推送过 → 不回推、不覆盖用户修改
        app(WorkspaceService::class)->saveFor('user', 1003, [
            'desktop_items' => [[
                'id' => 'preset-a',
                'menu_id' => 21,
                'title' => '我的订单（自定义）',
                'path' => '/user/order',
                'icon' => 'fa fa-star',
                'x' => 5,
                'y' => 5,
            ]],
        ]);
        $workspace = app(WorkspaceService::class)->getFor('user', 1003);
        $this->assertCount(1, $workspace->desktop_items);
        $this->assertSame('我的订单（自定义）', $workspace->desktop_items[0]['title']);

        // 后台修改同一预设项（id 不变、内容变了）：已推送过 → 不覆盖用户桌面
        app(WorkspaceService::class)->savePresetItems([[
            'id' => 'preset-a',
            'menu_id' => 21,
            'title' => '我的订单（新版）',
            'path' => '/user/orders',
            'icon' => 'fa fa-list',
            'x' => 0,
            'y' => 0,
        ]]);
        $workspace = app(WorkspaceService::class)->getFor('user', 1003);
        $this->assertCount(1, $workspace->desktop_items);
        $this->assertSame('我的订单（自定义）', $workspace->desktop_items[0]['title']);
        $this->assertSame('/user/order', $workspace->desktop_items[0]['path']);
        $this->assertSame(['preset-a'], $workspace->preset_sync_ids);
    }

    /** 文件夹挂靠（2.1.89）：预设文件夹命中用户已有等价文件夹时，其子项挂靠到已有文件夹下，避免孤立子项 */
    public function test_it_attaches_preset_folder_children_to_the_existing_equivalent_folder(): void
    {
        // 用户桌面已有同名文件夹
        app(WorkspaceService::class)->saveFor('user', 1004, [
            'desktop_items' => [[
                'id' => 'user-folder-1',
                'title' => '常用工具',
                'path' => '',
                'icon' => 'fa fa-folder',
                'kind' => 'folder',
                'x' => 0,
                'y' => 0,
            ]],
        ]);

        app(WorkspaceService::class)->savePresetItems([
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
                'id' => 'preset-sub',
                'menu_id' => 31,
                'title' => '账号设置',
                'path' => '/user/setting',
                'icon' => 'fa fa-cog',
                'parent_folder' => 'preset-folder',
                'x' => 0,
                'y' => 0,
            ],
        ]);

        $workspace = app(WorkspaceService::class)->getFor('user', 1004);

        // 文件夹命中已有等价项不新增；子项挂靠到用户已有文件夹下
        $this->assertCount(2, $workspace->desktop_items);
        $this->assertSame('user-folder-1', $workspace->desktop_items[0]['id']);
        $this->assertSame('preset-sub', $workspace->desktop_items[1]['id']);
        $this->assertSame('user-folder-1', $workspace->desktop_items[1]['parent_folder']);
        $this->assertSame(['preset-folder', 'preset-sub'], $workspace->preset_sync_ids);
    }

    /** 差异化同步仅作用于前台用户端：后台管理员工作区不参与预设同步 */
    public function test_it_does_not_sync_the_preset_for_the_admin_terminal(): void
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

        $admin = $this->actingAdmin();
        $workspace = app(WorkspaceService::class)->getForAdmin($admin->id);

        $this->assertSame([], $workspace->desktop_items);
        $this->assertNull($workspace->preset_sync_ids);
    }

    /** 「管理桌面」角色预设（2.1.98）：以 terminal=admin_preset + 角色 ID 单独一行存储，与真实账号工作区隔离 */
    public function test_it_stores_the_admin_role_preset_in_an_isolated_row(): void
    {
        $admin = $this->actingAdmin();
        app(WorkspaceService::class)->getForAdmin($admin->id);

        $role = AdminRole::create(['name' => '运营专员', 'code' => 'webos_preset_' . uniqid(), 'status' => 1]);

        $preset = app(WorkspaceService::class)->saveAdminPresetItems($role->id, [
            [
                'id' => 'role-preset-folder',
                'title' => '运营工具',
                'path' => '',
                'icon' => 'fa fa-folder',
                'kind' => 'folder',
                'x' => 0,
                'y' => 0,
            ],
            [
                'id' => 'role-preset-menu',
                'menu_id' => 66,
                'title' => '内容审核',
                'path' => '/admin/content/review',
                'icon' => 'fa fa-check-square-o',
                'x' => 1,
                'y' => 0,
            ],
        ]);

        $this->assertSame('admin_preset', $preset->terminal);
        $this->assertSame($role->id, $preset->admin_user_id);
        // 净化口径与管理员工作区一致：文件夹 path 强制置空
        $this->assertSame('', $preset->desktop_items[0]['path']);
        $this->assertSame('/admin/content/review', $preset->desktop_items[1]['path']);

        // 读回一致，且不污染管理员工作区（表中仅两行：管理员工作区 + 角色预设）
        $this->assertSame(
            $preset->desktop_items,
            app(WorkspaceService::class)->getAdminPreset($role->id)->desktop_items
        );
        $this->assertSame([], app(WorkspaceService::class)->getForAdmin($admin->id)->desktop_items);
        $this->assertSame(2, WebosWorkspace::query()->count());
    }

    /** 管理员带角色进入后台端：首次取工作区时按该角色「管理桌面」预设差量播种初始图标（2.1.98） */
    public function test_it_seeds_the_admin_role_preset_for_an_admin_with_the_role(): void
    {
        $admin = $this->actingAdmin();
        $role = AdminRole::create(['name' => '运营专员', 'code' => 'webos_seed_' . uniqid(), 'status' => 1]);
        $admin->roles()->attach($role->id);

        app(WorkspaceService::class)->saveAdminPresetItems($role->id, [[
            'id' => 'role-preset-menu',
            'menu_id' => 66,
            'title' => '内容审核',
            'path' => '/admin/content/review',
            'icon' => 'fa fa-check-square-o',
            'x' => 0,
            'y' => 0,
        ]]);

        $workspace = app(WorkspaceService::class)->getForAdmin($admin->id);

        $this->assertSame('admin', $workspace->terminal);
        $this->assertCount(1, $workspace->desktop_items);
        $this->assertSame('/admin/content/review', $workspace->desktop_items[0]['path']);
        $this->assertSame(['role-preset-menu'], $workspace->preset_sync_ids);
        // 同步结果已落库（重新查库仍是预设内容与推送台账，而非仅内存态）
        $stored = WebosWorkspace::query()->where('terminal', 'admin')->where('admin_user_id', $admin->id)->first();
        $this->assertSame('/admin/content/review', $stored->desktop_items[0]['path']);
        $this->assertSame(['role-preset-menu'], $stored->preset_sync_ids);

        // 管理员删除该项后：已推送过 → 不回推（与前台用户端预设语义一致）
        app(WorkspaceService::class)->saveForAdmin($admin->id, ['desktop_items' => []]);
        $this->assertSame([], app(WorkspaceService::class)->getForAdmin($admin->id)->desktop_items);
    }

    /** 多角色管理员：按角色 sort 顺序取各角色预设并集（presetItemId 去重，先出现的角色优先）（2.1.98） */
    public function test_it_merges_admin_role_presets_as_a_union_for_multiple_roles(): void
    {
        $admin = $this->actingAdmin();
        $firstRole = AdminRole::create(['name' => '运营组', 'code' => 'webos_union_a_' . uniqid(), 'status' => 1, 'sort' => 1]);
        $secondRole = AdminRole::create(['name' => '客服组', 'code' => 'webos_union_b_' . uniqid(), 'status' => 1, 'sort' => 2]);
        $admin->roles()->attach([$firstRole->id, $secondRole->id]);

        app(WorkspaceService::class)->saveAdminPresetItems($firstRole->id, [
            [
                'id' => 'role-a-menu',
                'menu_id' => 61,
                'title' => '运营工作台',
                'path' => '/admin/ops/dashboard',
                'icon' => 'fa fa-line-chart',
                'x' => 0,
                'y' => 0,
            ],
            [
                'id' => 'role-shared-menu',
                'menu_id' => 63,
                'title' => '公告管理',
                'path' => '/admin/notice',
                'icon' => 'fa fa-bullhorn',
                'x' => 0,
                'y' => 1,
            ],
        ]);
        app(WorkspaceService::class)->saveAdminPresetItems($secondRole->id, [
            // 与第一角色重复（推送标识相同）：并集去重，先出现的角色优先
            [
                'id' => 'role-shared-menu',
                'menu_id' => 63,
                'title' => '公告管理',
                'path' => '/admin/notice',
                'icon' => 'fa fa-bullhorn',
                'x' => 0,
                'y' => 1,
            ],
            [
                'id' => 'role-b-menu',
                'menu_id' => 62,
                'title' => '客服工作台',
                'path' => '/admin/service/dashboard',
                'icon' => 'fa fa-headphones',
                'x' => 0,
                'y' => 2,
            ],
        ]);

        $workspace = app(WorkspaceService::class)->getForAdmin($admin->id);

        // 并集共 3 项：第一角色在前（role-a-menu、role-shared-menu），第二角色去重后仅剩 role-b-menu
        $this->assertSame(
            ['role-a-menu', 'role-shared-menu', 'role-b-menu'],
            collect($workspace->desktop_items)->pluck('id')->all()
        );
        $this->assertSame(['role-a-menu', 'role-shared-menu', 'role-b-menu'], $workspace->preset_sync_ids);
    }

    /** 无角色管理员、禁用角色、未配置预设的启用角色均不参与同步（也不为未配置的角色创建空预设行）（2.1.98） */
    public function test_it_skips_disabled_or_unconfigured_roles_and_admins_without_roles(): void
    {
        // 场景一：无角色管理员 → 桌面保持空白
        $noRoleAdmin = $this->actingAdmin();
        $this->assertSame([], app(WorkspaceService::class)->getForAdmin($noRoleAdmin->id)->desktop_items);
        $this->assertSame(1, WebosWorkspace::query()->count());

        // 场景二：管理员仅绑定禁用角色（该角色已配置预设）→ 不同步
        $disabledRole = AdminRole::create([
            'name' => '停用角色',
            'code' => 'webos_disabled_' . uniqid(),
            'status' => 0,
            'sort' => 1,
        ]);
        app(WorkspaceService::class)->saveAdminPresetItems($disabledRole->id, [[
            'id' => 'disabled-role-menu',
            'menu_id' => 71,
            'title' => '停用入口',
            'path' => '/admin/disabled',
            'icon' => 'fa fa-ban',
            'x' => 0,
            'y' => 0,
        ]]);

        $secondAdmin = $this->actingAdmin();
        $secondAdmin->roles()->attach($disabledRole->id);
        $this->assertSame([], app(WorkspaceService::class)->getForAdmin($secondAdmin->id)->desktop_items);

        // 场景三：再绑定启用但未配置预设的角色 → 不同步，也不为其创建空预设行
        $enabledRole = AdminRole::create([
            'name' => '未配置角色',
            'code' => 'webos_unconfigured_' . uniqid(),
            'status' => 1,
            'sort' => 2,
        ]);
        $secondAdmin->roles()->attach($enabledRole->id);
        $this->assertSame([], app(WorkspaceService::class)->getForAdmin($secondAdmin->id)->desktop_items);

        // 表中仅：无角色管理员工作区、停用角色预设、第二管理员工作区（未配置的启用角色未建空行）
        $this->assertSame(3, WebosWorkspace::query()->count());
    }
}
