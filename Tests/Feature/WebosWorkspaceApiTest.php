<?php

namespace App\Apps\CmsproWebos\Tests\Feature;

use App\Apps\CmsproWebos\Tests\WebosTestCase;
use App\Models\AdminMenu;
use App\Models\AdminUser;
use Illuminate\Support\Facades\DB;

class WebosWorkspaceApiTest extends WebosTestCase
{
    public function test_guest_is_redirected_to_admin_login(): void
    {
        $this->get('/admin/cmspro/webos')
            ->assertRedirect('/admin/login');
    }

    public function test_admin_can_open_webos_desktop(): void
    {
        $this->actingAdmin();

        $response = $this->get('/admin/cmspro/webos');
        $response->assertOk()
            ->assertSee('webos-desktop')
            ->assertSee('个人设置')
            ->assertSee('修改密码')
            ->assertSee('data-taskbar-position="bottom"', false);

        // 桌面标题动态携带系统与 WebOS 双版本号（WebOS 版本读应用 manifest）
        $this->assertMatchesRegularExpression('/<title>CMSPRO v\d+\.\d+\.\d+ · WebOS v\d+\.\d+\.\d+<\/title>/', $response->getContent());
        // 开始菜单系统操作区版权：与框架后台页脚一致（版本号与年份动态读取）
        $response->assertSee('start-copyright', false)
            ->assertSee('&copy; 2015-' . date('Y') . ' Copyright by', false);
    }

    public function test_all_todos_api_returns_full_list_with_read_state(): void
    {
        $this->actingAdmin();

        // 测试内自建待办数据源（模拟其他应用通过系统 hook 接入，WebOS 自身不注册演示数据）
        app(\App\Services\HookManager::class)->registerFilter(
            'admin.notifications.todos',
            function (array $entries): array {
                $entries[] = ['key' => 'webos.test.first', 'title' => '测试待办一', 'count' => 3, 'link' => '/admin/user'];
                $entries[] = ['key' => 'webos.test.second', 'title' => '测试待办二', 'count' => 5, 'link' => '/admin/app'];

                return $entries;
            },
            10,
            'webos.test'
        );

        // 未点击任何待办：全部返回且均为未读
        $this->getJson('/admin/cmspro/webos/api/all-todos')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.key', 'webos.test.first')
            ->assertJsonPath('data.0.is_read', false)
            ->assertJsonPath('data.1.key', 'webos.test.second')
            ->assertJsonPath('data.1.count', 5);

        // 模拟点击「测试待办一」（seen_count 记满 count）：仍返回全部 2 条，该条 is_read=true
        app(\App\Services\NotificationService::class)->markTodoRead('webos.test.first', 3);

        $this->getJson('/admin/cmspro/webos/api/all-todos')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.is_read', true)
            ->assertJsonPath('data.1.is_read', false);

        app(\App\Services\HookManager::class)->removeHooksByApp('webos.test');
    }

    public function test_admin_can_read_and_save_workspace(): void
    {
        $this->actingAdmin();

        $this->getJson('/admin/cmspro/webos/api/workspace')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.preferences.wallpaper', 'webos-default');

        $payload = [
            'desktop_items' => [[
                'id' => 'menu-10',
                'menu_id' => 10,
                'app_id' => 'cmspro.demo',
                'title' => '用户管理',
                'path' => '/admin/user',
                'icon' => 'fa fa-users',
                'group_title' => '系统管理',
                'x' => 1,
                'y' => 2,
            ]],
            'taskbar_items' => [[
                'id' => 'menu-20',
                'menu_id' => 20,
                'app_id' => 'cmspro.demo',
                'title' => '报表中心',
                'path' => '/admin/report',
                'icon' => 'fa fa-bar-chart',
                'group_title' => '报表',
            ]],
            'preferences' => [
                'wallpaper' => 'webos-default',
                'taskbar_alignment' => 'left',
                'taskbar_position' => 'left',
                'clock_format' => '24h',
                'show_seconds' => false,
                'motion' => true,
                'usage_stats' => [
                    'folder:13' => [
                        'count' => 4,
                        'last_opened_at' => '2026-09-29 09:18:00',
                    ],
                ],
            ],
        ];

        $this->putJson('/admin/cmspro/webos/api/workspace', $payload)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.desktop_items.0.title', '用户管理')
            ->assertJsonPath('data.desktop_items.0.app_id', 'cmspro.demo')
            ->assertJsonPath('data.taskbar_items.0.title', '报表中心')
            ->assertJsonPath('data.taskbar_items.0.path', '/admin/report')
            ->assertJsonPath('data.preferences.taskbar_position', 'left')
            ->assertJsonPath('data.preferences.usage_stats.folder:13.count', 4);

        // 任务栏固定项与桌面快捷方式相互独立：再次保存桌面布局不影响任务栏
        $this->getJson('/admin/cmspro/webos/api/workspace')
            ->assertOk()
            ->assertJsonPath('data.taskbar_items.0.title', '报表中心')
            ->assertJsonPath('data.desktop_items.0.title', '用户管理');
    }

    public function test_workspace_api_rejects_an_unknown_taskbar_position(): void
    {
        $this->actingAdmin();

        $this->putJson('/admin/cmspro/webos/api/workspace', [
            'preferences' => ['taskbar_position' => 'diagonal'],
        ])->assertUnprocessable()
            ->assertJsonPath('code', 40201);
    }

    /** 桌面图标三档尺寸（右键「查看」切换）：控制器验证必须保留 icon_size 键并持久化（回归：无规则时 $validated 丢弃导致勾选回退中图标） */
    public function test_workspace_api_persists_icon_size_preference(): void
    {
        $this->actingAdmin();

        $this->putJson('/admin/cmspro/webos/api/workspace', [
            'preferences' => ['icon_size' => 'large'],
        ])->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.preferences.icon_size', 'large');

        // 再次读取验证持久化，非法值回退中图标
        $this->getJson('/admin/cmspro/webos/api/workspace')
            ->assertOk()
            ->assertJsonPath('data.preferences.icon_size', 'large');

        $this->putJson('/admin/cmspro/webos/api/workspace', [
            'preferences' => ['icon_size' => 'giant'],
        ])->assertUnprocessable()
            ->assertJsonPath('code', 40201);
    }

    /** OS 设置「应用窗口多选项卡」：偏好三层同步、OS 设置开关卡、标题栏选项卡（切换/关闭/页面状态保留） */
    public function test_window_tabs_preference_and_titlebar_tab_bar(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');
        $service = file_get_contents(dirname(__DIR__, 2) . '/Services/WorkspaceService.php');
        $controller = file_get_contents(dirname(__DIR__, 2) . '/Controllers/Admin/WebosController.php');

        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        $this->assertIsString($service);
        $this->assertIsString($controller);
        // 偏好默认关闭 + 三层同步（DEFAULT_PREFERENCES / sanitizePreferences / 验证规则）
        $this->assertStringContainsString('window_tabs: false,', $script);
        $this->assertStringContainsString("'window_tabs' => false,", $service);
        $this->assertStringContainsString("(bool) (\$preferences['window_tabs'] ?? false)", $service);
        $this->assertStringContainsString("'preferences.window_tabs' => ['sometimes', 'boolean'],", $controller);
        // OS 设置系统页开关卡
        $this->assertStringContainsString('data-toggle-webos-setting="window_tabs"', $script);
        // 「点击菜单进入」偏好：三层同步 + OS 设置开关卡 + 菜单按钮点击分支（开启直接进全部功能启动台）
        $this->assertStringContainsString('menu_open_launcher: false,', $script);
        $this->assertStringContainsString("'menu_open_launcher' => false,", $service);
        $this->assertStringContainsString("(bool) (\$preferences['menu_open_launcher'] ?? false)", $service);
        $this->assertStringContainsString("'preferences.menu_open_launcher' => ['sometimes', 'boolean'],", $controller);
        $this->assertStringContainsString('data-toggle-webos-setting="menu_open_launcher"', $script);
        $this->assertMatchesRegularExpression(
            '/if \(state\.workspace\.preferences\.menu_open_launcher === true\)\s*\{\s*closePanels\(\);\s*openLauncherDialog\(\);\s*return;\s*\}/',
            $script
        );
        // 选项卡核心：数据/开关判定/标题栏渲染/切换/关闭
        $this->assertStringContainsString('function makeWindowTab', $script);
        $this->assertStringContainsString('function windowTabsEnabled', $script);
        $this->assertStringContainsString('function syncWindowTabsBar', $script);
        $this->assertStringContainsString('function switchWindowTab', $script);
        $this->assertStringContainsString('function closeWindowTab', $script);
        // 窗口创建时启用首个选项卡；菜单点击同路径复用选项卡，否则新开
        $this->assertStringContainsString('windowState.tabs = [makeWindowTab(entry)];', $script);
        $this->assertStringContainsString("windowState.tabs.find(function (tab) { return tab.path === entry.path; })", $script);
        // 页面容器切换显示不重建（hidden 切换保留 iframe 状态）+ 事件委托（先关闭叉后切换）
        $this->assertStringContainsString("host.querySelectorAll('.window-page').forEach(function (node) {", $script);
        $this->assertStringContainsString('node.hidden = node.dataset.windowPage !== tab.id;', $script);
        $this->assertStringContainsString('data-window-tab-close', $script);
        // 选项卡溢出导航：超出宽度显示左右切换按钮，激活选项卡自动滚入可视区
        $this->assertStringContainsString('function updateWindowTabsNav', $script);
        $this->assertStringContainsString('function revealWindowTab', $script);
        $this->assertStringContainsString('data-window-tabs-scroll', $script);
        // 品牌区固定显示应用身份：切换/关闭选项卡不重写 window-brand（曾随菜单名变化，用户要求固定应用名）
        $this->assertStringNotContainsString('syncWindowTabBrand', $script);
        // 品牌区副标题跟随当前选项卡：仅更新 strong 内 small 文本，应用名固定（切换/相邻切换/全部关闭清空/新开复用共 4 处调用）
        $this->assertStringContainsString('function syncWindowTabSubtitle', $script);
        $this->assertSame(5, substr_count($script, 'syncWindowTabSubtitle(windowState,'));
        // 选项卡右键菜单：复用桌面右键菜单容器（menuMarkupInto + positionDesktopMenu），刷新页面/关闭当前/其它/全部
        $this->assertStringContainsString('function openWindowTabContextMenu', $script);
        $this->assertStringContainsString('function runWindowTabContextAction', $script);
        // 刷新页面：对齐框架后台刷新按钮——清 token 强制重建当前激活页（iframe 重设 src / 组件重新拉取）
        $this->assertStringContainsString("['刷新页面', 'fa-refresh', 'tab-reload']", $script);
        $this->assertStringContainsString('function reloadWindowTab(', $script);
        $this->assertMatchesRegularExpression(
            "/action === 'tab-reload'\)\s*\{\s*reloadWindowTab\(found\.windowState,\s*found\.tab\);/",
            $script
        );
        // 外层分发白名单必须含 tab-reload，否则点击「刷新页面」被 runDesktopContextAction 丢弃（1.9.88 教训）
        $this->assertStringContainsString("action === 'tab-reload' || action === 'tab-close'", $script);
        // 标题栏刷新按钮（仅选项卡模式渲染）：点击复用 reloadWindowTab 刷新当前激活选项卡
        $this->assertStringContainsString('data-window-action="refresh" aria-label="刷新当前页面"', $script);
        // 无选项卡的特殊窗口（应用中心/OS 设置/通知中心/官网动态）刷新整页：清页面令牌强制重建并重新触发数据渲染
        $this->assertStringContainsString("else if (refreshTarget && refreshTarget.entry && refreshTarget.entry.path) {", $script);
        $this->assertStringContainsString("refreshHost.dataset.pageToken = '';", $script);
        $this->assertStringContainsString("if (refreshTarget.entry.id === 'webos-settings') {", $script);
        $this->assertMatchesRegularExpression(
            "/windowTabsEnabled\(\)\s*\?\s*'<button class=\"window-control window-refresh\" type=\"button\" data-window-action=\"refresh\"/",
            $script
        );
        // 刷新按钮位于品牌区右侧（标题栏直属子元素）：无论有无选项卡条位置恒定，不随控制区右推/选项卡条插序变化
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');
        $this->assertMatchesRegularExpression(
            '/\.window-refresh\s*\{\s*margin-left:\s*4px;/',
            $stylesheet
        );
        $this->assertMatchesRegularExpression(
            "/windowAction\.dataset\.windowAction === 'refresh'\)\s*\{\s*\/\/ 标题栏刷新按钮[\s\S]*?reloadWindowTab\(refreshTarget,\s*refreshTab\);/",
            $script
        );
        // iframe 独立文档点击不冒泡到父文档 click 委托：借 window blur 关闭右键菜单（桌面/任务栏/应用行）
        $this->assertMatchesRegularExpression(
            "/window\.addEventListener\('blur', function \(\)\s*\{\s*closeDesktopContextMenu\(\);\s*closeTaskbarContextMenu\(\);\s*closeAppRowMenus\(\);\s*\}\);/",
            $script
        );
        $this->assertMatchesRegularExpression(
            "/page\.dataset\.pageToken = '';\s*\}\s*windowState\.activeTabId = tab\.id;\s*renderWindowPage\(windowState, tab\);/",
            $script
        );
        $this->assertStringContainsString("'tab-close-others'", $script);
        // 双击标题栏切换最大化还原：dblclick 绑定在拖动标题栏上，控制区按钮（closest('button')）不触发
        $this->assertMatchesRegularExpression(
            "/titlebar\.addEventListener\('dblclick', function \(event\) \{\s*if \(event\.target\.closest\('button'\)\) \{\s*return;\s*\}\s*if \(target\.maximized\) \{\s*restoreWindow\(key\);\s*\} else \{\s*maximizeWindow\(key\);\s*\}\s*\}\);/",
            $script
        );
        $this->assertStringContainsString("'tab-close-all'", $script);
        // 右键监听：closest('[data-window-tab]') 委托 + 右键同时聚焦所在窗口
        $this->assertStringContainsString("event.target.closest('[data-window-tab]')", $script);
        $this->assertStringContainsString('focusWindow(host.dataset.windowKey);', $script);
        // 官网动态窗口：仅超管自动打开、固定 600×500 停靠最右侧、无 page-host 不启用选项卡
        $this->assertStringContainsString('function officialNewsEntry', $script);
        $this->assertStringContainsString('function renderOfficialNews', $script);
        $this->assertStringContainsString('function linkifyOfficialNews', $script);
        $this->assertStringContainsString("'webos-official-news'", $script);
        $this->assertStringContainsString('data-official-news', $script);
        // 图片弹层预览：layer.photos 相册（同组多图可切换），layer 不可用时回退新标签打开
        $this->assertStringContainsString('layer.photos({', $script);
        $this->assertStringContainsString("window.open(img.getAttribute('src'), '_blank', 'noopener');", $script);
        $this->assertStringContainsString('.official-news-shell', $stylesheet);
        // 事件委托宿主窗口变量不得与全局函数同名（var 提升遮蔽 closeWindow 曾致关闭按钮报错）
        $this->assertStringContainsString("tabButton.closest('[data-window-key]')", $script);
        $this->assertStringNotContainsString('var closeWindow =', $script);
        // 选项卡条从标题栏左侧展开：有选项卡加 has-window-tabs 类取消控制区右推，无选项卡移除恢复
        $this->assertStringContainsString("classList.add('has-window-tabs')", $script);
        $this->assertStringContainsString("classList.remove('has-window-tabs')", $script);
        // 标题栏选项卡样式与页面容器隐藏规则
        $this->assertStringContainsString('.window-tab.is-active', $stylesheet);
        $this->assertStringContainsString('.app-window.has-window-tabs .window-controls', $stylesheet);
        $this->assertStringContainsString('.window-tabs-nav[hidden]', $stylesheet);
        // 选项卡不收缩（flex: 0 0 auto）：保持自身宽度触发容器溢出，导航按钮才有意义
        $this->assertMatchesRegularExpression('/\.window-tab\s*\{[^}]*flex: 0 0 auto;/', $stylesheet);
        $this->assertStringContainsString('.window-page[hidden]', $stylesheet);
    }

    /** 多选项卡偏好持久化：HTTP 链路 window_tabs=true 保存后再读保持开启 */
    public function test_workspace_api_persists_window_tabs_preference(): void
    {
        $this->actingAdmin();

        $this->putJson('/admin/cmspro/webos/api/workspace', [
            'preferences' => ['window_tabs' => true],
        ])->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.preferences.window_tabs', true);

        $this->getJson('/admin/cmspro/webos/api/workspace')
            ->assertOk()
            ->assertJsonPath('data.preferences.window_tabs', true);
    }

    public function test_workspace_api_persists_override_admin_home_preference(): void
    {
        $this->actingAdmin();

        // 系统设置「覆盖传统后台」：开启后偏好持久化，供后台布局首页覆盖跳转读取
        $this->putJson('/admin/cmspro/webos/api/workspace', [
            'preferences' => ['override_admin_home' => true],
        ])->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.preferences.override_admin_home', true);

        $this->getJson('/admin/cmspro/webos/api/workspace')
            ->assertOk()
            ->assertJsonPath('data.preferences.override_admin_home', true);

        // 关闭覆盖
        $this->putJson('/admin/cmspro/webos/api/workspace', [
            'preferences' => ['override_admin_home' => false],
        ])->assertOk()
            ->assertJsonPath('data.preferences.override_admin_home', false);
    }

    /** 「点击菜单进入」偏好持久化：HTTP 链路 menu_open_launcher=true 保存后再读保持开启 */
    public function test_workspace_api_persists_menu_open_launcher_preference(): void
    {
        $this->actingAdmin();

        $this->putJson('/admin/cmspro/webos/api/workspace', [
            'preferences' => ['menu_open_launcher' => true],
        ])->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.preferences.menu_open_launcher', true);

        $this->getJson('/admin/cmspro/webos/api/workspace')
            ->assertOk()
            ->assertJsonPath('data.preferences.menu_open_launcher', true);
    }

    public function test_workspace_reset_api_restores_default_layout(): void
    {
        $this->actingAdmin();

        $items = [[
            'id' => 'menu-10',
            'menu_id' => 10,
            'app_id' => 'cmspro.demo',
            'title' => '用户管理',
            'path' => '/admin/user',
            'icon' => 'fa fa-users',
            'group_title' => '系统管理',
            'x' => 0,
            'y' => 0,
        ]];
        $this->putJson('/admin/cmspro/webos/api/workspace', [
            'desktop_items' => $items,
            'taskbar_items' => $items,
            'preferences' => ['override_admin_home' => true, 'taskbar_position' => 'top'],
        ])->assertOk();

        // 重置：非超管（actingAdmin 无 super_admin 角色）桌面/任务栏为空（应用中心仅超管可用），偏好全部回默认
        // （覆盖传统后台关闭、任务栏回底部）
        $this->postJson('/admin/cmspro/webos/api/workspace/reset')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.desktop_items', [])
            ->assertJsonPath('data.taskbar_items', [])
            ->assertJsonPath('data.preferences.override_admin_home', false)
            ->assertJsonPath('data.preferences.taskbar_position', 'bottom');

        $this->getJson('/admin/cmspro/webos/api/workspace')
            ->assertOk()
            ->assertJsonPath('data.desktop_items', [])
            ->assertJsonPath('data.preferences.taskbar_position', 'bottom');
    }

    /** 超级管理员重置工作区：桌面/任务栏恢复初始「应用中心」固定项 */
    public function test_super_admin_reset_pins_application_center(): void
    {
        $admin = $this->actingAdmin();
        $superRole = \App\Models\AdminRole::create(['name' => '超级管理员', 'code' => 'super_admin', 'status' => 1]);
        DB::table('admin_user_roles')->insert(['user_id' => $admin->id, 'role_id' => $superRole->id]);

        $this->postJson('/admin/cmspro/webos/api/workspace/reset')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.desktop_items.0.id', 'webos-app-center')
            ->assertJsonPath('data.desktop_items.0.title', '应用中心')
            ->assertJsonPath('data.taskbar_items.0.id', 'webos-app-center');
    }

    public function test_taskbar_pinned_items_are_independent_from_desktop_and_draggable(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');

        $this->assertIsString($script);

        // 任务栏固定项独立数据源（不再取桌面项前 4 位；仅旧数据兜底保留一处），渲染前按已分配入口过滤
        $this->assertStringContainsString('function taskbarItems()', $script);
        $this->assertStringContainsString('var pinned = taskbarItems().filter(function (item) {', $script);
        $this->assertStringContainsString('return new Set(taskbarItems().map(', $script);
        $this->assertSame(1, substr_count($script, 'desktop_items.slice(0, 4)'));
        // 任务栏固定/解除固定动作不再写桌面列表；固定后重绘运行区避免重复图标
        $this->assertStringContainsString('function addTaskbarItem(entry)', $script);
        $this->assertStringContainsString('function removeTaskbarItem(id)', $script);
        $this->assertStringContainsString('addTaskbarItem(findEntry(id) || findDesktopItem(id));', $script);
        $this->assertStringContainsString('removeTaskbarItem(id);', $script);
        // 固定按应用聚合键去重（同应用多菜单入口只留一个图标）
        $this->assertStringContainsString('var appKey = entryAppKey(entry);', $script);
        $this->assertStringContainsString("toast('该应用已固定在任务栏');", $script);
        // 读取时按 id 去重，兜底历史重复数据
        $this->assertStringContainsString('seen[item.id] = true;', $script);
        // 任务栏固定图标支持拖动排序，保存时随工作区持久化
        $this->assertStringContainsString('function dragTaskbarIcon(button, event)', $script);
        $this->assertStringContainsString('dragTaskbarIcon(button, event);', $script);
        $this->assertStringContainsString('taskbar_items: taskbarItems().map(', $script);
        // 卸载应用时同步清理任务栏固定项
        $this->assertStringContainsString('state.workspace.taskbar_items = remainingPinned;', $script);
    }

    public function test_start_menu_application_cards_support_keyboard_activation(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');

        $this->assertIsString($script);
        $this->assertStringContainsString("event.key === 'Enter' || event.key === ' '", $script);
        $this->assertStringContainsString('startItem.dataset.menuId', $script);
    }

    public function test_start_menu_groups_internal_menus_as_folders(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');

        $this->assertIsString($script);
        $this->assertStringContainsString('folder_id:', $script);
        $this->assertStringContainsString("start_kind: representative.app_id ? 'application' : 'folder'", $script);
        $this->assertStringContainsString(
            "entry.app_id ? 'app:' + entry.app_id : 'folder:' + entry.folder_id",
            $script
        );
        $this->assertStringContainsString("entry.start_kind === 'folder'", $script);
    }

    public function test_start_menu_exposes_system_actions_and_common_entries(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $view = file_get_contents(dirname(__DIR__, 2) . '/Views/Admin/desktop/index.blade.php');

        $this->assertIsString($script);
        $this->assertIsString($view);
        $this->assertStringContainsString('recordEntryUsage', $script);
        $this->assertStringContainsString('Array.isArray(state.workspace.preferences.usage_stats)', $script);
        $this->assertStringContainsString("id: 'common'", $script);
        $this->assertStringContainsString('data-action="lock-desktop"', $view);
        $this->assertStringContainsString('data-action="logout"', $view);
        $this->assertStringContainsString('data-open-webos-settings', $view);
        // 开始菜单底部栏顺序：锁定 → 退出登录（均仅图标，aria-label/title 提示）→ 版权（中间撑开）→ OS 设置（仅图标）
        $this->assertMatchesRegularExpression(
            '/data-action="lock-desktop" aria-label="锁定桌面" title="锁定桌面">[\s\S]{0,80}data-action="logout" aria-label="退出登录" title="退出登录">/',
            $view
        );
        $this->assertMatchesRegularExpression(
            '/class="os-settings" data-open-webos-settings aria-label="OS 设置" title="OS 设置"><i class="fa fa-cog"><\/i><\/button>/',
            $view
        );
    }

    /** 应用中心目录数据直接复用系统接口（与传统后台应用管理同一数据源），不再自建 catalog 接口 */
    public function test_app_center_reuses_system_app_and_menu_apis(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $view = file_get_contents(dirname(__DIR__, 2) . '/Views/Admin/desktop/index.blade.php');

        $this->assertIsString($script);
        $this->assertIsString($view);
        $this->assertStringContainsString("api('/api/admin/apps' + cacheBust)", $script);
        $this->assertStringContainsString("api('/api/admin/menus/user' + cacheBust)", $script);
        // 安装记录：按操作类型筛选拉取系统接口
        $this->assertStringContainsString("'/api/admin/app-logs?per_page=30'", $script);
        $this->assertStringContainsString("'&operation=' + encodeURIComponent(state.recordsOperation)", $script);
        $this->assertStringNotContainsString('data-catalog-url', $view);
    }

    public function test_application_cards_fall_back_to_installed_icon(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');

        $this->assertIsString($script);
        $this->assertStringContainsString('data-app-icon-primary', $script);
        $this->assertStringContainsString('data-app-icon-fallback', $script);
        $this->assertStringContainsString('data-app-icon-fallback hidden data-src', $script);
        $this->assertStringContainsString('fallback.src = fallback.dataset.src;', $script);
        $this->assertStringContainsString('var fallback = app.icon;', $script);
    }

    public function test_application_window_sidebar_can_collapse_and_hides_single_menu(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');

        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('countNavLeaves(navTree) <= 1', $script);
        $this->assertStringContainsString('data-window-action="toggle-sidebar"', $script);
        $this->assertStringContainsString('function toggleWindowSidebar', $script);
        $this->assertStringContainsString('is-sidebar-collapsed', $script);
        $this->assertStringContainsString('.app-window.is-sidebar-collapsed .window-sidebar', $stylesheet);
        $this->assertStringContainsString('flex: 0 0 180px;', $stylesheet);
        $this->assertStringNotContainsString('flex-basis: 176px;', $stylesheet);
    }

    /** 应用窗口标题栏前台菜单下拉框：仅有前台（home）菜单的应用注入，点击菜单项新标签页打开前台地址 */
    public function test_window_home_menu_dropdown_injected_when_app_has_home_menus(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');

        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        // 数据链路：系统菜单树接口按前台终端过滤，前端按窗口应用 app_id 筛选叶子
        $this->assertStringContainsString("api('/api/admin/menus/tree?terminal_type=home')", $script);
        $this->assertStringContainsString('function loadWindowHomeMenu', $script);
        $this->assertStringContainsString('function collectHomeMenuLeaves', $script);
        $this->assertStringContainsString('function windowHomeMenuMarkup', $script);
        // 挂载点：打开窗口与切换入口后均以窗口状态注入前台菜单下拉框
        $this->assertStringContainsString('loadWindowHomeMenu(windowState);', $script);
        // 注入锚点：固定「收起左侧菜单」按钮左侧，与选项卡条/溢出导航的注入时序无关
        $this->assertStringContainsString("controls.querySelector('.sidebar-toggle')", $script);
        $this->assertStringContainsString("anchor.insertAdjacentHTML('beforebegin', windowHomeMenuMarkup(leaves));", $script);
        // 交互：触发器切换展开/收起，菜单项携带前台地址新标签页打开
        $this->assertStringContainsString("data-window-action=\"toggle-home-menu\"", $script);
        $this->assertStringContainsString("window.open(homeMenuItem.dataset.homeUrl, '_blank', 'noopener');", $script);
        $this->assertStringContainsString('[data-home-url]', $script);
        // 样式与注入标记
        $this->assertStringContainsString('.window-home-menu-list', $stylesheet);
        $this->assertStringContainsString('.window-home-menu-item', $stylesheet);
    }

    /** 与框架权限对齐：非超管仅授权应用子菜单而未授权父级分组时，menus/user 需补全祖先链（否则 WebOS 目录丢失整个应用） */
    public function test_user_menus_completes_missing_parent_chain_for_role_granted_children(): void
    {
        $admin = AdminUser::create([
            'username' => 'webos_orphan_' . uniqid(),
            'password' => bcrypt('123456'),
            'name' => '非超管测试账号',
            'status' => 1,
        ]);

        $role = \App\Models\AdminRole::create([
            'name' => '中医治疗师',
            'code' => 'childrehab_tester_' . uniqid(),
            'status' => 1,
        ]);
        DB::table('admin_user_roles')->insert(['user_id' => $admin->id, 'role_id' => $role->id]);

        // 三层菜单：祖父分组 → 应用分组 → 应用子菜单；仅把子菜单授予角色
        $root = AdminMenu::create(['name' => '管理', 'parent_id' => 0, 'terminal_type' => 'admin', 'status' => 1, 'visible' => 1, 'sort' => 1]);
        $group = AdminMenu::create(['name' => '儿康管理', 'parent_id' => $root->id, 'app_id' => 'cmspro.childrehab', 'terminal_type' => 'admin', 'status' => 1, 'visible' => 1, 'sort' => 2]);
        $leaf = AdminMenu::create(['name' => '工作台', 'parent_id' => $group->id, 'app_id' => 'cmspro.childrehab', 'path' => '/admin/childrehab/dashboard', 'terminal_type' => 'admin', 'status' => 1, 'visible' => 1, 'sort' => 3]);
        AdminMenu::create(['name' => '未授权菜单', 'parent_id' => 0, 'path' => '/admin/other', 'terminal_type' => 'admin', 'status' => 1, 'visible' => 1, 'sort' => 4]);

        DB::table('admin_role_menus')->insert(['role_id' => $role->id, 'menu_id' => $leaf->id]);

        $this->actingAs($admin, 'admin');

        $tree = $this->getJson('/api/admin/menus/user')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->json('data');

        $names = collect($tree)->pluck('name')->all();
        $this->assertNotContains('未授权菜单', $names, '未授权的菜单不应出现在目录中');
        $this->assertContains('管理', $names, '补全后的祖父分组应出现在菜单树根部');

        $rootNode = collect($tree)->firstWhere('id', $root->id);
        $this->assertNotNull($rootNode, '补全后的祖父分组节点存在');
        $groupNode = collect($rootNode['children'] ?? [])->firstWhere('id', $group->id);
        $this->assertNotNull($groupNode, '补全后的父级分组应出现在菜单树中');
        $this->assertEquals([$leaf->id], collect($groupNode['children'] ?? [])->pluck('id')->all(), '授权叶子挂在补全的父级之下');
    }

    /** 桌面/任务栏图标按「已分配」过滤：特殊入口保留，未分配的应用快捷方式与菜单入口不再渲染 */
    public function test_workspace_icons_filtered_by_assigned_entries(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');

        $this->assertIsString($script);
        // 可见应用集合：从当前用户菜单目录递归收集 app_id，作为应用快捷方式的分配口径
        $this->assertStringContainsString('function visibleAppIds', $script);
        $this->assertStringContainsString('collect(state.catalog.menus);', $script);
        // 分配判断：特殊入口永远保留；菜单入口须在 flatMenus；应用快捷方式须目录中仍有该应用菜单
        $this->assertStringContainsString('function entryAssigned', $script);
        $this->assertStringContainsString("entryId.indexOf(appPrefix) === 0", $script);
        // 桌面与任务栏渲染前过滤未分配入口
        $this->assertStringContainsString('state.workspace.desktop_items.filter(function (item) {', $script);
        $this->assertStringContainsString('return entryAssigned(item.id);', $script);
        $this->assertStringContainsString('taskbarItems().filter(function (item) {', $script);
        // 菜单目录刷新后同步重绘桌面与任务栏
        $this->assertMatchesRegularExpression('/renderDesktop\(\);\s*[\r\n]+\s*renderPinnedApps\(\);/', $script);
    }

    /** 停用应用桌面图标不占位：可见集合统一过滤，布局归一化让重新启用的图标自动落到空白格 */
    public function test_disabled_app_icons_do_not_occupy_grid_cells(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');

        $this->assertIsString($script);
        // 可见桌面项统一出口：渲染/拖拽占用/找空行都基于已分配集合
        $this->assertStringContainsString('function assignedDesktopItems', $script);
        $this->assertMatchesRegularExpression('/return state\.workspace\.desktop_items\.filter\(function \(item\) \{\s*[\r\n]+\s*return entryAssigned\(item\.id\);/', $script);
        // 拖拽落点占用集合仅统计可见图标（未分配入口的格子可移入）
        $this->assertStringContainsString('var occupied = new Set(assignedDesktopItems().filter(function (entry) {', $script);
        // 新增入口找空行仅统计可见图标
        $this->assertStringContainsString('var occupied = assignedDesktopItems().map(function (item) { return Number(item.y) || 0; });', $script);
        // 布局归一化：坐标冲突的重新启用图标按 (y,x) 顺序下移到第一个空白格并静默保存
        $this->assertStringContainsString('function normalizeDesktopLayout', $script);
        $this->assertStringContainsString('while (occupied.has(x + \',\' + y) && guard < 200) {', $script);
        $this->assertStringContainsString('if (changed) { saveWorkspace(false).catch(function () {}); }', $script);
        // 目录刷新与首次渲染前均执行归一化
        $this->assertSame(2, substr_count($script, 'normalizeDesktopLayout();'));
    }

    /** 桌面右键「查看」子菜单切换大/中/小图标：三档格子换算、偏好持久化与后端白名单校验 */
    public function test_desktop_view_submenu_switches_icon_size(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');
        $service = file_get_contents(dirname(__DIR__, 2) . '/Services/WorkspaceService.php');

        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        $this->assertIsString($service);
        // 三档格子常量（中档与历史布局 102x104 完全一致，保证默认中图标与现状相同）
        $this->assertStringContainsString('var DESKTOP_ICON_SIZES = {', $script);
        $this->assertStringContainsString('large: { cellW: 128, cellH: 130, iconW: 112, iconH: 122 }', $script);
        $this->assertStringContainsString('medium: { cellW: 102, cellH: 104, iconW: 88, iconH: 96 }', $script);
        $this->assertStringContainsString('small: { cellW: 86, cellH: 88, iconW: 72, iconH: 78 }', $script);
        $this->assertStringContainsString('function desktopIconSize', $script);
        // 渲染与拖拽落点按档位换算（renderDesktop 与拖拽 move 各一处）
        $this->assertSame(2, substr_count($script, 'var size = desktopIconSize();'));
        $this->assertStringContainsString('var dropSize = desktopIconSize();', $script);
        $this->assertStringContainsString('root.dataset.iconSize = ', $script);
        // 右键「查看」子菜单与切档动作（当前档位勾选）
        $this->assertStringContainsString("['查看', 'fa-th-large', '', '', sizeChildren]", $script);
        $this->assertStringContainsString("action === 'icon-large' || action === 'icon-medium' || action === 'icon-small'", $script);
        $this->assertStringContainsString('desktop-context-submenu', $stylesheet);
        $this->assertStringContainsString('.webos-desktop[data-icon-size="large"] .desktop-icon-badge', $stylesheet);
        // 偏好默认中图标 + 后端白名单与枚举校验
        $this->assertStringContainsString("icon_size: 'medium',", $script);
        $this->assertStringContainsString("'icon_size' => 'medium',", $service);
        $this->assertStringContainsString("in_array(\$preferences['icon_size'] ?? '', ['small', 'medium', 'large'], true)", $service);
    }

    /** 应用中心与 WebOS 升级提醒仅对超级管理员生效：runtime 注入超管标记，前端控制初始预置/图标过滤/升级提醒 */
    public function test_app_center_and_self_update_are_super_admin_only(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $controller = file_get_contents(dirname(__DIR__, 2) . '/Controllers/Admin/WebosController.php');
        $service = file_get_contents(dirname(__DIR__, 2) . '/Services/WorkspaceService.php');

        $this->assertIsString($script);
        $this->assertIsString($controller);
        $this->assertIsString($service);
        // 后端 runtime 注入超管标记
        $this->assertStringContainsString("'is_super_admin' => \$admin->isSuperAdmin(),", $controller);
        // 前端读取超管标记
        $this->assertStringContainsString('var isSuperAdmin = !!(runtime.admin && runtime.admin.is_super_admin);', $script);
        // 初始预置：非超管不固定应用中心
        $this->assertStringContainsString('var selected = isSuperAdmin ? [applicationCenterEntry()] : [];', $script);
        // 图标过滤：非超管的桌面/任务栏不显示应用中心（含历史工作区数据）
        $this->assertStringContainsString("if (entryId === 'webos-app-center') { return isSuperAdmin; }", $script);
        // 升级提醒：非超管跳过 WebOS 自身版本检测
        $this->assertStringContainsString('if (!isSuperAdmin) { return; }', $script);
        // 重置：非超管重置后桌面/任务栏为空
        $this->assertStringContainsString('$isSuperAdmin ? [$defaultEntry] : []', $service);
    }

    public function test_window_brand_uses_application_icon_and_keeps_system_logo(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');

        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('application.is_system', $script);
        $this->assertStringContainsString("applicationIconMarkup(application, 'window-brand-icon')", $script);
        $this->assertStringContainsString('<img src="/Images/logo-80x80.png"', $script);
        $this->assertStringContainsString('.window-brand-icon', $stylesheet);
    }

    /** 安装弹窗精简：默认加入系统菜单（不展示该选项），桌面快捷方式为可选，layui 风格无毛玻璃 */
    public function test_install_dialog_uses_terminal_aware_menu_mounting(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $view = file_get_contents(dirname(__DIR__, 2) . '/Views/Admin/desktop/index.blade.php');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');

        $this->assertIsString($script);
        $this->assertIsString($view);
        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('/menu-terminals', $script);
        $this->assertStringContainsString('/prepare', $script);
        $this->assertStringContainsString('terminal_type=', $script);
        $this->assertStringContainsString('parent_menu_ids', $script);
        $this->assertStringContainsString('id="install-menu-parents"', $view);
        $this->assertStringContainsString('安装后默认添加到系统菜单。', $view);
        $this->assertStringContainsString('id="install-create-shortcut"', $view);
        $this->assertStringContainsString('installCreateShortcut.checked', $script);
        $this->assertStringNotContainsString('install_entry', $script);
        $this->assertStringNotContainsString('install_entry', $view);
        $this->assertStringContainsString('webos-dialog--plain', $view);
        $this->assertStringContainsString('backdrop-filter: none', $stylesheet);
    }

    /** 安装成功后询问启用（对齐传统后台）；已安装/未安装/应用更新点击应用名称进入本地详情 */
    public function test_install_prompts_enable_and_local_app_detail(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');

        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        // 安装成功后询问启用（confirmInstall 内调用，与后台 layer.confirm 一致）
        $this->assertStringContainsString('promptEnableApp(target.app_id, target.name);', $script);
        // 本地详情：三个列表的应用名称可点击，复用市场详情布局与返回按钮委托
        $this->assertStringContainsString('function openLocalDetail(shell, appId, source)', $script);
        $this->assertStringContainsString('data-local-detail', $script);
        $this->assertStringContainsString('data-local-source="installed"', $script);
        $this->assertStringContainsString("data-local-source=\"' + (mode === 'updates' ? 'updates' : 'local') + '\"", $script);
        $this->assertStringContainsString('strong.app-card-title', $stylesheet);
    }

    /** 未安装列表：导出对等已安装、右上角 × 物理删除（悬停显示）；卸载/删除确认弹窗 layui 无毛玻璃 */
    public function test_uninstalled_export_delete_and_plain_uninstall_dialog(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');

        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        // 未安装卡片：安装按钮下提供导出（复用 exportAppPackage），卡片右上角 × 删除
        $this->assertStringContainsString('data-export-id', $script);
        $this->assertStringContainsString('data-export-source="local"', $script);
        $this->assertStringContainsString('data-delete-app-id', $script);
        $this->assertStringContainsString('app-card-remove', $stylesheet);
        $this->assertStringContainsString('.app-card:hover .app-card-remove', $stylesheet);
        // 未安装卡片右上角删除按钮：卡片右侧预留空间，避免操作按钮列与删除按钮重叠
        $this->assertStringContainsString('.app-card.has-remove', $stylesheet);
        // 已安装列表沿用后台应用管理的默认排序：安装/更新时间较新者在前
        $this->assertStringContainsString('function sortInstalledApps(apps)', $script);
        $this->assertStringContainsString('apps = sortInstalledApps(apps);', $script);
        // 物理删除对标后台 deleteAppFiles：输入应用名确认后调用系统删除接口
        $this->assertStringContainsString('function openDeleteFilesDialog(appId, appName)', $script);
        $this->assertStringContainsString("'/delete'", $script);
        $this->assertStringContainsString('此操作将永久删除应用的所有文件，此操作不可恢复！', $script);
        // 卸载与删除确认弹窗均为 layui 精简风格（无毛玻璃）；管理入口弹窗同风格
        $this->assertStringContainsString("elements.actionDialog.classList.toggle('webos-dialog--plain', options.plain === true)", $script);
        $this->assertStringContainsString("kicker: '入口管理',", $script);
        $this->assertStringContainsString("kicker: '入口管理',\n            title: '「' + appDisplayName(app) + '」应用入口',\n            plain: true,", $script);
        // 卸载/禁用引导/删除对话宽度 660px
        $this->assertStringContainsString("width: 'min(660px, calc(100vw - 60px))'", $script);
        $this->assertStringContainsString('plain: true,', $script);
        // 输入匹配点亮确认按钮：is-armed 脉冲动画 + 未匹配灰显
        $this->assertStringContainsString("submit.classList.toggle('is-armed', matched);", $script);
        $this->assertStringContainsString('.webos-button.danger.is-armed {', $stylesheet);
        $this->assertStringContainsString('@keyframes webos-button-armed {', $stylesheet);
        $this->assertStringContainsString('.webos-button.danger:disabled {', $stylesheet);
        // 安装勾选创建桌面快捷方式时，快捷方式标题使用应用名称而非菜单名称
        $this->assertStringContainsString("addDesktopEntry(Object.assign({}, entry, { title: target.name || entry.title }));", $script);
        // 卸载/删除执行期间均只显示加载特效（无文字提示）
        $this->assertStringContainsString('var loadIndex = layer.load(2, { time: 0 });', $script);
        $this->assertStringContainsString('var loadIndex = layer.load(2);' . "\n" . '        api(', $script);
        $this->assertStringNotContainsString("layer.load(2, { content:", $script);
    }

    public function test_notification_center_and_records_ux(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $view = file_get_contents(dirname(__DIR__, 2) . '/Views/Admin/desktop/index.blade.php');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');

        $this->assertIsString($script);
        $this->assertIsString($view);
        $this->assertIsString($stylesheet);

        // 通知中心：待办 / 通知 Tab 分离 + 待办计数徽标 + 未读蓝点 + 底部全部已读与查看全部
        $this->assertStringContainsString('data-notification-tab="todos"', $view);
        $this->assertStringContainsString('data-notification-tab="notifications"', $view);
        $this->assertStringContainsString('notification-todo-list', $view);
        $this->assertStringContainsString('notification-footer', $view);
        $this->assertStringContainsString('function setNotificationTab(', $script);
        $this->assertStringContainsString("'/api/admin/notifications/todo-read'", $script);
        $this->assertStringContainsString('notification-dot', $script);
        $this->assertStringContainsString('.notification-tab.is-active::after', $stylesheet);
        $this->assertStringContainsString('.notification-todo-badge', $stylesheet);
        $this->assertStringContainsString('.notification-footer', $stylesheet);
        // 底部「查看全部」在 WebOS 通知中心窗口中打开（左侧待办/通知菜单 + 对应列表）
        $this->assertStringContainsString('data-open-notification-page', $view);
        $this->assertStringContainsString("openEntry(notificationCenterEntry());", $script);
        $this->assertStringContainsString("id: 'webos-notification-page',", $script);
        $this->assertStringContainsString("special: 'notifications'", $script);
        $this->assertStringContainsString('NOTIFICATION_CENTER_TABS = [', $script);
        $this->assertStringContainsString('function notificationCenterSidebarMarkup()', $script);
        $this->assertStringContainsString('function renderNotificationCenter(', $script);
        $this->assertStringContainsString("var url = '/api/admin/notifications?per_page=15&page=' + page;", $script);
        // 窗口工具栏右侧：全部/已读/未读筛选 + 全部已读按钮（待办逐条标记，通知走系统 read-all）
        $this->assertStringContainsString('function notificationToolbarActionsMarkup()', $script);
        $this->assertStringContainsString('data-notification-filter', $script);
        $this->assertStringContainsString('data-notification-read-all', $script);
        $this->assertStringContainsString('function markAllNotificationCenterRead(button)', $script);
        $this->assertStringContainsString('notificationCenterState.noticeFilter === \'unread\'', $script);
        // 通知标记已读后刷新任务栏徽标（/badge 驱动）：窗口行与面板行均已接入 loadNotifications
        $this->assertStringContainsString('data-notification-id=', $script);
        $this->assertStringContainsString('.then(loadNotifications)', $script);
        $this->assertStringContainsString('.notification-filter-btn.is-active {', $stylesheet);
        $this->assertStringContainsString("'/read', { method: 'POST' })", $script);
        $this->assertStringContainsString('data-notification-goto-page', $script);
        $this->assertStringContainsString('.webos-notifications-shell {', $stylesheet);
        // 通知/待办点击优先在 WebOS 内打开对应应用窗口（按路径匹配菜单入口），无匹配回退新标签页
        $this->assertStringContainsString('function openLinkInWebos(link) {', $script);
        $this->assertStringContainsString('openLinkInWebos(todo.getAttribute(\'href\'));', $script);
        $this->assertStringContainsString('openLinkInWebos(item.dataset.notificationLink);', $script);
        $this->assertStringContainsString('openLinkInWebos(noticeLink);', $script);
        // 通知中心待办数据源：WebOS 自身不注册演示数据，由其他应用通过系统 hook 接入；
        // 窗口待办列表显示全部（含已读）走应用侧全量接口 + 已读弱化样式
        $this->assertStringContainsString("'/admin/cmspro/webos/api/all-todos'", $script);
        $this->assertStringContainsString("class=\"notification-todo' + (isRead ? ' is-read' : '')", $script);
        $this->assertStringContainsString('.notification-todo.is-read {', $stylesheet);
        $this->assertStringContainsString('public function allTodos(): JsonResponse', file_get_contents(dirname(__DIR__, 2) . '/Controllers/Admin/WebosController.php'));
        $this->assertStringContainsString("Route::get('/all-todos', [WebosController::class, 'allTodos'])", file_get_contents(dirname(__DIR__, 2) . '/Routes/admin.php'));
        // 全量待办走应用内子类公开（线上全局 NotificationService 无 allTodos 包装方法，避免框架级依赖）
        $webosNotification = file_get_contents(dirname(__DIR__, 2) . '/Services/WebosNotificationService.php');
        $this->assertStringContainsString('use App\Services\NotificationService;', $webosNotification);
        $this->assertStringContainsString('return $this->aggregateTodoEntries();', $webosNotification);
        // 部署兜底：线上框架服务为旧版（无 aggregateTodoEntries）时降级为 todos()，避免接口 500
        $this->assertStringContainsString("method_exists(\$this, 'aggregateTodoEntries')", $webosNotification);
        $this->assertStringContainsString('return $this->todos();', $webosNotification);
        $controller = file_get_contents(dirname(__DIR__, 2) . '/Controllers/Admin/WebosController.php');
        $this->assertStringContainsString('app(WebosNotificationService::class)->allTodos()', $controller);
        $this->assertStringNotContainsString('app(NotificationService::class)->allTodos()', $controller);

        // 安装记录：搜索（名称/标识）与类型筛选移至右上角工具区 + 日期分组 + 右侧操作详情
        $this->assertStringContainsString("records: '搜索应用名称或标识'", $script);
        $this->assertStringContainsString("state.appCenterTab === 'records'", $script);
        $this->assertStringNotContainsString('data-records-search', $script);
        $this->assertStringContainsString('data-records-operation', $script);
        $this->assertStringContainsString("String(recordAppName(entry.log.app_id) || '').toLowerCase().indexOf(keyword) >= 0", $script);
        $this->assertStringNotContainsString('records-toolbar', $script);
        $this->assertStringNotContainsString('records-toolbar', $stylesheet);
        $this->assertStringContainsString('function loadRecordsTab(', $script);
        $this->assertStringContainsString('function renderRecordGroups(', $script);
        $this->assertStringContainsString('function renderRecordDetail(', $script);
        $this->assertStringContainsString("label = match[1] === todayStamp ? '今天' : (match[1] === yesterdayStamp ? '昨天' : '更早');", $script);
        $this->assertStringContainsString('.records-layout {', $stylesheet);
        $this->assertStringContainsString('.records-detail {', $stylesheet);
        $this->assertStringContainsString('.record-detail-error {', $stylesheet);
        // 记录行显示应用名称（目录 → 市场缓存 → 应用标识）；图标按全局优先级：服务端 icon.svg → icon.png → manifest icon → 通用占位
        $this->assertStringContainsString('function recordAppName(', $script);
        $this->assertStringContainsString('return (app && (app.name || app.title)) || appId;', $script);
        $this->assertStringContainsString('function fillRecordAppInfo(', $script);
        $this->assertStringContainsString("'/api/admin/apps/available'", $script);
        $this->assertStringContainsString("'/api/admin/market/apps/' + encodeURIComponent(appId)", $script);
        $this->assertStringContainsString("src=\"/api/app/' + encodeURIComponent(appId) + '/icon\"", $script);
        $this->assertStringContainsString('data-app-icon-primary', $script);
        $this->assertStringContainsString('data-app-icon-fallback', $script);
        $this->assertStringContainsString('.record-row-icon-inner {', $stylesheet);
        $this->assertStringContainsString('object-fit: contain;', $stylesheet);
        // 旧表格实现已废弃
        $this->assertStringNotContainsString('records-table', $script);
        $this->assertStringNotContainsString('status-pill', $stylesheet);
    }

    public function test_market_base_url_is_passed_to_frontend(): void
    {
        $this->actingAdmin();
        config(['apps.market.api_url' => 'https://v5.cmspro.cn/']);

        $this->get('/admin/cmspro/webos')
            ->assertOk()
            ->assertSee('data-market-base-url="//v5.cmspro.cn"', false);
    }

    public function test_application_icons_are_reused_on_desktop_taskbar_and_start_menu(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');

        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('function entryIconMarkup(entry, className)', $script);
        $this->assertStringContainsString("entryIconMarkup(findEntry(item.id) || item, 'desktop-icon-badge')", $script);
        $this->assertStringContainsString("entryIconMarkup(entry, 'taskbar-app-icon')", $script);
        $this->assertStringContainsString("entryIconMarkup(windowState.entry, 'taskbar-app-icon')", $script);
        $this->assertStringContainsString("entryIconMarkup(item, 'start-app-item-icon')", $script);
        $this->assertStringContainsString('is-app-icon', $stylesheet);
        // 拖拽应用换顶级分类（整个应用含应用文件夹节点整体移动）：flattenMenus 输出 app_node_id、
        // 卡片收集优先收节点 id（子树随父节点移动）、超管条件渲染 draggable、drop 复用系统菜单移动接口
        $this->assertStringContainsString('app_node_id: appNodeId || \'\'', $script);
        $this->assertStringContainsString("var nextAppNodeId = item.app_id ? String(item.id || '') : (inheritedAppNodeId || '');", $script);
        $this->assertStringContainsString('if (entry.app_node_id) {', $script);
        $this->assertStringContainsString('moveIds.indexOf(entry.app_node_id) < 0', $script);
        $this->assertStringContainsString('moveIds.push(Number(representative.folder_id));', $script);
        $this->assertStringContainsString('isSuperAdmin && !item.special && item.move_ids && item.move_ids.length', $script);
        $this->assertStringContainsString('draggable="true" data-move-ids', $script);
        $this->assertStringContainsString("api('/api/admin/menus/move', { method: 'PUT', body: { ids: moveIds, parent_id: Number(category.dataset.groupId) } })", $script);
        $this->assertStringContainsString('return refreshCatalog();', $script);
        // 移动/安装后刷新目录必须拿到实时数据：loadCatalog 的 GET 加时间戳破坏 HTTP 缓存
        $this->assertStringContainsString("var cacheBust = '?_t=' + Date.now();", $script);
        $this->assertStringContainsString('.start-category-button.is-drop-target', $stylesheet);
        $this->assertStringContainsString('.start-app-item[draggable="true"]', $stylesheet);
    }

    public function test_market_cards_use_remote_icon_and_install_state(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');

        $this->assertIsString($script);
        $this->assertStringContainsString('function marketIconUrl(icon)', $script);
        $this->assertStringContainsString('root.dataset.marketBaseUrl', $script);
        $this->assertStringContainsString('function compareVersions(leftVersion, rightVersion)', $script);
        $this->assertStringContainsString('function installedVersions()', $script);
        $this->assertStringContainsString('data-market-icon', $script);
        $this->assertStringContainsString('fa fa-puzzle-piece', $script);
        $this->assertStringContainsString("marketState(app)", $script);
    }

    public function test_installed_tab_renders_list_view_with_status_filter(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');
        $view = file_get_contents(dirname(__DIR__, 2) . '/Views/Admin/desktop/index.blade.php');

        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        $this->assertIsString($view);
        $this->assertStringContainsString('function renderInstalledList(apps)', $script);
        $this->assertStringContainsString('class="app-install-list"', $script);
        $this->assertStringContainsString('data-app-status-filter', $script);
        $this->assertStringContainsString('data-toggle-app-status', $script);
        $this->assertStringContainsString('<span>应用信息</span><span>状态</span><span>操作</span>', $script);
        $this->assertStringContainsString('id="action-dialog"', $view);
        // 应用包选择框改由上传弹层动态创建并随弹层销毁，桌面页不再保留静态 input
        $this->assertStringNotContainsString('id="app-package-input"', $view);
    }

    public function test_installed_list_drops_entry_column_and_status_text(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');

        $this->assertIsString($script);
        $this->assertStringNotContainsString('入口位置', $script);
        $this->assertStringNotContainsString('function appEntryTagsMarkup', $script);
        $this->assertStringContainsString('"><i></i></button>', $script);
        $this->assertStringContainsString('aria-label="\' + escapeHtml(text)', $script);
    }

    public function test_status_filter_is_limited_to_installed_tab(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');

        $this->assertIsString($script);
        $this->assertStringContainsString("var options = [['', '全部状态'], ['1', '已启用'], ['2', '已禁用']];", $script);
        $this->assertStringContainsString("if (tab !== 'installed') {", $script);
        $this->assertStringContainsString("'<button class=\"webos-button secondary compact\" type=\"button\" data-app-upload>", $script);
    }

    public function test_action_dialog_keeps_header_visible_and_scrolls_body(): void
    {
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');

        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('.action-dialog-card {', $stylesheet);
        $this->assertStringContainsString('max-height: calc(100vh - 60px);', $stylesheet);
        $this->assertStringContainsString('.action-dialog-card .dialog-body {', $stylesheet);
        $this->assertStringContainsString('overflow-y: auto;', $stylesheet);
    }

    public function test_calendar_api_returns_lunar_and_solar_term_data(): void
    {
        $this->actingAdmin();

        $this->getJson('/admin/cmspro/webos/api/calendar?month=2026-09')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.month', '2026-09')
            ->assertJsonPath('data.days.2026-09-29.lunar_month', '八月')
            ->assertJsonPath('data.days.2026-09-29.lunar_day', '十九')
            ->assertJsonPath('data.days.2026-09-23.term', '秋分')
            ->assertJsonPath('data.days.2026-09-07.term', '白露');

        $this->getJson('/admin/cmspro/webos/api/calendar?month=2026-13')
            ->assertUnprocessable()
            ->assertJsonPath('code', 40201);

        $this->getJson('/admin/cmspro/webos/api/calendar')
            ->assertUnprocessable()
            ->assertJsonPath('code', 40201);
    }

    public function test_calendar_panel_renders_lunar_labels_and_today_button_rule(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');
        $view = file_get_contents(dirname(__DIR__, 2) . '/Views/Admin/desktop/index.blade.php');

        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        $this->assertIsString($view);
        $this->assertStringContainsString('data-calendar-url="{{ url(\'/admin/cmspro/webos/api/calendar\') }}"', $view);
        $this->assertStringContainsString('id="calendar-today" data-calendar-today hidden', $view);
        $this->assertStringContainsString('function calendarDayLabel(info)', $script);
        $this->assertStringContainsString('function describeLunar(key)', $script);
        $this->assertStringContainsString('function loadCalendarMonth(date)', $script);
        $this->assertStringContainsString('state.calendarMonths.set(key', $script);
        $this->assertStringContainsString('elements.calendarToday.hidden = !selected || state.calendarSelected === todayKey;', $script);
        $this->assertStringContainsString("calendar-day-label' + (info && info.term ? ' is-term' : '')", $script);
        $this->assertStringContainsString('.calendar-day-label {', $stylesheet);
        $this->assertStringContainsString('.calendar-day-label.is-term', $stylesheet);
    }

    public function test_os_settings_controls_default_window_size(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');

        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('function settingsRangeMarkup(name, title, value)', $script);
        $this->assertStringContainsString("settingsRangeMarkup('window_width', '默认宽度'", $script);
        $this->assertStringContainsString("settingsRangeMarkup('window_height', '默认高度'", $script);
        $this->assertStringContainsString('data-set-window-size="', $script);
        $this->assertStringContainsString('function defaultWindowSize(key)', $script);
        $this->assertStringContainsString('function clampWindowRatio(value, fallback)', $script);
        $this->assertStringContainsString('function setWindowSizePreference(name, value)', $script);
        $this->assertStringContainsString("root.style.setProperty('--webos-window-width'", $script);
        $this->assertStringContainsString("root.style.setProperty('--webos-window-height'", $script);
        $this->assertStringContainsString('var size = defaultWindowSize(key);', $script);
        $this->assertStringContainsString('width: min(var(--webos-window-width, 78%)', $stylesheet);
        $this->assertStringContainsString('height: min(var(--webos-window-height, 80%)', $stylesheet);
        $this->assertStringContainsString('.settings-range input[type="range"]', $stylesheet);
    }

    public function test_workspace_api_accepts_window_size_preferences(): void
    {
        $this->actingAdmin();

        $this->putJson('/admin/cmspro/webos/api/workspace', [
            'preferences' => ['window_width' => 64, 'window_height' => 92],
        ])->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.preferences.window_width', 64)
            ->assertJsonPath('data.preferences.window_height', 92);

        $this->putJson('/admin/cmspro/webos/api/workspace', [
            'preferences' => ['window_width' => 20],
        ])->assertUnprocessable()
            ->assertJsonPath('code', 40201);
    }

    public function test_account_menu_opens_profile_and_password_dialog(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');
        $view = file_get_contents(dirname(__DIR__, 2) . '/Views/Admin/desktop/index.blade.php');

        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        $this->assertIsString($view);
        $this->assertStringContainsString('data-account-path="/admin/account"', $view);
        $this->assertStringContainsString('data-action="open-password-dialog"', $view);
        // 清除缓存：账号菜单项 + 点击分发复用系统缓存清理接口
        $this->assertStringContainsString('data-action="clear-cache"', $view);
        $this->assertStringContainsString("api('/api/admin/cache/clear')", $script);
        $this->assertStringContainsString('if (name === \'clear-cache\')', $script);
        $this->assertStringContainsString('data-action="lock-desktop"', $view);
        $this->assertStringContainsString('data-action="logout"', $view);
        $this->assertStringContainsString('function openPasswordDialog()', $script);
        $this->assertStringContainsString('function submitPasswordChange()', $script);
        $this->assertStringContainsString('class="password-form"', $script);
        $this->assertStringContainsString("event.target.classList.contains('password-form')", $script);
        $this->assertStringContainsString("'/api/admin/auth/password'", $script);
        $this->assertStringContainsString('old_password: values.old', $script);
        $this->assertStringContainsString('新密码长度需为 6-20 位', $script);
        $this->assertStringContainsString('两次输入的新密码不一致', $script);
        $this->assertStringContainsString('.dialog-field + .dialog-field', $stylesheet);
    }

    public function test_account_menu_drops_taskbar_position_switch(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2) . '/Views/Admin/desktop/index.blade.php');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');

        $this->assertIsString($view);
        $this->assertIsString($stylesheet);
        $this->assertIsString($script);
        $this->assertStringNotContainsString('taskbar-position-setting', $view);
        $this->assertStringNotContainsString('taskbar-position-options', $stylesheet);
        $this->assertStringContainsString("settingsChoice('set-taskbar-position'", $script);
    }

    public function test_clock_opens_calendar_and_renders_three_part_time(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');
        $view = file_get_contents(dirname(__DIR__, 2) . '/Views/Admin/desktop/index.blade.php');

        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        $this->assertIsString($view);
        $this->assertStringContainsString('id="calendar-panel"', $view);
        $this->assertStringContainsString('id="calendar-grid"', $view);
        $this->assertStringContainsString('id="clock-weekday"', $view);
        $this->assertStringContainsString('data-calendar-nav="prev"', $view);
        $this->assertStringContainsString('data-calendar-today', $view);
        $this->assertStringContainsString('function renderCalendar()', $script);
        $this->assertStringContainsString('function openCalendar()', $script);
        $this->assertStringContainsString('function shiftCalendarMonth(offset)', $script);
        $this->assertStringContainsString('function selectCalendarDate(key)', $script);
        $this->assertStringContainsString("togglePanel('calendar', elements.calendarPanel, elements.clockButton)", $script);
        $this->assertStringContainsString('calendarDayMarkup(date, view, todayKey, selectedKey)', $script);
        $this->assertStringContainsString("elements.clockWeekday.textContent = now.toLocaleDateString('zh-CN', { weekday: 'short' })", $script);
        $this->assertStringContainsString('.calendar-panel {', $stylesheet);
        $this->assertStringContainsString('.calendar-day.is-selected', $stylesheet);
    }

    public function test_taskbar_clock_adapts_to_taskbar_position(): void
    {
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');

        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('.clock-meta {', $stylesheet);
        $this->assertStringContainsString('.webos-desktop[data-taskbar-position="left"] .clock-meta,', $stylesheet);
        $this->assertStringContainsString('.webos-desktop[data-taskbar-position="right"] .clock-meta {', $stylesheet);
        $this->assertStringContainsString('font-size: 9px;', $stylesheet);
        $this->assertStringContainsString('letter-spacing: -0.2px;', $stylesheet);
    }

    public function test_desktop_icon_context_menu_offers_open_remove_and_uninstall(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');

        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        $this->assertStringContainsString("elements.desktopIcons.addEventListener('contextmenu'", $script);
        $this->assertStringContainsString('function openDesktopContextMenu(iconId, clientX, clientY)', $script);
        $this->assertStringContainsString('function closeDesktopContextMenu()', $script);
        $this->assertStringContainsString('function runDesktopContextAction(action, iconId)', $script);
        $this->assertStringContainsString("['删除图标', 'fa-thumb-tack', 'remove']", $script);
        // 应用中心为内置入口：右键不显示删除项，removeDesktopEntry 对其拦截兜底
        $this->assertStringContainsString("context.entry.id !== 'webos-app-center'", $script);
        $this->assertStringContainsString("if (id === 'webos-app-center') {", $script);
        $this->assertStringContainsString('应用中心为内置入口，不允许删除', $script);
        $this->assertStringContainsString("['卸载应用', 'fa-times-circle', 'uninstall', 'danger']", $script);
        $this->assertStringContainsString('data-desktop-action="', $script);
        $this->assertStringContainsString('openUninstallDialog(context.application)', $script);
        $this->assertStringContainsString('closeDesktopContextMenu();', $script);
        $this->assertStringContainsString('.desktop-context-menu {', $stylesheet);
        $this->assertStringContainsString('.desktop-context-item {', $stylesheet);
        $this->assertStringContainsString('function hydrateDesktopAppIds()', $script);
        $this->assertStringContainsString("app_id: item.app_id || ''", $script);
        $this->assertStringContainsString('findEntry(id) || findDesktopItem(id)', $script);
    }

    public function test_clicking_blank_desktop_closes_open_panels(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');

        $this->assertIsString($script);
        $this->assertStringContainsString('function isPanelToggle(target)', $script);
        $this->assertStringContainsString('button.contains(target)', $script);
        $this->assertStringContainsString("if (!event.target.closest('.webos-panel') && !isPanelToggle(event.target)) {", $script);
    }

    public function test_start_menu_pin_uses_application_name(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');

        $this->assertIsString($script);
        $this->assertStringContainsString('function findStartItem(id)', $script);
        $this->assertStringContainsString('state.startItems = entries;', $script);
        $this->assertStringContainsString('function desktopEntryFromStart(startItem)', $script);
        $this->assertStringContainsString("entry.title = startItem.start_title;", $script);
        $this->assertStringContainsString('toggleDesktopEntry(pinButton.dataset.pinId, findStartItem(pinButton.dataset.pinId))', $script);
    }

    public function test_installed_app_operations_reuse_system_app_apis(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');

        $this->assertIsString($script);
        $this->assertStringContainsString("'/api/admin/apps/upload'", $script);
        $this->assertStringContainsString("'/backups'", $script);
        $this->assertStringContainsString("'/docs'", $script);
        // 文档正文接口与传统后台一致，path 作为查询参数由 jQuery data 传递，而非手工拼接 URL
        $this->assertStringContainsString("'/docs/content',", $script);
        $this->assertStringContainsString('data: { path: path },', $script);
        $this->assertStringContainsString("'/config'", $script);
        $this->assertStringContainsString("'/export'", $script);
        $this->assertStringContainsString("'/uninstall'", $script);
        $this->assertStringContainsString("'/enable'", $script);
        $this->assertStringContainsString("'/disable'", $script);
        $this->assertStringContainsString('data-app-action="', $script);
        $this->assertStringContainsString('data-app-action="manual-upgrade" data-app-id="', $script);
        $this->assertStringContainsString('>手动升级</button>', $script);
        $this->assertStringContainsString("['export', 'fa-download', '导出']", $script);
        $this->assertStringContainsString("['manage-entry', 'fa-th', '管理入口']", $script);
        $this->assertStringContainsString("if (action === 'manage-entry')", $script);
        $this->assertStringContainsString("['backup', 'fa-archive', '备份']", $script);
        $this->assertStringContainsString("['docs', 'fa-book', '文档']", $script);
        $this->assertStringContainsString("['settings', 'fa-cog', '设置']", $script);
        $this->assertStringContainsString("['uninstall', 'fa-trash-o', '卸载', 'danger']", $script);
        $this->assertStringContainsString('请先禁用应用', $script);
    }

    public function test_switching_market_category_keeps_only_the_sentinel_hint(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');

        $this->assertIsString($script);
        $this->assertStringContainsString('function switchMarketCategory(code, content)', $script);
        // 切换分类时清空旧卡片，加载提示统一由哨兵“正在读取应用市场…”承担，不再重复提示
        $this->assertStringNotContainsString('正在加载该分类应用', $script);
        $this->assertStringContainsString("grid.innerHTML = '';", $script);
        $this->assertStringContainsString('sentinel.hidden = false;', $script);
        $this->assertStringContainsString('正在读取应用市场…', $script);
    }

    public function test_market_first_load_shows_only_sentinel_hint(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');

        $this->assertIsString($script);
        // 首次加载时列表区不再放大字号占位提示，避免与底部哨兵同时出现两个“正在读取应用市场…”
        $this->assertSame(1, substr_count($script, '正在读取应用市场…'));
        $this->assertStringContainsString('<div class="app-grid market-app-grid" data-market-grid></div>', $script);
        $this->assertStringNotContainsString('data-market-grid><div class="panel-empty"', $script);
    }

    public function test_window_menu_supports_the_four_open_types(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');

        $this->assertIsString($script);
        $this->assertIsString($stylesheet);

        // _blank：新标签页打开，除站内路径外额外放行 http(s) 外链以便跳转第三方系统
        $this->assertStringContainsString('function externalPath(path)', $script);
        $this->assertStringContainsString('function openablePath(entry)', $script);
        $this->assertStringContainsString("window.open(entry.path, '_blank', 'noopener');", $script);
        // 侧栏树与入口扁平化都要放行外链，否则 _blank 的第三方链接在左侧菜单里根本不显示
        $this->assertStringContainsString('if (openablePath(item)) { level.push(windowNavLeaf(item)); }', $script);

        // _layer：layui 弹层承载网页，参数与后台“弹窗网页”保持一致
        $this->assertStringContainsString('function openLayerWindow(entry)', $script);
        $this->assertStringContainsString("area: ['80%', '80%']", $script);
        $this->assertStringContainsString('maxmin: true', $script);

        // _component：AJAX 取 HTML 片段注入窗口内容区容器，并重建 script 节点使其执行
        $this->assertStringContainsString('function fetchText(url)', $script);
        $this->assertStringContainsString('function loadComponentPage(host, path, token)', $script);
        $this->assertStringContainsString('function runFragmentScripts(host)', $script);
        $this->assertStringContainsString('function initLayuiComponents()', $script);
        $this->assertStringContainsString('[data-window-page-host]', $script);
        $this->assertStringContainsString('.window-page-host', $stylesheet);

        // 四种打开方式统一由 openMenuByType 分流，_blank / _layer 不切换窗口内容、不改侧栏高亮
        $this->assertStringContainsString('function openMenuByType(entry)', $script);
        $this->assertStringContainsString('if (entry && !openMenuByType(entry) && currentWindow) {', $script);
        $this->assertStringContainsString('function renderWindowPage(windowState, entry)', $script);
    }

    public function test_component_page_reuses_the_legacy_admin_asset_environment(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2) . '/Views/Admin/desktop/index.blade.php');
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');

        $this->assertIsString($view);
        $this->assertIsString($script);

        // 路由模式片段与桌面页共享同一文档，pear.js 提供 layui base/extend 配置，
        // 缺失时片段内 layui.use(['toast'|'button'|'dtree'|'echarts'…]) 会去 layui.js 同级目录解析并 404
        $this->assertStringContainsString("asset('CmsProUi/component/pear/pear.js')", $view);
        $this->assertStringContainsString("asset('CmsProUi/component/layui/layui.js')", $view);

        // 预热片段高频依赖且无 DOM 副作用的模块；框架级模块不预热，避免在 WebOS 中初始化后台框架
        $this->assertStringContainsString(
            "layui.use(['element', 'form', 'jquery', 'layer', 'toast', 'button', 'popup'], function () {});",
            $view
        );
        $this->assertSame(1, substr_count($view, 'layui.use(['));

        // 片段脚本必须按文档顺序执行，与后台 jQuery .html() 语义一致，
        // 否则「外链插件 + 紧随其后的内联调用」这种常见写法会因动态 script 默认 async 而乱序报错
        $this->assertStringContainsString('function runScriptNode(original)', $script);
        $this->assertStringContainsString("script.addEventListener('load', resolve);", $script);
        $this->assertStringContainsString('runFragmentScripts(host).then(initLayuiComponents);', $script);
        $this->assertStringNotContainsString('function evalInlineScripts(host)', $script);

        // 与传统后台一致：jQuery 由 layui 内部持有，不向全局暴露 $
        $this->assertStringNotContainsString('window.jQuery =', $view);
        $this->assertStringNotContainsString('window.$ =', $view);
    }

    public function test_webos_assets_use_the_published_file_timestamp_to_bust_browser_cache(): void
    {
        $controller = file_get_contents(dirname(__DIR__, 2) . '/Controllers/Admin/WebosController.php');
        $view = file_get_contents(dirname(__DIR__, 2) . '/Views/Admin/desktop/index.blade.php');

        $this->assertIsString($controller);
        $this->assertIsString($view);
        $this->assertStringContainsString("'webosAssetVersion' => \$this->assetVersion()", $controller);
        $this->assertStringContainsString("asset('apps/cmspro.webos/css/webos.css') }}?v={{ \$webosAssetVersion }}", $view);
        $this->assertStringContainsString("asset('apps/cmspro.webos/js/webos.js') }}?v={{ \$webosAssetVersion }}", $view);
    }

    public function test_default_window_entry_skips_blank_and_layer_menus(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');

        $this->assertIsString($script);
        // 打开应用或系统菜单文件夹时的默认入口需跳过不占用窗口内容区的 _blank 与 _layer
        $this->assertStringContainsString('function defaultEntryOf(entries)', $script);
        $this->assertStringContainsString('var representative = defaultEntryOf(entries);', $script);
        $this->assertStringNotContainsString('var representative = entries[0];', $script);
        $this->assertStringContainsString('openEntry(defaultEntryOf(appMenus));', $script);
    }

    public function test_market_detail_shots_use_single_row_track_and_lightbox(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');

        $this->assertIsString($script);
        $this->assertIsString($stylesheet);

        // 截图区改为单行横向轨道，超出部分由左右箭头切换
        $this->assertStringContainsString('data-shots-track', $script);
        $this->assertStringContainsString('data-shots-prev', $script);
        $this->assertStringContainsString('data-shots-next', $script);
        $this->assertStringContainsString('function scrollMarketShots(', $script);
        $this->assertStringContainsString('function updateMarketShotsNav(', $script);

        // 点击截图打开全屏预览层，预览中可左右切换，不再新标签页打开原图
        $this->assertStringContainsString('data-shot-url', $script);
        $this->assertStringContainsString('function openShotViewer(', $script);
        $this->assertStringContainsString('function stepShotViewer(', $script);
        $this->assertStringContainsString('function closeShotViewer(', $script);
        $this->assertStringContainsString('data-shot-viewer-close', $script);
        $this->assertStringContainsString('data-shot-viewer-image', $script);
        $this->assertStringNotContainsString('class="market-detail-shot" href=', $script);

        // 轨道单行不换行；预览层层级高于窗口与弹窗、低于提示条
        $this->assertStringContainsString('.market-shots {', $stylesheet);
        $this->assertStringContainsString('.shot-viewer {', $stylesheet);
        $this->assertStringContainsString('z-index: 6500;', $stylesheet);
        $this->assertMatchesRegularExpression(
            '/\.market-detail-shots\s*\{[^}]*display:\s*flex[^}]*overflow-x:\s*auto/s',
            $stylesheet
        );
    }

    public function test_shot_viewer_image_uses_ninety_percent_viewport_box(): void
    {
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');

        $this->assertIsString($stylesheet);

        // 大图可用区域为视口的 90% × 90%：预览层内边距留出 5vh / 5vw，舞台按 90vw × 90vh 封顶
        $this->assertMatchesRegularExpression('/\.shot-viewer\s*\{[^}]*padding:\s*5vh 5vw;/s', $stylesheet);
        $this->assertMatchesRegularExpression(
            '/\.shot-viewer-stage\s*\{[^}]*max-width:\s*90vw;[^}]*max-height:\s*90vh;/s',
            $stylesheet
        );
        // 图片在 90% 框内等比缩放，预留 figcaption 的页码高度，避免拉伸变形
        $this->assertMatchesRegularExpression(
            '/\.shot-viewer-stage img\s*\{[^}]*max-height:\s*calc\(90vh - 32px\);/s',
            $stylesheet
        );

        // 左右切换按钮脱离文档流叠加在两侧，否则会挤占舞台宽度使 90% 无法真正生效
        $this->assertMatchesRegularExpression('/\.shot-viewer-nav\s*\{[^}]*position:\s*absolute;/s', $stylesheet);
        $this->assertMatchesRegularExpression(
            '/\.shot-viewer-nav\[data-shot-viewer-prev\]\s*\{[^}]*left:\s*12px;/s',
            $stylesheet
        );
        $this->assertMatchesRegularExpression(
            '/\.shot-viewer-nav\[data-shot-viewer-next\]\s*\{[^}]*right:\s*12px;/s',
            $stylesheet
        );

        // 旧的保守上限已移除
        $this->assertStringNotContainsString('min(1080px, 88vw)', $stylesheet);
        $this->assertStringNotContainsString('calc(100vh - 150px)', $stylesheet);
    }

    public function test_app_center_sidebar_marks_updates_tab_with_available_count(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');

        $this->assertIsString($script);
        $this->assertIsString($stylesheet);

        // 角标只挂在侧栏「应用更新」项，数量为 0 或尚未检查时不渲染，超过 99 折叠为 99+
        $this->assertStringContainsString('function updateBadgeMarkup()', $script);
        $this->assertStringContainsString('<em class="window-nav-badge">', $script);
        $this->assertStringContainsString("(item.id === 'updates' ? updateBadgeMarkup() : '')", $script);
        $this->assertStringContainsString("count > 99 ? '99+' : count", $script);
        $this->assertStringContainsString('updateCount: null,', $script);

        // 应用中心窗口新建时静默拉取数量，复用系统 lazy 接口（服务端 24h 缓存节流）
        $this->assertStringContainsString('function loadUpdateCount()', $script);
        $this->assertStringContainsString("api('/api/admin/apps/check-updates?lazy=1', { method: 'POST' })", $script);
        $this->assertMatchesRegularExpression(
            '/renderAppCenter\(state\.appCenterTab\);\s*\n\s*loadUpdateCount\(\);/',
            $script
        );

        // 进入「应用更新」Tab 与单个应用升级成功后同步角标，避免数量与列表不一致
        $this->assertStringContainsString('function syncUpdateBadge()', $script);
        $this->assertStringContainsString('state.updateCount = state.updateApps.length;', $script);
        $this->assertStringContainsString('function dropUpdatedApp(appId)', $script);

        // 更新列表等无图标数据的记录：合并目录应用图标（icon_url 全局规则 icon.svg→icon.png，icon 为 manifest 兜底）
        $this->assertStringContainsString('if (!app.icon_url && !app.icon) {' . "\n" . '            var catalogApp = findCatalogApp(app.app_id);', $script);
        $this->assertStringContainsString('app = Object.assign({}, catalogApp, app);', $script);

        // 底层窗口 iframe 点击聚焦：未聚焦窗口 iframe 覆盖透明遮罩，点击遮罩冒泡聚焦窗口后移除
        $this->assertStringContainsString('function syncWindowShields()', $script);
        $this->assertStringContainsString('shield.className = \'window-frame-shield\';', $script);
        $this->assertStringContainsString('syncWindowShields();' . "\n" . '        renderTaskbarWindows();', $script);

        // OS 设置窗口宽度固定 1000px（小屏收窄避免溢出），高度仍按默认比例
        $this->assertStringContainsString("if (key === 'webos-settings') {" . "\n" . '            return {' . "\n" . '                width: Math.max(MIN_WINDOW_WIDTH, Math.min(1000, Math.round(rect.width) - 40)),', $script);

        // 个人设置窗口宽度固定 680px；修改密码弹窗宽度固定 480px
        $this->assertStringContainsString("if (entry.path === '/admin/account') {" . "\n" . '            size.width = Math.max(MIN_WINDOW_WIDTH, Math.min(680, Math.round(layerRect.width) - 40));', $script);
        $this->assertStringContainsString("width: '480px',", $script);

        // 进入桌面时检查 WebOS 自身新版本：确认后打开应用中心并自动触发升级
        $this->assertStringContainsString('function checkWebosSelfUpdate()', $script);
        $this->assertStringContainsString("openEntry(applicationCenterEntry());" . "\n" . "                    // 打开应用中心后自动进入 WebOS 自身的升级流程（启用拦截 → 版本选择弹窗）" . "\n" . "                    upgradeApp('cmspro.webos');", $script);
        $this->assertStringContainsString('checkWebosSelfUpdate();', $script);
        // 桌面就绪后延迟检查系统框架新版本（Versionmgr），错开 WebOS 自身的更新提示弹窗
        $this->assertStringContainsString('setTimeout(checkFrameworkUpdate, 3000);', $script);

        // 桌面「应用中心」图标右上角的可更新数量角标：renderDesktop 按检查结果渲染，syncUpdateBadge 同步
        $this->assertStringContainsString("item.id === 'webos-app-center' && state.updateApps.length", $script);
        $this->assertStringContainsString("'<span class=\"desktop-update-badge\">' + state.updateApps.length + '</span>'", $script);
        $this->assertStringContainsString('rerenderWindowNav(state.windows.get(windowKey(applicationCenterEntry())));' . "\n" . '        // 桌面「应用中心」图标右上角的可更新数量角标随检查结果同步' . "\n" . '        renderDesktop();', $script);

        // 升级交互复刻传统后台：layui 弹窗选版本 + 启用拦截 + 逐级自动升级，
        // 成功后清除待升级记录、刷新目录并询问启用
        $this->assertStringContainsString('function upgradeApp(appId)', $script);
        $this->assertStringContainsString('function openUpgradeDialog(appId, availableVersions)', $script);
        $this->assertStringContainsString('function doUpgrade(appId, targetVersion)', $script);
        $this->assertStringContainsString('function startAutoUpgrade(appId, versions)', $script);
        $this->assertStringContainsString('btn: [\'我已备份，确认升级\', \'先去备份\', \'取消\'],', $script);
        $this->assertStringContainsString('dropUpdatedApp(appId);', $script);
        $this->assertStringContainsString('promptEnableAfterUpgrade(appId);', $script);

        // 升级链路停留在当前 Tab：reloadAppCenterCurrent 按当前 Tab 渲染（不切到已安装），
        // 更新 Tab 用本地数据渲染（updateChecked），升级成功 dropUpdatedApp 后列表即时移除该项
        $this->assertStringContainsString('function reloadAppCenterCurrent()', $script);
        $this->assertStringContainsString('updateChecked: false,', $script);
        $this->assertStringContainsString('state.updateChecked = true;', $script);
        $this->assertStringContainsString("if (state.updateChecked) {", $script);
        $this->assertStringContainsString('promptEnableApp(appId, app.name, \'升级成功，当前版本 v\' + app.version, true);', $script);

        // 桌面图标右键重命名：仅修改工作区桌面项 title，渲染与保存均走桌面工作区数据
        $this->assertStringContainsString("items.push(['重命名', 'fa-pencil', 'rename']);", $script);
        $this->assertStringContainsString('function renameDesktopIcon(iconId)', $script);
        $this->assertStringContainsString('item.title = name;', $script);
        $this->assertStringContainsString('renderDesktop();' . "\n" . '            saveWorkspace(false);', $script);

        // 角标绝对定位在按钮右上角，定位上下文只给 updates 按钮，不影响菜单树按钮
        $this->assertMatchesRegularExpression(
            '/\.window-nav-button\[data-special-tab="updates"\]\s*\{[^}]*position:\s*relative;/s',
            $stylesheet
        );
        $this->assertMatchesRegularExpression(
            '/\.window-nav-badge\s*\{[^}]*position:\s*absolute;[^}]*background:\s*#ef5a62;/s',
            $stylesheet
        );
    }

    public function test_window_brand_icon_uses_white_background_for_image_icons(): void
    {
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');

        $this->assertIsString($stylesheet);
        // 窗口标题栏图标：SVG/PNG 图片图标改用白色底，字体图标仍保持原有彩色底
        $this->assertMatchesRegularExpression(
            '/\.window-brand-icon img\s*\{[^}]*background:\s*#fff/s',
            $stylesheet
        );
        // 系统窗口 logo 保持原有透明底，不被白底规则覆盖
        $this->assertMatchesRegularExpression(
            '/\.window-brand-icon\.is-system img\s*\{[^}]*background:\s*transparent/s',
            $stylesheet
        );
    }

    public function test_builtin_windows_ignore_window_size_preference(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');

        $this->assertIsString($script);

        // “窗口默认尺寸”只作用于应用窗口；WebOS 内置窗口固定使用出厂默认（官网动态固定 600×500）
        $this->assertStringContainsString(
            "var BUILTIN_WINDOW_KEYS = ['webos-app-center', 'webos-settings', 'webos-official-news'];",
            $script
        );
        $this->assertMatchesRegularExpression(
            '/function defaultWindowSize\(key\)\s*\{[\s\S]{0,220}?BUILTIN_WINDOW_KEYS\.indexOf\(key\)/',
            $script
        );
        // 设置界面说明同步为仅作用于应用窗口
        $this->assertStringContainsString('仅作用于应用窗口，按可用桌面区域的百分比显示', $script);
    }

    public function test_window_sidebar_renders_collapsible_multi_level_menu(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');

        $this->assertIsString($script);
        $this->assertIsString($stylesheet);

        // 侧栏按原始菜单树层级渲染，分组节点可展开收起，不再平铺叶子菜单
        $this->assertStringContainsString('function windowNavTree(entry)', $script);
        $this->assertStringContainsString('var windowNavExpanded = new Set();', $script);
        $this->assertStringContainsString('data-window-nav-branch=', $script);
        $this->assertStringContainsString('function windowNavNodesMarkup(nodes, activeId, depth)', $script);
        // 打开页面时自动展开当前菜单所在的分组链路
        $this->assertStringContainsString('function revealActiveNavBranch(nodes, activeKey)', $script);
        // 分组互斥展开（手风琴），与后台菜单行为一致
        $this->assertStringContainsString('function toggleWindowNavBranch(button)', $script);
        // 切换菜单后只重绘侧栏，避免 iframe 重新加载
        $this->assertStringContainsString('function rerenderWindowNav(windowState, reveal)', $script);
        $this->assertMatchesRegularExpression(
            '/\[data-window-nav-branch\][\s\S]{0,120}?toggleWindowNavBranch\(/',
            $script
        );

        // 子级默认隐藏，展开时用 class 控制显示（避开 flex 覆盖 hidden 的问题）
        $this->assertMatchesRegularExpression(
            '/\.window-nav-children\s*\{[^}]*display:\s*none/s',
            $stylesheet
        );
        $this->assertMatchesRegularExpression(
            '/\.window-nav-group\.is-open\s*>\s*\.window-nav-children\s*\{[^}]*display:\s*block/s',
            $stylesheet
        );
        $this->assertStringContainsString('.window-nav-toggle', $stylesheet);
    }

    public function test_workspace_api_rejects_external_paths(): void
    {
        $this->actingAdmin();

        $response = $this->putJson('/admin/cmspro/webos/api/workspace', [
            'desktop_items' => [[
                'id' => 'unsafe',
                'title' => '外部站点',
                'path' => 'https://example.com',
                'icon' => 'fa fa-link',
                'x' => 0,
                'y' => 0,
            ]],
            'preferences' => [],
        ])->assertUnprocessable()
            ->assertJsonPath('code', 40201);

        $this->assertArrayHasKey('desktop_items.0.path', $response->json('data'));
    }

    /** 导出：对齐传统后台 exportApp，用 layer 确认 + XHR blob 下载，并识别 JSON 错误体 */
    public function test_app_export_follows_the_legacy_admin_flow(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');

        $this->assertIsString($script);
        $this->assertStringContainsString("layer.confirm('确定要导出「'", $script);
        $this->assertStringContainsString("xhr.responseType = 'blob';", $script);
        $this->assertStringContainsString("xhr.setRequestHeader('X-CSRF-TOKEN', runtime.csrfToken || '');", $script);
        $this->assertStringContainsString("'/export'", $script);
        $this->assertStringContainsString("xhr.getResponseHeader('Content-Disposition')", $script);
        $this->assertStringContainsString("layer.msg('导出成功', { icon: 1 });", $script);
        // 后端出错时返回 JSON 而非文件流，需读出真实错误而不是把错误体当 zip 下载
        $this->assertStringContainsString('readAsText', $script);
        $this->assertStringContainsString('indexOf(\'json\')', $script);
    }

    /** 手动升级：照抄后台 openUploadDialog，去掉自动弹文件框，改点选/拖拽 + 回显 + 后端 message + 询问启用 */
    public function test_manual_upgrade_reuses_the_legacy_upload_dialog(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');

        $this->assertIsString($script);
        $this->assertStringContainsString("openUploadDialog('手动升级「'", $script);
        $this->assertStringContainsString("'/api/admin/apps/upload'", $script);
        $this->assertStringContainsString("{ app_id: app.app_id }", $script);
        $this->assertStringContainsString('app-upload-zone', $script);
        $this->assertStringContainsString('拖拽 .zip 文件到此处', $script);
        $this->assertStringContainsString('仅支持 .zip 格式的应用包', $script);
        $this->assertStringContainsString("layer.msg('请选择 .zip 格式的应用包'", $script);
        $this->assertStringContainsString('processData: false', $script);
        // 成功提示使用后端返回的 message（含版本号），不再硬编码
        $this->assertStringContainsString("return res.message || '操作成功';", $script);
        $this->assertStringNotContainsString('升级包已上传，应用将完成升级', $script);
        $this->assertStringContainsString('promptEnableAfterUpgrade', $script);
        $this->assertStringContainsString('是否现在启用当前应用？', $script);

        // WebOS 自身升级特殊优化：禁用拦截附桌面可用说明；启用成功后引导刷新加载新版桌面（其它应用保持通用提示）
        $this->assertStringContainsString("if (appId === 'cmspro.webos') {" . "\n" . '                disableNotice +=', $script);
        $this->assertStringContainsString('升级期间当前桌面与已打开的窗口可继续正常操作，不受影响', $script);
        $this->assertStringContainsString('WebOS 管理桌面已升级并启用至新版本，刷新页面后加载新版桌面。', $script);
        $this->assertStringContainsString("window.location.reload();", $script);

        // 安装流程依赖检测对齐传统后台：失败走 showErrorDialog 长弹窗（含依赖应用市场引导），不再 toast 一闪而过
        $this->assertStringContainsString('showErrorDialog(error.message);' . "\n" . '        }).finally(function () {' . "\n" . '            elements.confirmInstall.disabled = false;', $script);
        // 打开对话框不再自动触发系统文件选择框
        $this->assertStringNotContainsString('elements.packageInput.click()', $script);
        $this->assertStringContainsString('.app-upload-zone {', $stylesheet);
        $this->assertStringContainsString('.app-upload-file-info {', $stylesheet);
    }

    /** 备份：照抄后台三步分卷备份与三步恢复，含分卷设置、进度条、删除与下载 */
    public function test_app_backup_uses_three_step_volume_flow(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');

        $this->assertIsString($script);
        $this->assertStringContainsString('function doBackupWithProgress(', $script);
        $this->assertStringContainsString("'/backups/prepare'", $script);
        $this->assertStringContainsString("'/backups/table'", $script);
        $this->assertStringContainsString("'/backups/finish'", $script);
        $this->assertStringContainsString('layui-progress-bar', $script);
        $this->assertStringContainsString('分卷大小（MB）', $script);
        $this->assertStringContainsString('备份成功！文件大小：', $script);
        $this->assertStringContainsString('条备份记录', $script);
        // 恢复：三步 + 自动重试
        $this->assertStringContainsString('function doRestoreWithProgress(', $script);
        $this->assertStringContainsString("'/backups/restore-prepare'", $script);
        $this->assertStringContainsString("'/backups/restore-table'", $script);
        $this->assertStringContainsString("'/backups/restore-finish'", $script);
        $this->assertStringContainsString('maxRetries = 3', $script);
        $this->assertStringContainsString('此操作不可逆！', $script);
        // 删除与下载
        $this->assertStringContainsString("type: 'DELETE'", $script);
        $this->assertStringContainsString("'/download'", $script);
        // 不再使用一次性备份接口
        $this->assertStringNotContainsString('body: { volume_size: 2 }', $script);
    }

    /** 备份弹窗与后台一致：顶部工具栏（左「共 N 条 + 导入恢复」/ 右「立即备份」），列表在下 */
    public function test_backup_dialog_toolbar_matches_admin(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');

        $this->assertIsString($script);
        // 顶部工具栏：共 N 条记录 + 导入恢复按钮（左侧）与立即备份（右侧）
        $this->assertStringContainsString('dlgBtnImportBackup', $script);
        $this->assertStringContainsString('<i class="fa fa-upload"></i> 导入恢复</button>', $script);
        // 渲染顺序：工具栏在前（顶部），列表在下
        $this->assertStringContainsString("backupToolbarMarkup(backups.length, app.app_id, appDisplayName(app))", $script);
        $this->assertStringContainsString('renderBackupList(backups)', $script);
        // 导入弹窗（上传文件 + 从服务器选择两个 Tab）
        $this->assertStringContainsString('function openImportDialog(', $script);
        $this->assertStringContainsString('dlgImportZone', $script);
        $this->assertStringContainsString('/backups/local-files', $script);
        $this->assertStringContainsString('/backups/upload-file', $script);
        $this->assertStringContainsString('local_file_name: res.data.file_name', $script);
        // 从服务器本地文件恢复入口
        $this->assertStringContainsString('function doLocalRestore(', $script);
        // 备份管理弹层宽高 90%
        $this->assertStringContainsString("title: '「' + appDisplayName(app) + '」备份管理',\n            area: ['90%', '90%']", $script);
    }

    /** 文档：照抄后台三栏预览（文件树 + 正文 + TOC），含 hljs 高亮与滚动联动 */
    public function test_app_docs_use_three_pane_viewer_with_toc(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');
        $view = file_get_contents(dirname(__DIR__, 2) . '/Views/Admin/desktop/index.blade.php');

        $this->assertIsString($script);
        $this->assertStringContainsString('function openAppDocs(', $script);
        $this->assertStringContainsString('doc-viewer-toc', $script);
        $this->assertStringContainsString('function renderDocViewerToc(', $script);
        $this->assertStringContainsString('doc-toc-active', $script);
        $this->assertStringContainsString('hljs.highlightElement(block);', $script);
        $this->assertStringContainsString("scrollIntoView({ behavior: 'smooth', block: 'start' })", $script);
        $this->assertStringContainsString('doc-markdown-body', $script);
        $this->assertStringContainsString("'/docs/download?path='", $script);
        $this->assertStringContainsString('.doc-markdown-body h2 {', $stylesheet);
        $this->assertStringContainsString('.doc-toc-active {', $stylesheet);
        $this->assertStringContainsString('highlight.js/highlight.min.js', $view);
        $this->assertStringContainsString('highlight.js/styles/github-dark.min.css', $view);
        $this->assertStringContainsString('highlight.js/languages/php.min.js', $view);
        // 文档预览弹层宽高 90%；左侧文件名超出一行省略号截断不换行
        $this->assertStringContainsString("' - 文档预览',\n            area: ['90%', '90%']", $script);
        $this->assertStringContainsString('.doc-tree-dir span,', $stylesheet);
        $this->assertStringContainsString('text-overflow: ellipsis;', $stylesheet);
    }

    /** 头像：任务栏与账号菜单始终渲染 img，未上传头像时兜底系统默认头像图 */
    public function test_admin_avatar_falls_back_to_system_default_image(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2) . '/Views/Admin/desktop/index.blade.php');

        $this->assertIsString($view);
        // 与后台 layouts/admin.blade.php 一致：avatar 为空时用 Admin/images/avatar.png
        $this->assertSame(
            2,
            substr_count($view, '{{ $admin->avatar ?: asset(\'Admin/images/avatar.png\') }}')
        );
        // 不再用字体图标占位，否则未上传头像时看不到管理员头像
        $this->assertStringNotContainsString('@if($admin->avatar)', $view);
        $this->assertStringNotContainsString('<i class="fa fa-user" aria-hidden="true"></i>', $view);
    }

    /** 初始数据：超管首次进入桌面只固定「应用中心」；非超管不预置（应用中心仅超管可用） */
    public function test_initial_desktop_pins_only_application_center(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');

        $this->assertIsString($script);
        $this->assertStringContainsString('var selected = isSuperAdmin ? [applicationCenterEntry()] : [];', $script);
        $this->assertStringContainsString('if (isSuperAdmin && !state.workspace.taskbar_items.length) {', $script);
        $this->assertStringNotContainsString("var preferred = ['文件', '内容', '系统'];", $script);
        $this->assertStringNotContainsString('selected = selected.slice(0, 3);', $script);
    }

    /** 入口管理：应用菜单要真正收集进应用节点，否则展开后是空的 */
    public function test_entry_tree_collects_application_menus(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');

        $this->assertIsString($script);
        $this->assertStringContainsString('function appNodeOf(appId)', $script);
        $this->assertStringContainsString('function collectAppMenu(parent, item, children, nested)', $script);
        $this->assertStringContainsString('collectAppMenu(appNodeOf(appId), item, children, !fresh);', $script);
        $this->assertStringContainsString('var fresh = !appIndex.has(appId);', $script);
        // 旧实现只递归不落位，应用节点 children 恒为空，展开后显示「0 项」
        $this->assertStringNotContainsString('walk(children, appId);', $script);
    }

    /** 入口管理：展开/收起应用节点后滚动位置要保持，不能跳回顶部 */
    public function test_entry_tree_toggles_keep_scroll_position(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');

        $this->assertIsString($script);
        // 点击展开分支时先记住滚动容器位置，重渲染后恢复
        $this->assertStringContainsString('var entryScroll = contentEl ? contentEl.scrollTop : 0;', $script);
        $this->assertStringContainsString('contentEl.scrollTop = entryScroll;', $script);
    }

    /** 入口管理：树的应用节点与桌面入口行要显示应用图标（icon_url 优先，manifest 兜底），纯菜单保持字体图标 */
    public function test_entry_manager_shows_application_icons(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');

        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        // 树的应用节点存真实应用引用并复用 applicationIconMarkup（icon_url 优先，应用 icon 字段兜底）
        $this->assertStringContainsString('application ? application.name : appId,', $script);
        $this->assertStringContainsString("applicationIconMarkup(node.application, 'entry-tree-app-icon')", $script);
        // is-app-icon 已由 applicationIconMarkup 源头声明（img 分支统一携带），渲染位不再外层拼接
        $this->assertStringContainsString("className + ' is-app-icon\"><img data-app-icon-primary", $script);
        $this->assertStringContainsString("entryIconMarkup(findEntry(item.id) || item, 'entry-row-icon')", $script);
        $this->assertStringContainsString('type: \'leaf\',', $script);
        $this->assertStringContainsString("entryIconMarkup(node, 'entry-row-icon')", $script);
        // 树内 toggle 行的图标尺寸规则
        $this->assertStringContainsString('.entry-tree-toggle .start-app-item-icon', $stylesheet);
    }

    /** 入口管理的应用图标要限制在 38px 容器内，img 不能按原始尺寸撑爆行高 */
    public function test_entry_manager_icons_are_constrained(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');

        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        // 树的应用节点也走 is-app-icon 图片降级链（is-app-icon 由 applicationIconMarkup 源头声明）
        $this->assertStringContainsString("applicationIconMarkup(node.application, 'entry-tree-app-icon')", $script);
        $this->assertStringContainsString("className + ' is-app-icon\"><img data-app-icon-primary", $script);
        // img 尺寸约束覆盖入口管理两类容器
        $this->assertStringContainsString('.entry-row .entry-row-icon.is-app-icon img', $stylesheet);
        $this->assertStringContainsString('.entry-tree-toggle .entry-tree-app-icon.is-app-icon img', $stylesheet);
        // 容器自身要隐藏溢出并居中
        $this->assertStringContainsString('.entry-row .entry-row-icon.is-app-icon,', $stylesheet);
        $this->assertStringContainsString('overflow: hidden', $stylesheet);
    }

    /** 桌面入口非应用项图标蓝底白字；可用菜单分支节点用菜单设置的图标而非固定文件夹 */
    public function test_entry_manager_plain_icons_and_branch_icons(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');

        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        // 分支节点使用菜单设置的图标，兜底 fa fa-folder（三处 buildEntryTree 分支创建）
        $this->assertStringContainsString("icon: safeIcon(item.icon || 'fa fa-folder')", $script);
        // 桌面入口行（非 is-tree-leaf）非应用项图标蓝底白字
        $this->assertStringContainsString('.entry-row:not(.is-tree-leaf) .entry-row-icon:not(.is-app-icon)', $stylesheet);
        $this->assertStringContainsString('background: #178fe5', $stylesheet);
        $this->assertStringContainsString('color: #fff', $stylesheet);
    }

    /** 企业级动效：窗口拖拽与缩放需按帧合并，并完整尊重系统减少动态效果偏好 */
    public function test_window_gestures_and_motion_are_optimized(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');

        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('function createFrameScheduler(callback)', $script);
        $this->assertStringContainsString('window.requestAnimationFrame', $script);
        $this->assertStringContainsString("element.classList.add('is-window-gesturing')", $script);
        $this->assertStringContainsString("titlebar.addEventListener('pointercancel', end)", $script);
        $this->assertStringContainsString("handle.addEventListener('pointercancel', end)", $script);
        $this->assertStringContainsString('--motion-fast: 120ms;', $stylesheet);
        $this->assertStringContainsString('--motion-standard: 180ms;', $stylesheet);
        $this->assertStringContainsString('--motion-emphasized: 240ms;', $stylesheet);
        $this->assertStringContainsString('@keyframes webos-window-enter', $stylesheet);
        $this->assertStringContainsString('@keyframes webos-panel-enter', $stylesheet);
        $this->assertStringContainsString('outline: 2px solid var(--webos-focus);', $stylesheet);
        $this->assertStringContainsString('animation-duration: 0.001ms !important;', $stylesheet);
        $this->assertStringContainsString('transition-duration: 0.001ms !important;', $stylesheet);
        $this->assertStringNotContainsString('transition: all', $stylesheet);
    }

    /** 已安装应用：操作层级清晰、长文本可查看，并防止状态切换重复提交 */
    public function test_installed_application_rows_have_enterprise_interaction_feedback(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');

        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('>手动升级</button>', $script);
        $this->assertStringContainsString("items.push(['export', 'fa-download', '导出']", $script);
        $this->assertStringContainsString('data-local-source="installed" title="\'', $script);
        $this->assertStringContainsString('<span title="\'', $script);
        $this->assertStringContainsString('escapeHtml(description)', $script);
        $this->assertStringContainsString('function setStatusToggleBusy(button, busy)', $script);
        $this->assertStringContainsString("button.setAttribute('aria-busy', busy ? 'true' : 'false')", $script);
        $this->assertStringContainsString('toggleAppStatus(statusToggle.dataset.toggleAppStatus', $script);
        $this->assertStringContainsString('.status-switch[aria-busy="true"]', $stylesheet);
        $this->assertStringContainsString('decoding="async"', $script);
    }

    /** 应用市场：切换分类或搜索时取消旧请求，禁止迟到响应覆盖新结果 */
    public function test_market_requests_ignore_stale_responses(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');

        $this->assertIsString($script);
        $this->assertStringContainsString('var marketRequestSequence = 0;', $script);
        $this->assertStringContainsString('var marketRequestController = null;', $script);
        $this->assertStringContainsString('function cancelMarketRequest()', $script);
        $this->assertStringContainsString("if (state.appCenterTab !== 'market')", $script);
        $this->assertStringContainsString('function resetMarketPager(category, keyword)', $script);
        $this->assertStringContainsString('marketRequestController.abort();', $script);
        $this->assertStringContainsString('var requestId = ++marketRequestSequence;', $script);
        $this->assertStringContainsString('signal: requestController.signal', $script);
        $this->assertStringContainsString('requestId !== marketRequestSequence', $script);
        $this->assertStringContainsString("error.name === 'AbortError'", $script);
    }

    /** 模态弹窗：打开后接管焦点，Tab 不得越过遮罩，关闭后恢复触发位置 */
    public function test_modal_dialogs_manage_and_restore_keyboard_focus(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');

        $this->assertIsString($script);
        $this->assertStringContainsString('var modalFocusableSelector =', $script);
        $this->assertStringContainsString('function showModalDialog(dialog)', $script);
        $this->assertStringContainsString('function hideModalDialog(dialog)', $script);
        $this->assertStringContainsString('function trapModalFocus(event, dialog)', $script);
        $this->assertStringContainsString('dialog._webosReturnFocus = document.activeElement;', $script);
        $this->assertStringContainsString('focusables[0].focus();', $script);
        $this->assertStringContainsString('returnFocus.focus();', $script);
        $this->assertStringContainsString("if (event.key === 'Tab' && activeDialog)", $script);
        $this->assertStringContainsString('hideModalDialog(elements.installDialog);', $script);
        $this->assertStringContainsString('showModalDialog(elements.actionDialog);', $script);
    }

    /** 前台菜单链接兼容：域名绑定应用的 home 菜单路径换算为绑定域名（复用框架 menu_path），普通应用保持主站地址 */
    public function test_home_menu_urls_converts_domain_bound_paths(): void
    {
        $this->actingAdmin();

        AdminMenu::create([
            'name' => '论坛', 'parent_id' => 0, 'app_id' => 'cmspro.forum',
            'path' => '/forum', 'terminal_type' => 'home', 'status' => 1, 'visible' => 1, 'sort' => 1,
        ]);
        AdminMenu::create([
            'name' => '站点', 'parent_id' => 0, 'app_id' => 'cmspro.site',
            'path' => '/site', 'terminal_type' => 'home', 'status' => 1, 'visible' => 1, 'sort' => 2,
        ]);
        // cmspro.site 配置为域名绑定模式并绑定独立域名
        \App\Models\ConfigItem::create([
            'group_id' => 0, 'name' => '访问模式', 'code' => 'app_cmspro_site_access_mode',
            'value' => 'domain', 'type' => 'text', 'status' => \App\Enums\Status::ENABLED,
        ]);
        \App\Models\ConfigItem::create([
            'group_id' => 0, 'name' => '绑定域名', 'code' => 'app_cmspro_site_access_domain',
            'value' => 'site.example.com', 'type' => 'text', 'status' => \App\Enums\Status::ENABLED,
        ]);

        $response = $this->getJson('/admin/cmspro/webos/api/home-menu-urls?app_ids[]=cmspro.forum&app_ids[]=cmspro.site&app_ids[]=bad id!');

        $response->assertOk()->assertJsonPath('code', 0);
        $data = $response->json('data');
        // 域名绑定应用：menu_path 返回绑定域名根（丢弃原始路径，绑定站点根即应用首页）
        $this->assertSame('http://site.example.com/', $data['cmspro.site'][0]['url']);
        // 普通应用：main_url 主站地址（测试环境为主站域名），路径保留
        $this->assertStringEndsWith('/forum', $data['cmspro.forum'][0]['url']);
        // 非法 app_id 已被过滤
        $this->assertArrayNotHasKey('bad id!', $data);
    }

    /** 前台链接兼容的前端接入：叶子合并 url 字段，转换失败回退原始路径 */
    public function test_home_menu_url_merge_in_frontend(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');

        $this->assertIsString($script);
        $this->assertStringContainsString('/admin/cmspro/webos/api/home-menu-urls?app_ids[]=', $script);
        $this->assertStringContainsString("leaf.url = urlMap[leaf.path];", $script);
        $this->assertStringContainsString("escapeHtml(leaf.url || leaf.path)", $script);
    }

    /** 选项卡全部关闭后的空状态引导：恢复选项卡时先移除非页面子节点，避免引导残留 */
    public function test_window_tabs_empty_state_cleared_on_restore(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');

        $this->assertIsString($script);
        $this->assertStringContainsString('选项卡已全部关闭，可从左侧菜单重新打开内容', $script);
        // renderWindowPage tabs 分支的清理循环：非 .window-page 子节点（空状态）在恢复选项卡前移除
        $this->assertMatchesRegularExpression(
            '/if \(windowState\.tabs\)\s*\{\s*var tab = entry;\s*\/\/[^\r\n]*空状态[^\r\n]*\s*Array\.prototype\.forEach\.call\(host\.children, function \(node\)\s*\{\s*if \(node\.classList && !node\.classList\.contains\(\'window-page\'\)\)\s*\{\s*node\.remove\(\);/',
            $script
        );
    }

    /** 图标别名兼容 + 开始菜单「全部功能」启动台 */
    public function test_start_folder_icon_and_launcher(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $this->assertIsString($script);

        // 目录项 start_icon 优先用目录/分组自身图标，渲染处不再硬编码 fa-folder
        $this->assertStringContainsString("representative.folder_icon || representative.group_icon || 'fa fa-folder'", $script);
        $this->assertStringContainsString('safeIcon(item.start_icon)', $script);

        // iconPicker 短代码别名 → layui 类名（与框架 ConfigController::buildMenuTree 一致），safeIcon 统一出口
        $this->assertStringContainsString('var ICON_ALIASES = {', $script);
        $this->assertStringContainsString("IconImage: 'layui-icon layui-icon-picture'", $script);
        $this->assertMatchesRegularExpression('/if \(ICON_ALIASES\[className\]\)\s*\{\s*return ICON_ALIASES\[className\];/', $script);

        // 开始菜单「全部功能」按钮：data-open-special="entries" 特例打开启动台；应用行菜单保持「管理入口」
        $this->assertMatchesRegularExpression(
            '/openSpecial === \'entries\'\)\s*\{\s*openLauncherDialog\(\);\s*return;\s*\}/',
            $script
        );
        $this->assertStringContainsString('function openLauncherDialog()', $script);
        // 启动台与系统菜单同源（菜单树）：按顶级分类逐层进入——文件夹卡片（目录图标优先/文件夹兜底）+ 叶子应用卡片 + 面包屑返回
        $this->assertStringContainsString('function renderLauncherView(', $script);
        $this->assertStringContainsString('function launcherFolderMarkup(', $script);
        $this->assertStringContainsString('function launcherLeafMarkup(', $script);
        $this->assertStringContainsString('function collectLauncherLeaves(', $script);
        $this->assertStringContainsString("safeIcon(item.icon || 'fa fa-folder')", $script);
        // 分组卡片与开始菜单应用聚合卡同标准：带 app_id 走 applicationIconMarkup 应用图片，
        // 无应用回退 is-folder-icon 底色风格（folder 黄底，与开始菜单目录卡片一致）
        $this->assertStringContainsString("applicationIconMarkup(application, 'launcher-item-icon')", $script);
        $this->assertStringContainsString('launcher-item-icon is-folder-icon', $script);
        // 分组卡片点击行为与开始菜单应用卡一致：有应用关联直接打开应用窗口（应用代表叶子 data-launcher-leaf），
        // 不再进入子菜单；纯目录分组（无 app_id）保持 data-launcher-folder 进入子目录
        $this->assertMatchesRegularExpression(
            "/var representative = application\s*\? state\.flatMenus\.find\(function \(candidate\) \{ return candidate\.app_id === application\.app_id; \}\)\s*: null;/",
            $script
        );
        $this->assertMatchesRegularExpression(
            "/var action = representative\s*\? ' data-launcher-leaf=\"menu-' \+ escapeHtml\(String\(representative\.menu_id\)\) \+ '\"'\s*: ' data-launcher-folder=\"' \+ escapeHtml\(path\.join\(','\)\) \+ '\"';/",
            $script
        );
        // 叶子卡片图标与开始菜单（系统菜单）同标准：app_id 取 flattenMenus 继承后的值（树原生节点仅顶层带 app_id，
        // 深层叶子靠祖先继承）+ entryIconMarkup 同一渲染函数（开始菜单应用项同款，findApplication → applicationIconMarkup）
        $this->assertStringContainsString('function findFlatLeaf(', $script);
        $this->assertMatchesRegularExpression(
            '/var leaf = findFlatLeaf\(item\.id\) \|\| \{ app_id: item\.app_id \|\| \'\', icon: item\.icon \};/',
            $script
        );
        $this->assertStringContainsString("entryIconMarkup(leaf, 'launcher-item-icon')", $script);
        // is-app-icon 由 applicationIconMarkup 源头运行时拼接（JS 字面量单引号闭合在 src=" 后，故断言不带尾引号）
        $this->assertStringContainsString("className + ' is-app-icon", $script);
        $this->assertStringContainsString('data-launcher-folder', $script);
        $this->assertStringContainsString('data-launcher-leaf', $script);
        $this->assertStringContainsString('data-launcher-crumb', $script);
        // 右上角关闭按钮：浮层 markup 内 data-launcher-close + 点击委托 closeLauncherDialog
        $this->assertMatchesRegularExpression(
            '/<button class="launcher-close" type="button" aria-label="关闭" data-launcher-close><i class="fa fa-times"><\/i><\/button>/',
            $script
        );
        $this->assertMatchesRegularExpression(
            '/if \(event\.target\.closest\(\'\[data-launcher-close\]\'\)\)\s*\{\s*closeLauncherDialog\(\);\s*return;\s*\}/',
            $script
        );
        // 空白区域点击关闭：非功能卡片（launcher-item）、非搜索框（launcher-search）区域即关闭
        $this->assertMatchesRegularExpression(
            '/if \(!event\.target\.closest\(\'\.launcher-item\'\) && !event\.target\.closest\(\'\.launcher-search\'\)\)\s*\{\s*closeLauncherDialog\(\);\s*\}/',
            $script
        );
        // 叶子打开：findEntry → openMenuByType（_blank/_layer）|| openEntry 后关闭浮层
        $this->assertMatchesRegularExpression(
            '/var entry = findEntry\(leaf\.dataset\.launcherLeaf\);\s*if \(entry && !openMenuByType\(entry\)\)\s*\{\s*openEntry\(entry\);\s*closeLauncherDialog\(\);\s*\}/',
            $script
        );
        $this->assertStringContainsString("['manage-entry', 'fa-th', '管理入口']", $script);

        // 开始菜单按钮文案（Blade）：data-open-special="entries" 显示为「全部功能」
        $blade = file_get_contents(dirname(__DIR__, 2) . '/Views/Admin/desktop/index.blade.php');
        $this->assertIsString($blade);
        $this->assertStringContainsString('data-open-special="entries">全部功能</button>', $blade);

        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');
        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('.launcher-layer', $stylesheet);
        // 启动台图片图标约束：is-app-icon 由 applicationIconMarkup 源头声明（所有渲染位共享）
        $this->assertStringContainsString('.webos-desktop .launcher-item-icon.is-app-icon img', $stylesheet);
        $this->assertMatchesRegularExpression(
            '/\.launcher-item img\s*\{\s*pointer-events:\s*none;/',
            $stylesheet
        );
        $this->assertMatchesRegularExpression(
            '/className \+ \' is-app-icon"><img data-app-icon-primary/',
            $script
        );
        // 启动台全屏：浮层纵向铺满、搜索框顶部、网格从顶部排列
        $this->assertMatchesRegularExpression(
            '/\.launcher-layer\s*\{[^}]*flex-direction:\s*column;/',
            $stylesheet
        );
        $this->assertMatchesRegularExpression(
            '/\.launcher-panel\s*\{[^}]*height:\s*100%;/',
            $stylesheet
        );
        $this->assertMatchesRegularExpression(
            '/\.launcher-grid\s*\{[^}]*align-content:\s*start;/',
            $stylesheet
        );
    }
}
