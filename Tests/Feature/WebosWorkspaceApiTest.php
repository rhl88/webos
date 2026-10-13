<?php

namespace App\Apps\CmsproWebos\Tests\Feature;

use App\Apps\CmsproWebos\Tests\WebosTestCase;
use App\Models\AdminMenu;
use App\Models\AdminUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

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

    /** 任务栏行为偏好（Windows 式自动隐藏/锁定）：保存往返持久化，非法值校验拒绝 */
    public function test_workspace_api_persists_taskbar_behavior_preferences(): void
    {
        $this->actingAdmin();

        $this->putJson('/admin/cmspro/webos/api/workspace', [
            'preferences' => ['taskbar_autohide' => true, 'taskbar_locked' => true],
        ])->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.preferences.taskbar_autohide', true)
            ->assertJsonPath('data.preferences.taskbar_locked', true);

        $this->getJson('/admin/cmspro/webos/api/workspace')
            ->assertOk()
            ->assertJsonPath('data.preferences.taskbar_autohide', true)
            ->assertJsonPath('data.preferences.taskbar_locked', true);

        $this->putJson('/admin/cmspro/webos/api/workspace', [
            'preferences' => ['taskbar_autohide' => 'yes'],
        ])->assertUnprocessable()
            ->assertJsonPath('code', 40201);
    }

    /** 任务栏图标显示方式偏好（Windows 式：纯图标/图标+文字）：保存往返持久化，非法值校验拒绝 */
    public function test_workspace_api_persists_taskbar_button_style_preference(): void
    {
        $this->actingAdmin();

        $this->putJson('/admin/cmspro/webos/api/workspace', [
            'preferences' => ['taskbar_button_style' => 'text'],
        ])->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.preferences.taskbar_button_style', 'text');

        $this->getJson('/admin/cmspro/webos/api/workspace')
            ->assertOk()
            ->assertJsonPath('data.preferences.taskbar_button_style', 'text');

        $this->putJson('/admin/cmspro/webos/api/workspace', [
            'preferences' => ['taskbar_button_style' => 'both'],
        ])->assertUnprocessable()
            ->assertJsonPath('code', 40201);
    }

    /** 任务栏图标显示方式：固定图标与运行窗口按钮渲染标题 label，CSS 按偏好控制显隐（竖排保持纯图标） */
    public function test_taskbar_button_style_renders_labels_with_css_scope(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');

        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        // 设置面板：任务栏位置下新增「任务栏图标显示」choice（纯图标 / 图标+文字）
        $this->assertStringContainsString('<strong>任务栏图标显示</strong>', $script);
        $this->assertStringContainsString("settingsChoice('set-taskbar-button-style', 'icon'", $script);
        $this->assertStringContainsString("settingsChoice('set-taskbar-button-style', 'text'", $script);
        $this->assertStringContainsString('[data-set-taskbar-button-style]', $script);
        // 偏好驱动：dataset 作用域 + 默认值声明
        $this->assertStringContainsString('root.dataset.taskbarButtonStyle', $script);
        $this->assertStringContainsString("taskbar_button_style: 'icon'", $script);
        // 固定图标与运行窗口按钮均渲染标题 label（CSS 控制显隐，切换偏好无需重渲染）
        $this->assertStringContainsString('<span class="taskbar-button-label" aria-hidden="true">', $script);
        $this->assertStringContainsString('.taskbar-button-label {', $stylesheet);
        $this->assertStringContainsString('[data-taskbar-button-style="text"] .taskbar-app-button', $stylesheet);
        $this->assertStringContainsString('[data-taskbar-button-style="text"] .taskbar-button-label', $stylesheet);
        $this->assertStringContainsString('[data-taskbar-position="left"] .taskbar-button-label', $stylesheet);
    }

    /** 任务栏空白右键菜单（Windows 式）：自动隐藏/锁定/任务栏设置 + 设置面板开关与 CSS 收起样式 */
    public function test_taskbar_shell_context_menu_and_settings(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');

        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        // 任务栏空白右键：固定/窗口按钮保留各自菜单，空白处弹出 Windows 式菜单
        $this->assertStringContainsString('function taskbarShellMenuItems()', $script);
        $this->assertStringContainsString('elements.taskbar.addEventListener(\'contextmenu\'', $script);
        $this->assertStringContainsString("event.target.closest('.taskbar-app-button')", $script);
        // 菜单动作：自动隐藏切换 / 锁定切换 / 打开任务栏设置（基本设置 Tab）
        $this->assertStringContainsString("action: 'taskbar-autohide'", $script);
        $this->assertStringContainsString("action: 'taskbar-locked'", $script);
        $this->assertStringContainsString("action: 'taskbar-settings'", $script);
        $this->assertStringContainsString("action === 'taskbar-autohide' || action === 'taskbar-locked'", $script);
        $this->assertStringContainsString("osSettingsTab = 'general';", $script);
        // 锁定任务栏：禁用固定图标拖动（左键打开不受影响）
        $this->assertStringContainsString("root.dataset.taskbarLocked !== 'on'", $script);
        // 自动隐藏：桌面容器 data 属性驱动 CSS，按停靠方向收起
        $this->assertStringContainsString('root.dataset.taskbarAutohide', $script);
        $this->assertStringContainsString('[data-taskbar-autohide="on"]', $stylesheet);
        $this->assertStringContainsString('.webos-desktop[data-taskbar-autohide="on"][data-taskbar-position="bottom"] .webos-taskbar:not(:hover):not(:focus-within)', $stylesheet);
        // 设置面板「任务栏行为」卡与默认偏好声明
        $this->assertStringContainsString('data-toggle-webos-setting="taskbar_autohide"', $script);
        $this->assertStringContainsString('data-toggle-webos-setting="taskbar_locked"', $script);
        $this->assertStringContainsString('taskbar_autohide: false,', $script);
        $this->assertStringContainsString('taskbar_locked: false,', $script);
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
            "/window\.addEventListener\('blur', function \(\)\s*\{\s*closeDesktopContextMenu\(\);\s*closeTaskbarContextMenu\(\);\s*closeAppRowMenus\(\);\s*closeFolderOverlay\(\);\s*\}\);/",
            $script
        );
        $this->assertMatchesRegularExpression(
            "/page\.dataset\.pageToken = '';\s*\}\s*windowState\.activeTabId = tab\.id;\s*renderWindowPage\(windowState, tab\);/",
            $script
        );
        $this->assertStringContainsString("'tab-close-others'", $script);
        // 双击拖动区切换最大化还原：dblclick 委托在窗口元素上按 closest('[data-window-drag]') 分发
        // （浏览器标签栏是手势绑定后才动态创建的，逐元素绑定会漏绑），按钮/标签/输入控件不触发
        $this->assertMatchesRegularExpression(
            "/element\.addEventListener\('dblclick', function \(event\) \{\s*if \(!event\.target\.closest\('\[data-window-drag\]'\)\s*\|\| event\.target\.closest\('button, \.browser-tab, input, select, textarea'\)\) \{\s*return;\s*\}\s*if \(target\.maximized\) \{\s*restoreWindow\(key\);\s*\} else \{\s*maximizeWindow\(key\);\s*\}\s*\}\);/",
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
        $this->assertStringContainsString('return entryAssigned(item.id) && !item.parent_folder;', $script);
        $this->assertStringContainsString('taskbarItems().filter(function (item) {', $script);
        // 菜单目录刷新后同步重绘桌面与任务栏
        $this->assertMatchesRegularExpression('/renderDesktop\(\);\s*[\r\n]+\s*renderPinnedApps\(\);/', $script);
    }

    /** 停用应用桌面图标不占位：可见集合统一过滤，布局归一化让重新启用的图标自动落到空白格 */
    public function test_disabled_app_icons_do_not_occupy_grid_cells(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');

        $this->assertIsString($script);
        // 可见桌面项统一出口：渲染/拖拽占用/找空行都基于已分配集合
        $this->assertStringContainsString('function assignedDesktopItems', $script);
        $this->assertMatchesRegularExpression('/return state\.workspace\.desktop_items\.filter\(function \(item\) \{\s*[\r\n]+\s*return entryAssigned\(item\.id\) && !item\.parent_folder;/', $script);
        // 拖拽落点占用集合仅统计可见图标（未分配入口的格子可移入）
        $this->assertStringContainsString('var occupied = new Set(assignedDesktopItems().filter(function (entry) {', $script);
        // 桌面网格可视容量：行列数按容器可视宽高与当前档位决定（窗口缩放/跨屏、任务栏移动、图标切档均会改变）
        $this->assertStringContainsString('function desktopGridCapacity', $script);
        // 新增入口列优先找空位：行数、列数均由可视容量决定，第一列放满后进入下一列，全满时提示整理
        $this->assertStringContainsString('if (!occupied.has(column + \':\' + row)) {', $script);
        $this->assertStringContainsString("toast('桌面可视区域已满，请先整理或移除部分图标', 'error');", $script);
        // 布局归一化：坐标冲突的重新启用图标按 (y,x) 顺序下移到第一个空白格并静默保存；
        // 超出可视容量（纵向/横向越界）的图标按列优先规则放回第一个空格
        $this->assertStringContainsString('function normalizeDesktopLayout', $script);
        $this->assertStringContainsString('while (occupied.has(x + \',\' + y) && guard < 200) {', $script);
        $this->assertStringContainsString('if ((rows > 0 && y >= rows) || (cols > 0 && x >= cols)) {', $script);
        $this->assertStringContainsString('overflow.forEach(function (item) {', $script);
        $this->assertStringContainsString('if (changed) { saveWorkspace(false).catch(function () {}); }', $script);
        // 归一化触发点：目录刷新、首次渲染、窗口缩放自适应、任务栏移动、图标档位切换、内置浏览器补齐归位
        $this->assertSame(6, substr_count($script, 'normalizeDesktopLayout();'));
        // 桌面右键「自动排列图标」：列优先紧凑重排（复用可视容量，拖乱后一键恢复整齐）
        $this->assertStringContainsString('function autoArrangeDesktopIcons', $script);
        $this->assertStringContainsString("['自动排列图标', 'fa-magic', 'auto-arrange', '', null]", $script);
        $this->assertStringContainsString("if (action === 'auto-arrange') {", $script);
        // 键盘操作：Ctrl+A 全选、方向键移动焦点、Enter 打开、Delete 删除
        $this->assertStringContainsString("ArrowRight: [1, 0], ArrowLeft: [-1, 0], ArrowDown: [0, 1], ArrowUp: [0, -1]", $script);
        $this->assertStringContainsString('targets.forEach(removeDesktopEntry);', $script);
        // 标签文字效果（OS 设置三选一，默认无效果）：默认样式无底衬，底衬/阴影挂在 data-label-effect 作用域
        $this->assertStringContainsString('.desktop-icon-label {', $stylesheet);
        // 图标与文字垂直堆叠：图标图形与标签均为行内级，短标签（两字标题）会与图标挤进同一行
        $this->assertStringContainsString('短标签（如两字标题）会与图标挤进同一行，flex column 强制各占一行', $stylesheet);
        $this->assertStringContainsString("align-items: center;\n    width: 88px;", $stylesheet);
        $this->assertStringContainsString('.desktop-icons[data-label-effect="shield"] .desktop-icon-label {', $stylesheet);
        $this->assertStringContainsString('.desktop-icons[data-label-effect="shadow"] .desktop-icon-label {', $stylesheet);
        $this->assertStringContainsString('text-shadow: 0 1px 2px rgba(0, 0, 0, 0.85), 0 0 5px rgba(0, 0, 0, 0.6);', $stylesheet);
        $this->assertStringContainsString('.settings-choice-grid.is-three {', $stylesheet);
        // 偏好写入：容器 data 属性切换 + OS 设置单选委托 + 后端白名单
        $this->assertStringContainsString('elements.desktopIcons.dataset.labelEffect = preferences.label_effect', $script);
        $this->assertStringContainsString("settingsChoice('set-label-effect', 'none'", $script);
        $this->assertStringContainsString("settingsChoice('set-label-effect', 'shadow'", $script);
        $this->assertStringContainsString("settingsChoice('set-label-effect', 'shield'", $script);
        $this->assertStringContainsString("closest('[data-set-label-effect]')", $script);
        $service = file_get_contents(dirname(__DIR__, 2) . '/Services/WorkspaceService.php');
        $this->assertStringContainsString("'label_effect' => 'none',", $service);
        $this->assertStringContainsString("['none', 'shadow', 'shield']", $service);
    }

    /** 系统菜单应用卡片右键菜单：打开（所有人）/ 添加到桌面(从桌面移除) / 停用(启用)/卸载（仅超管，复用应用中心接口与卸载流程） */
    public function test_start_item_context_menu_supports_open_disable_uninstall(): void
    {
        $javascript = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');

        $this->assertIsString($javascript);
        $this->assertStringContainsString('function startItemContextMenuItems', $javascript);
        // 禁用应用：菜单项为 打开（置灰不可点击）/ 启用 / 卸载（仅超管）
        $this->assertStringContainsString("var disabled = Boolean(application && Number(application.status) !== 1);", $javascript);
        $this->assertStringContainsString("disabled ? '打开（应用已禁用）' : '打开'", $javascript);
        $this->assertStringContainsString("items.push([disabled ? '启用' : '停用'", $javascript);
        $this->assertStringContainsString("items.push(['卸载', 'fa-times-circle', 'uninstall', 'danger']);", $javascript);
        // 固定到桌面：按当前固定状态显示 添加到桌面/从桌面移除（与卡片图钉按钮同链路 toggleDesktopEntry）
        $this->assertStringContainsString("items.push(['从桌面移除', 'fa-trash-o', 'toggle-pin']);", $javascript);
        $this->assertStringContainsString("items.push(['添加到桌面', 'fa-thumb-tack', 'toggle-pin']);", $javascript);
        // 右键菜单 disabled 项渲染为灰化且 button disabled；danger 项精确比较避免 disabled 误染红色
        $this->assertStringContainsString("item[3] === 'danger' ? 'is-danger'", $javascript);
        $this->assertStringContainsString("item[3] === 'disabled' ? ' is-disabled'", $javascript);
        $this->assertStringContainsString("item[3] === 'disabled' ? ' disabled'", $javascript);
        // 停用/启用复用应用中心状态开关，卸载前收起开始菜单面板避免弹窗遮挡
        $this->assertStringContainsString("action === 'toggle-app-status'", $javascript);
        $this->assertStringContainsString('function openUninstallDialog', $javascript);
        // 开始面板右键委托绑定（含「最近」区块卡片）
        $this->assertStringContainsString("elements.startPanel.addEventListener('contextmenu'", $javascript);
    }

    /** 开始菜单禁用应用：卡片灰化不可点击（点击/键盘激活拦截），右键菜单可启用，启用后开始菜单同步刷新 */
    public function test_start_menu_disables_disabled_application_cards(): void
    {
        $javascript = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');

        $this->assertIsString($javascript);
        $this->assertIsString($stylesheet);
        // 开始菜单数据带出禁用状态，卡片渲染 is-disabled + aria-disabled（常用与「最近」两处）
        $this->assertStringContainsString("start_disabled: Boolean(application && Number(application.status) !== 1)", $javascript);
        $this->assertStringContainsString("(item.start_disabled ? ' is-disabled' : '')", $javascript);
        $this->assertStringContainsString('(item.start_disabled ? \'true\' : \'false\')', $javascript);
        // 点击与键盘激活均拦截 is-disabled 卡片
        $this->assertStringContainsString("!menuButton.classList.contains('is-disabled')", $javascript);
        $this->assertStringContainsString("!startItem.classList.contains('is-disabled')", $javascript);
        // 状态切换成功后开始菜单同步重渲染（右键与应用中心两处调用）
        $this->assertStringContainsString("function () {\n                    renderStartMenu();\n                }", $javascript);
        $this->assertStringContainsString("function () { renderStartMenu(); }", $javascript);
        // 灰化样式：开始菜单卡片与右键菜单禁用项
        $this->assertStringContainsString('.start-app-item.is-disabled', $stylesheet);
        $this->assertStringContainsString('.desktop-context-item.is-disabled', $stylesheet);
    }

    /** 开始菜单显示停用应用：停用应用菜单接口按 userMenus 口径返回禁用菜单（userMenus 已联动过滤不再返回） */
    public function test_start_disabled_menus_api_returns_disabled_app_menus(): void
    {
        $admin = $this->actingAdmin();
        $superRole = \App\Models\AdminRole::create(['name' => '超级管理员', 'code' => 'super_admin', 'status' => 1]);
        DB::table('admin_user_roles')->insert(['user_id' => $admin->id, 'role_id' => $superRole->id]);

        // 启用应用菜单（对照组）：userMenus 正常返回
        $category = AdminMenu::create(['name' => '内容管理', 'parent_id' => 0, 'terminal_type' => 'admin', 'status' => 1, 'visible' => 1, 'sort' => 1]);
        $enabledGroup = AdminMenu::create(['name' => '启用应用', 'parent_id' => $category->id, 'app_id' => 'cmspro.enabled_test', 'terminal_type' => 'admin', 'status' => 1, 'visible' => 1, 'sort' => 2]);
        AdminMenu::create(['name' => '启用菜单', 'parent_id' => $enabledGroup->id, 'app_id' => 'cmspro.enabled_test', 'path' => '/admin/enabled-test/index', 'terminal_type' => 'admin', 'status' => 1, 'visible' => 1, 'sort' => 3]);

        // 停用应用菜单：应用停用时框架联动把菜单置为禁用（AppManagerService::disableMenus）
        $disabledGroup = AdminMenu::create(['name' => '停用应用', 'parent_id' => $category->id, 'app_id' => 'cmspro.disabled_test', 'terminal_type' => 'admin', 'status' => 0, 'visible' => 1, 'sort' => 4]);
        AdminMenu::create(['name' => '停用菜单', 'parent_id' => $disabledGroup->id, 'app_id' => 'cmspro.disabled_test', 'path' => '/admin/disabled-test/index', 'terminal_type' => 'admin', 'status' => 0, 'visible' => 1, 'sort' => 5]);
        // 隐藏菜单不返回（与 userMenus 的 visible 口径一致）
        AdminMenu::create(['name' => '停用隐藏菜单', 'parent_id' => $category->id, 'app_id' => 'cmspro.disabled_test', 'path' => '/admin/disabled-test/hidden', 'terminal_type' => 'admin', 'status' => 0, 'visible' => 0, 'sort' => 6]);

        // 停用应用记录（status=2 DISABLED）；启用应用不入 apps 表（对照其菜单不应出现在停用列表）
        \App\Models\AppModel::create([
            'app_id' => 'cmspro.disabled_test',
            'name' => '停用测试应用',
            'version' => '1.0.0',
            'status' => \App\Enums\AppStatus::DISABLED->value,
            'is_system' => false,
        ]);

        // 现状：userMenus 已不再返回停用应用菜单（开始菜单因此无数据）
        $userTree = $this->getJson('/api/admin/menus/user')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->json('data');
        $this->assertNotContains('停用应用', collect($userTree)->pluck('name')->all());

        // 停用应用菜单接口：仅返回停用应用的可见禁用菜单（含应用文件夹节点与叶子）
        $data = $this->getJson('/admin/cmspro/webos/api/start-disabled-menus')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->json('data');
        $names = collect($data)->pluck('name')->all();
        $this->assertContains('停用应用', $names);
        $this->assertContains('停用菜单', $names);
        $this->assertNotContains('启用应用', $names, '启用应用的菜单不应出现在停用列表');
        $this->assertNotContains('停用隐藏菜单', $names, '隐藏菜单不应出现在停用列表');
        // 停用应用带 app_id，前端据此挂树并按应用状态灰化
        $this->assertSame('cmspro.disabled_test', collect($data)->firstWhere('name', '停用应用')['app_id']);
    }

    /** 开始菜单数据源并入停用应用菜单：仅开始菜单使用 startFlatMenus，桌面仍基于 flatMenus 不显示停用应用 */
    public function test_start_menu_merges_disabled_app_menus(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');

        $this->assertIsString($script);
        // 目录加载并行请求停用应用菜单（失败降级为空列表，不阻塞目录渲染）
        $this->assertStringContainsString("api('/admin/cmspro/webos/api/start-disabled-menus' + cacheBust).catch(function () { return []; })", $script);
        // 停用菜单并入目录树：组内按 parent_id 组子树，顶层挂目录树对应分类，父缺失丢弃
        $this->assertStringContainsString('function mergeDisabledMenus(tree, disabledMenus)', $script);
        $this->assertStringContainsString('function findMenuNode(tree, menuId)', $script);
        // 开始菜单用并入后的 startFlatMenus；flatMenus 保持不含停用应用（桌面/任务栏「已分配」口径不变）
        $this->assertStringContainsString('state.startFlatMenus = flattenMenus(mergeDisabledMenus(state.catalog.menus, responses[2]));', $script);
        $this->assertStringContainsString('state.startFlatMenus.forEach(function (item) {', $script);
    }

    /** 系统菜单「最近」区块（常用下方显示最近打开 6 项）与桌面右键菜单全屏切换项 */
    public function test_start_menu_recent_section_and_fullscreen_toggle(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');
        $view = file_get_contents(dirname(__DIR__, 2) . '/Views/Admin/desktop/index.blade.php');

        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        $this->assertIsString($view);
        // Blade：最近区块容器（常用网格下方）；任务栏全屏按钮已移除（全屏入口迁至桌面右键菜单）
        $this->assertStringContainsString('<div id="start-recent" class="start-recent" hidden></div>', $view);
        $this->assertStringNotContainsString('fullscreen-button', $view);
        // JS：最近区块复用 usage_stats 的 last_opened_at 降序取前 6，副标题相对时间（x 秒/分钟/小时/天/月前打开）；无记录时隐藏
        $this->assertStringContainsString('function renderStartRecent(groups, query)', $script);
        // 「最近」区块仅在「常用」分类且非搜索时渲染：其它分类/搜索传 null，renderStartRecent 内隐藏
        $this->assertStringContainsString("renderStartRecent(!query && current && current.id === 'common' ? groups : null, query);", $script);
        $this->assertStringContainsString('var recent = groups && !query', $script);
        $this->assertStringContainsString("statistics[right.start_key].last_opened_at.localeCompare(statistics[left.start_key].last_opened_at)", $script);
        // 数据源排除「常用」虚拟分组（commonGroup 条目与原始分组同 start_key，不排除会成对重复）
        $this->assertStringContainsString("groups.filter(function (group) { return group.id !== 'common'; })", $script);
        // 副标题相对时间：秒/分钟/小时/天/月五档进位 + 年兜底，不足 1 秒「刚刚打开」
        $this->assertStringContainsString('function relativeOpenedAt(value)', $script);
        $this->assertStringContainsString("return Math.floor(amount) + ' ' + suffix;", $script);
        $this->assertStringContainsString("return Math.floor(amount) + ' 年前打开';", $script);
        $this->assertStringContainsString("escapeHtml(relativeOpenedAt(statistics[item.start_key].last_opened_at))", $script);
        $this->assertStringContainsString('host.hidden = true;', $script);
        // JS：全屏切换（保留 toggleFullscreen）+ 桌面右键菜单「显示桌面」下方动态文案项（每次渲染实时读取状态）
        $this->assertStringContainsString('function toggleFullscreen()', $script);
        $this->assertStringContainsString('var fullscreenActive = Boolean(document.fullscreenElement || document.webkitFullscreenElement);', $script);
        $this->assertStringContainsString(
            "[fullscreenActive ? '退出全屏' : '全屏显示', fullscreenActive ? 'fa-compress' : 'fa-expand', 'toggle-fullscreen', '', null]",
            $script
        );
        $this->assertStringContainsString("if (action === 'toggle-fullscreen') {", $script);
        $this->assertStringNotContainsString('syncFullscreenButton', $script);
        // CSS：最近标题保留；旧全屏按钮激活态样式已随按钮一并移除
        $this->assertStringContainsString('.start-recent-title {', $stylesheet);
        $this->assertStringNotContainsString('.fullscreen-button', $stylesheet);
    }

    /** 内置浏览器：默认内置不可删除、多标签 iframe 窗口、地址栏导航与刷新/新窗口兜底 */
    public function test_builtin_browser_window(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');
        $view = file_get_contents(dirname(__DIR__, 2) . '/Views/Admin/desktop/index.blade.php');
        $controller = file_get_contents(dirname(__DIR__, 2) . '/Controllers/Admin/WebosController.php');

        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        // 入口定义：special=browser + 空 path（内容不走应用 iframe path，由 renderBrowserWindow 管理）
        $this->assertStringContainsString('function webosBrowserEntry()', $script);
        $this->assertStringContainsString("id: 'webos-browser',", $script);
        $this->assertStringContainsString("special: 'browser'", $script);
        // 内置窗口白名单（不读管理员偏好尺寸）与固定单实例 key
        $this->assertStringContainsString("BUILTIN_WINDOW_KEYS = ['webos-app-center', 'webos-settings', 'webos-official-news', 'webos-browser']", $script);
        $this->assertStringContainsString("if (entry.id === 'webos-browser') {\n            return 'webos-browser';", $script);
        // 桌面初始预置与存量补齐（超管：应用中心+浏览器；非超管仅浏览器）
        $this->assertStringContainsString('var selected = isSuperAdmin ? [applicationCenterEntry(), webosBrowserEntry()] : [webosBrowserEntry()];', $script);
        $this->assertStringContainsString("return item.id === 'webos-browser';", $script);
        // 右键菜单不可删除（与应用中心同待遇）
        $this->assertStringContainsString("context.entry.id === 'webos-app-center' || context.entry.id === 'webos-browser'", $script);
        // 窗口默认 90%×86% + 特殊窗口骨架（无侧栏 page host）
        $this->assertStringContainsString('if (key === \'webos-browser\') {', $script);
        $this->assertStringContainsString("content = '<div class=\"webos-browser-shell\" data-webos-browser></div>';", $script);
        // 核心链路：URL 规范化（仅 http(s)）/渲染/更新/导航/刷新/关闭标签/事件绑定
        $this->assertStringContainsString('function normalizeBrowserUrl(input)', $script);
        $this->assertStringContainsString('function renderBrowserWindow(windowState)', $script);
        $this->assertStringContainsString('function updateBrowserWindow(windowState)', $script);
        $this->assertStringContainsString('function browserNavigate(windowState, tab, input)', $script);
        $this->assertStringContainsString('function browserReload(windowState, tab)', $script);
        $this->assertStringContainsString('function browserCloseTab(windowState, tabId)', $script);
        $this->assertStringContainsString('function bindBrowserEvents(shell, windowState)', $script);
        // 起始页 srcdoc 引导与 iframe 保活（按标签 id 显隐，不重建已加载页面）
        $this->assertStringContainsString('function browserStartPageMarkup()', $script);
        $this->assertStringContainsString('frame.srcdoc = browserStartPageMarkup();', $script);
        $this->assertStringContainsString("viewsHost.querySelector('[data-browser-view=\"' + tab.id + '\"]')", $script);
        // 全屏下任务栏地球图标改为在内置浏览器窗口打开（新标签页会退出全屏）
        $this->assertStringContainsString('function openBrowserWithURL(url)', $script);
        $this->assertStringContainsString('openBrowserWithURL(elements.websiteButton.dataset.websiteUrl);', $script);
        // 桌面项加载过滤：内置浏览器空 path 与桌面原生项（文件夹 path 置空、URL 链接为外链）均按特判/kind 放行，
        // 否则首次载入被 safePath 过滤导致浏览器图标、文件夹、URL 链接每次加载都不显示
        $this->assertStringContainsString("item.id === 'webos-browser' || item.kind === 'folder' || item.kind === 'url' || safePath(item.path)", $script);
        // 桌面渲染权限过滤放行内置浏览器（entryAssigned 无特判时浏览器图标被判未分配而不渲染）
        $this->assertStringContainsString("if (entryId === 'webos-browser') { return true; }", $script);
        // 应用窗口 iframe 内框架「前台」地球链接：全屏改在内置浏览器打开，非全屏保持 target=_blank 新窗口
        $this->assertStringContainsString('function bindWindowFrame(frame)', $script);
        $this->assertStringContainsString("target.closest('a.layui-icon-website')", $script);
        $this->assertStringContainsString('openBrowserWithURL(link.href);', $script);
        $this->assertStringContainsString('bindWindowFrame(frame);', $script);
        $this->assertStringContainsString('bindWindowFrame(windowFrame);', $script);
        // 标签站点图标与标题：后端元信息代理（跨域 iframe 读不到 title/favicon）
        $this->assertStringContainsString('function fetchPageMeta(tab)', $script);
        $this->assertStringContainsString('var requestedUrl = tab.url;', $script);
        $this->assertStringContainsString('tab.url !== requestedUrl', $script);
        $this->assertStringContainsString('fetchPageMeta(tab);', $script);
        // 标签 iframe 显隐用 hidden 属性：必须显式覆盖 .window-content iframe 的 display:block，
        // 否则作者样式压过 UA 的 [hidden] 默认样式，切换标签时内容停留在最后打开的标签
        $this->assertStringContainsString('.browser-views iframe[hidden] {', $stylesheet);
        // iframe 内点击不冒泡到父文档：通过 window blur + activeElement 为 IFRAME 判定，
        // 点击已打开窗口 iframe 内容时同样收起开始菜单等面板（与点击面板外部一致）
        $this->assertStringContainsString("window.addEventListener('blur', function () {", $script);
        $this->assertStringContainsString("active.tagName === 'IFRAME'", $script);
        // 元信息代理失败结果短缓存：瞬时失败不会被固化成空结果一整天（成功缓存 1 天）
        $service = file_get_contents(dirname(__DIR__, 2) . '/Services/PageMetaService.php');
        $this->assertStringContainsString('Cache::put($key, $meta, $succeeded ? now()->addDay() : now()->addMinutes(5));', $service);
        $this->assertStringContainsString('\'<img class="browser-tab-favicon" src="\' + escapeHtml(tab.icon)', $script);
        $this->assertStringContainsString('.browser-tab-favicon {', $stylesheet);
        $this->assertStringContainsString("data-page-meta-url=\"{{ url('/admin/cmspro/webos/api/page-meta') }}\"", $view);
        $this->assertStringContainsString('function pageMeta(Request $request)', $controller);
        // 浏览器窗口无标题栏：控制按钮内嵌标签栏右侧，标签栏空白区承担窗口拖动（手势事件委托到窗口元素）
        $this->assertStringContainsString("'<div class=\"browser-tabbar\" data-window-drag>", $script);
        $this->assertStringContainsString('browser-window-controls', $script);
        $this->assertStringContainsString("event.target.closest('[data-window-drag]')", $script);
        $this->assertStringContainsString("querySelectorAll('[data-window-action=\"maximize\"]')", $script);
        $this->assertStringContainsString('[data-window-key="webos-browser"] .window-titlebar', $stylesheet);
        $this->assertStringContainsString('.browser-window-controls {', $stylesheet);
        // CSS：浏览器外壳/标签条/标签页/iframe 视图铺满
        $this->assertStringContainsString('.webos-browser-shell {', $stylesheet);
        $this->assertStringContainsString('.browser-tabbar {', $stylesheet);
        $this->assertStringContainsString('.browser-tab {', $stylesheet);
        $this->assertStringContainsString('.browser-views iframe {', $stylesheet);
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
        // 渲染、网格容量、拖拽 move 三处按档位换算（新增找位/归一化经 desktopGridCapacity 间接取档位；拖拽落点用 dropSize）
        $this->assertSame(3, substr_count($script, 'var size = desktopIconSize();'));
        $this->assertStringContainsString('var dropSize = desktopIconSize();', $script);
        $this->assertStringContainsString('root.dataset.iconSize = ', $script);
        // 右键「查看」子菜单与切档动作（当前档位勾选）
        $this->assertStringContainsString("['查看', 'fa-th-large', '', '', sizeChildren]", $script);
        $this->assertStringContainsString("action === 'icon-large' || action === 'icon-medium' || action === 'icon-small'", $script);
        $this->assertStringContainsString('desktop-context-submenu', $stylesheet);
        $this->assertStringContainsString('.webos-desktop[data-icon-size="large"] .desktop-icon-badge', $stylesheet);
        // 子菜单防闪烁：与父菜单重叠 2px 消除间隙断点 + 常驻透明 hover 桥（指针缓慢斜移经过不再消失）
        $this->assertStringContainsString('left: calc(100% - 2px);', $stylesheet);
        $this->assertStringContainsString('.desktop-context-group.has-children::after {', $stylesheet);
        // 右键菜单紧凑化：行高调小（多菜单项时不再显得过高）
        $this->assertStringContainsString('.desktop-context-item {', $stylesheet);
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
        // 初始预置：非超管不固定应用中心（内置浏览器对所有角色可用）
        $this->assertStringContainsString('var selected = isSuperAdmin ? [applicationCenterEntry(), webosBrowserEntry()] : [webosBrowserEntry()];', $script);
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
        // 顶级分类拖拽挂为其它分类的子菜单（2.1.18）：超管渲染可拖分类按钮（复用 data-move-ids 链路），
        // 防拖到自身（dragover 不放行 + drop 兜底），拖拽中高亮类清理通用化覆盖分类按钮
        $this->assertStringContainsString("var draggable = isSuperAdmin && group.id !== 'common';", $script);
        $this->assertStringContainsString("if (draggingMoveIds.length === 1 && category.dataset.groupId === String(draggingMoveIds[0])) { return; }", $script);
        $this->assertStringContainsString("if (moveIds.length === 1 && category.dataset.groupId === String(moveIds[0])) { return; }", $script);
        $this->assertStringContainsString("querySelectorAll('.is-dragging')", $script);
        $this->assertStringContainsString('.start-category-button[draggable="true"]', $stylesheet);
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
        // 弹出层对话整层恢复文本可选（2.1.20）：.webos-dialog 整层（含标题/正文/摘要）与
        // .session-recovery-card 均 user-select: text，按钮文字另行禁选防拖选干扰点击
        $this->assertStringContainsString('user-select: text;', $stylesheet);
        $this->assertStringContainsString('.webos-dialog button {
    user-select: none;
}', $stylesheet);
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
        // 应用中心/内置浏览器均为内置入口：右键不显示删除项，removeDesktopEntry 对应用中心拦截兜底
        $this->assertStringContainsString("context.entry.id === 'webos-app-center' || context.entry.id === 'webos-browser'", $script);
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

        // 会话恢复：api()/fetchText 统一拦截 419/401——419 先静默刷新 CSRF Token（登录态可能仍有效，全程无感），
        // 刷新失败或 401 才走恢复流程（iframe 登录 + 轮询 + 新 Token）后自动重放一次；
        // 心跳保活每 5 分钟静默请求一次用户信息，防止挂机过期，正常时顺带同步最新 Token
        $this->assertStringContainsString('if (response.status === 419 || response.status === 401) {', $script);
        $this->assertStringContainsString('function refreshCsrfToken()', $script);
        $this->assertStringContainsString("return refreshed ? null : recoverSession();", $script);
        $this->assertStringContainsString('function parseApiResponse(response)', $script);
        $this->assertStringContainsString('function recoverSession()', $script);
        $this->assertStringContainsString('function openSessionRecovery()', $script);
        $this->assertStringContainsString('function startSessionHeartbeat()', $script);
        $this->assertStringContainsString('startSessionHeartbeat();', $script);
        // 会话恢复层 DOM：iframe 登录 + 取消按钮（桌面 Blade 视图）
        $view = file_get_contents(dirname(__DIR__, 2) . '/Views/Admin/desktop/index.blade.php');
        $this->assertIsString($view);
        $this->assertStringContainsString('id="session-recovery-layer"', $view);
        $this->assertStringContainsString('id="session-recovery-frame"', $view);
        $this->assertStringContainsString('id="session-recovery-cancel"', $view);

        // 桌面「应用中心」图标右上角的可更新数量角标：renderDesktop 按检查结果渲染，syncUpdateBadge 同步
        $this->assertStringContainsString("item.id === 'webos-app-center' && visibleUpdates.length", $script);
        $this->assertStringContainsString("'<span class=\"desktop-update-badge\">' + visibleUpdates.length + '</span>'", $script);
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

        // “窗口默认尺寸”只作用于应用窗口；WebOS 内置窗口固定使用出厂默认（官网动态固定 600×500，浏览器 90%×86%）
        $this->assertStringContainsString(
            "var BUILTIN_WINDOW_KEYS = ['webos-app-center', 'webos-settings', 'webos-official-news', 'webos-browser'];",
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

    /** 内置浏览器入口（id=webos-browser）path 为空放行（内容由前端管理、按 id 特判打开）；普通项空 path 仍拒绝 */
    public function test_workspace_api_allows_builtin_browser_empty_path(): void
    {
        $this->actingAdmin();

        $this->putJson('/admin/cmspro/webos/api/workspace', [
            'desktop_items' => [
                [
                    'id' => 'webos-browser',
                    'title' => '浏览器',
                    'path' => '',
                    'icon' => 'fa fa-compass',
                    'group_title' => 'WebOS',
                    'x' => 0,
                    'y' => 0,
                ],
                [
                    'id' => 'menu-1046',
                    'title' => '儿康管理系统',
                    'path' => '/admin/cmspro/childrehab/hospitals',
                    'icon' => 'fa fa-home',
                    'x' => 0,
                    'y' => 1,
                ],
            ],
            'preferences' => [],
        ])->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.desktop_items.0.path', '')
            ->assertJsonPath('data.desktop_items.1.path', '/admin/cmspro/childrehab/hospitals');

        // 再次读取验证持久化
        $this->getJson('/admin/cmspro/webos/api/workspace')
            ->assertOk()
            ->assertJsonPath('data.desktop_items.0.path', '');

        // 普通入口空 path 仍拒绝（仅 webos-browser 白名单放行）
        $this->putJson('/admin/cmspro/webos/api/workspace', [
            'desktop_items' => [[
                'id' => 'menu-1046',
                'title' => '儿康管理系统',
                'path' => '',
                'icon' => 'fa fa-home',
                'x' => 0,
                'y' => 0,
            ]],
            'preferences' => [],
        ])->assertUnprocessable()
            ->assertJsonPath('code', 40201);
    }

    /** 内置浏览器页面元信息接口：后端代理抓取站点 title/favicon（Http fake，不发生真实外网请求） */
    public function test_page_meta_api_returns_parsed_site_info(): void
    {
        $this->actingAdmin();

        Http::fake([
            'https://meta.example.com/' => Http::response(
                '<html><head><title>Meta 站点标题</title><link rel="icon" href="/favicon.ico"></head></html>'
            ),
            'https://meta.example.com/favicon.ico' => Http::response('ICO', 200, ['Content-Type' => 'image/x-icon']),
        ]);

        $this->getJson('/admin/cmspro/webos/api/page-meta?url=' . urlencode('https://meta.example.com/'))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.title', 'Meta 站点标题')
            ->assertJsonPath('data.icon', 'data:image/x-icon;base64,' . base64_encode('ICO'));

        // 非法协议：接口不放行也不发请求
        $this->getJson('/admin/cmspro/webos/api/page-meta?url=' . urlencode('file:///etc/passwd'))
            ->assertOk()
            ->assertJsonPath('data.title', '')
            ->assertJsonPath('data.icon', '');
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

    /** 上传安装：执行安装时与常规安装一致，可选择创建桌面快捷方式与各终端菜单挂载位置；手动升级弹层不受影响 */
    public function test_app_upload_install_offers_install_options(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');

        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        // 上传安装入口携带 installOptions，手动升级不携带（弹层保持原样）
        $this->assertStringContainsString("null, { installOptions: true });", $script);
        $this->assertStringContainsString("{ app_id: app.app_id });", $script);
        // 弹层内选项区：创建桌面快捷方式 + 菜单挂载位置（复用常规安装样式类）
        $this->assertStringContainsString('id="dlgCreateShortcut"', $script);
        $this->assertStringContainsString('id="dlgUploadMounts"', $script);
        $this->assertStringContainsString('.install-shortcut-option {', $stylesheet);
        $this->assertStringContainsString('.install-menu-parents {', $stylesheet);
        // 菜单挂载选项与常规安装共用渲染函数，并加载三终端菜单树
        $this->assertStringContainsString('function installMenuFieldsMarkup(terminals, menuTrees, hintText)', $script);
        $this->assertStringContainsString('function loadUploadMenuMounts(context)', $script);
        $this->assertStringContainsString("'/api/admin/menus/tree?terminal_type='", $script);
        // 提交按钮状态：已选文件且菜单挂载选项就绪才可提交
        $this->assertStringContainsString('function updateUploadSubmitState(context)', $script);
        // FormData 附加各终端挂载位置，与常规安装 parent_menu_ids 同构
        $this->assertStringContainsString("form.append('parent_menu_ids[' + terminal + ']', select.val());", $script);
        // 安装成功：先刷新目录（同步 flatMenus），再按需创建桌面快捷方式并询问启用
        $this->assertStringContainsString('function promptEnableFirstInstalledApp(withShortcut)', $script);
        $this->assertStringContainsString('promptEnableFirstInstalledApp(createShortcut);', $script);
        $this->assertStringContainsString('reloadInstalledAppCenter().then(function () {', $script);
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

    /** 初始数据：超管首次进入桌面固定「应用中心 + 浏览器」；非超管仅浏览器（应用中心仅超管可用） */
    public function test_initial_desktop_pins_default_builtin_entries(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');

        $this->assertIsString($script);
        // 初始预置：超管 = 应用中心 + 内置浏览器；非超管 = 仅内置浏览器（浏览器无权限要求）
        $this->assertStringContainsString('var selected = isSuperAdmin ? [applicationCenterEntry(), webosBrowserEntry()] : [webosBrowserEntry()];', $script);
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
        $this->assertStringContainsString("dragHost.addEventListener('pointercancel', end)", $script);
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

    /** 桌面原生项持久化：folder 空 path 放行、url 放行 http(s) 外链、parent_folder 往返；非法值拒绝 */
    public function test_workspace_api_persists_desktop_folders_and_url_entries(): void
    {
        $this->actingAdmin();

        $this->putJson('/admin/cmspro/webos/api/workspace', [
            'desktop_items' => [
                [
                    'id' => 'webos-folder-Ab12Cd34',
                    'title' => '新建文件夹',
                    'path' => '',
                    'icon' => 'fa fa-folder',
                    'group_title' => '文件夹',
                    'kind' => 'folder',
                    'x' => 0,
                    'y' => 0,
                ],
                [
                    'id' => 'webos-url-Ab12Cd34',
                    'title' => '官网',
                    'path' => 'https://www.example.com',
                    'icon' => 'fa fa-globe',
                    'group_title' => '链接',
                    'kind' => 'url',
                    'x' => 0,
                    'y' => 1,
                ],
                [
                    'id' => 'menu-1046',
                    'title' => '儿康管理系统',
                    'path' => '/admin/cmspro/childrehab/hospitals',
                    'icon' => 'fa fa-home',
                    'kind' => '',
                    'parent_folder' => 'webos-folder-Ab12Cd34',
                    'x' => 0,
                    'y' => 2,
                ],
            ],
            'preferences' => [],
        ])->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.desktop_items.0.kind', 'folder')
            ->assertJsonPath('data.desktop_items.0.path', '')
            ->assertJsonPath('data.desktop_items.1.kind', 'url')
            ->assertJsonPath('data.desktop_items.1.path', 'https://www.example.com')
            ->assertJsonPath('data.desktop_items.2.parent_folder', 'webos-folder-Ab12Cd34');

        // 再次读取验证持久化
        $this->getJson('/admin/cmspro/webos/api/workspace')
            ->assertOk()
            ->assertJsonPath('data.desktop_items.0.kind', 'folder')
            ->assertJsonPath('data.desktop_items.2.parent_folder', 'webos-folder-Ab12Cd34');

        // folder 传入的 path 强制置空（内容由前端浮层管理）；parent_folder 非法字符清理
        $this->putJson('/admin/cmspro/webos/api/workspace', [
            'desktop_items' => [
                [
                    'id' => 'webos-folder-Ab12Cd34',
                    'title' => '新建文件夹',
                    'path' => '/admin/cmspro/childrehab/hospitals',
                    'icon' => 'fa fa-folder',
                    'kind' => 'folder',
                    'parent_folder' => 'bad<id>!',
                    'x' => 0,
                    'y' => 0,
                ],
            ],
            'preferences' => [],
        ])->assertOk()
            ->assertJsonPath('data.desktop_items.0.path', '')
            ->assertJsonPath('data.desktop_items.0.parent_folder', '');

        // URL 链接仅放行 http(s)：非 http 地址拒绝
        $this->putJson('/admin/cmspro/webos/api/workspace', [
            'desktop_items' => [[
                'id' => 'webos-url-Ab12Cd34',
                'title' => '官网',
                'path' => 'ftp://www.example.com',
                'icon' => 'fa fa-globe',
                'kind' => 'url',
                'x' => 0,
                'y' => 0,
            ]],
            'preferences' => [],
        ])->assertUnprocessable()
            ->assertJsonPath('code', 40201);
    }

    /** 桌面右键「新建」（文件夹/桌面入口/URL 链接）+ 文件夹浮层 + 拖入收纳链路 */
    public function test_desktop_new_context_menu_folder_overlay_and_drag_into(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');
        $service = file_get_contents(dirname(__DIR__, 2) . '/Services/WorkspaceService.php');

        $this->assertIsString($script);
        // 右键菜单：移除「设置背景」，新增「新建」子菜单三动作
        $this->assertStringNotContainsString('设置背景', $script);
        $this->assertStringContainsString("['查看', 'fa-th-large', '', '', sizeChildren],", $script);
        $this->assertStringContainsString("['新建', 'fa-plus', '', '', newChildren],", $script);
        $this->assertStringContainsString("{ label: '文件夹', action: 'new-folder' },", $script);
        $this->assertStringContainsString("{ label: '桌面入口', action: 'new-entry' },", $script);
        $this->assertStringContainsString("{ label: 'URL 链接', action: 'new-url' }", $script);
        $this->assertStringContainsString("if (action === 'new-folder') {", $script);
        $this->assertStringContainsString('createDesktopFolder();', $script);
        $this->assertStringContainsString('openNewEntryDialog();', $script);
        $this->assertStringContainsString('openNewUrlDialog();', $script);
        $this->assertStringContainsString("if (action === 'folder-remove') {", $script);
        $this->assertStringContainsString('removeItemFromFolder(iconId);', $script);
        // 新建三实现：文件夹（随机 id + kind）、URL 弹窗（http(s) 校验 + kind）、桌面入口弹窗（复用可用菜单树）
        $this->assertStringContainsString('function randomEntryId(prefix)', $script);
        $this->assertMatchesRegularExpression("/id: randomEntryId\('webos-folder-'\)/", $script);
        $this->assertMatchesRegularExpression("/id: randomEntryId\('webos-url-'\)/", $script);
        $this->assertMatchesRegularExpression("/kind: 'folder'/", $script);
        $this->assertMatchesRegularExpression("/kind: 'url'/", $script);
        $this->assertStringContainsString('/^https?:\/\/[^\s]+$/i.test(url)', $script);
        // 桌面入口弹窗（2.1.30）：与入口管理树同构的树结构、分支默认收起、叶子点选即添加
        $this->assertStringContainsString('function pickNodeMarkup(node, depth)', $script);
        $this->assertStringContainsString("data-pick-branch-id=\"' + escapeHtml(node.key) + '\"", $script);
        $this->assertStringContainsString("var expanded = entryPickExpanded.has(node.key);", $script);
        $this->assertStringContainsString("layero.on('click', '[data-pick-branch-id]'", $script);
        $this->assertStringContainsString("layero.on('click', '[data-pick-entry-id]'", $script);
        $this->assertStringContainsString('var entryPickExpanded = new Set();', $script);
        $this->assertStringNotContainsString('flattenEntryTreeLeaves', $script);
        // 打开链路：文件夹走浮层分发；URL 链接按 http(s) 外链放行 iframe 窗口
        $this->assertStringContainsString("if (entry.kind === 'folder') {", $script);
        $this->assertMatchesRegularExpression("/if \(entry\.kind === 'url' && externalPath\(entry\.path\)\) \{ return true; \}/", $script);
        // 文件夹浮层：macOS 式锚定展开、子项网格、右键移回桌面、全局关闭链
        $this->assertStringContainsString('function openFolderOverlay(folder)', $script);
        $this->assertStringContainsString('function closeFolderOverlay(folderId)', $script);
        $this->assertStringContainsString('function refreshFolderOverlay(folderId)', $script);
        $this->assertStringContainsString('function folderChildren(folderId)', $script);
        $this->assertStringContainsString("['从文件夹移除', 'fa-external-link-square', 'folder-remove']", $script);
        // 点击浮层外关闭判定带 isConnected（2.1.49）：detached target（标题重命名 replaceWith 旧节点）不参与判定
        $this->assertStringContainsString("root.querySelector('.webos-folder-overlay') && event.target.isConnected && !event.target.closest('.webos-folder-overlay')", $script);
        $this->assertStringContainsString("event.target.closest('.webos-folder-item')", $script);
        $this->assertStringContainsString('state.folderOverlayId = folder.id;', $script);
        // 拖入文件夹：悬停判定（拖动穿透）+ 高亮 + 整批移入；拖动项含文件夹时禁止嵌套收纳
        $this->assertStringContainsString('function folderUnderPointer(clientX, clientY)', $script);
        $this->assertStringContainsString('function setFolderTarget(button)', $script);
        // 拖入修复（2.1.31）：松手判定用 pointerup 坐标（此前误用 pointerdown 闭包坐标）+ cleanup 前判定保持穿透
        $this->assertStringContainsString('function end(upEvent)', $script);
        $this->assertMatchesRegularExpression('/var dropFolderButton = moved \? folderUnderPointer\(upEvent\.clientX, upEvent\.clientY\) : null;/', $script);
        $this->assertStringNotContainsString('folderUnderPointer(event.clientX', $script);
        // 拖入打开中的浮层：folderOverlayUnderPointer 判定 + 悬停高亮 + 整批移入
        $this->assertStringContainsString('function folderOverlayUnderPointer(clientX, clientY)', $script);
        $this->assertStringContainsString("over.closest('.webos-folder-overlay')", $script);
        $this->assertStringContainsString('overlay.dataset.folderId || null', $script);
        // 悬停高亮修复（2.1.37）：folderOverlayUnderPointer 返回的是文件夹 id 而非 DOM 节点，
        // setOverlayTarget 须按 id 查节点并判空后再切类，否则对字符串取 classList 抛 TypeError
        $this->assertStringContainsString('function setOverlayTarget(folderId)', $script);
        $this->assertStringContainsString('var overlayTargetId = null;', $script);
        $this->assertStringContainsString('function overlayNodeById(folderId)', $script);
        $this->assertStringContainsString("overlayTargetId === folderId", $script);
        $this->assertStringContainsString('prevNode.classList.remove(\'is-folder-target\');', $script);
        $this->assertStringContainsString('nextNode.classList.add(\'is-folder-target\');', $script);
        $this->assertStringNotContainsString('overlayTarget.classList.', $script);
        $this->assertStringContainsString('setOverlayTarget(folderOverlayUnderPointer(moveEvent.clientX, moveEvent.clientY));', $script);
        $this->assertMatchesRegularExpression("/entry\.parent_folder = dropOverlayId;/", $script);
        // 拖出文件夹（2.1.33）：浮层子项拖拽三去向——其它文件夹 / 桌面空格 / 落回原浮层回弹
        $this->assertStringContainsString('function dragFolderItem(folder, item, button)', $script);
        $this->assertStringContainsString("overlay.querySelectorAll('.webos-folder-item').forEach", $script);
        $this->assertStringContainsString('dragFolderItem(folder, child, itemButton);', $script);
        // 拖出跟手修复（2.1.36）：拖影克隆节点挂 body 用 transform 跟随——浮层 backdrop-filter 会成为 fixed 后代的
        // 包含块（left/top 相对浮层而非视口计算，图标偏移不跟手），网格 overflow-y:auto 又会裁剪出界子项
        $this->assertStringContainsString('ghost = button.cloneNode(true);', $script);
        // 文件夹/URL 徽章固定背景色（2.1.51）：entryIconMarkup 按 kind 追加 is-folder-badge/is-url-badge 修饰类，
        // 文件夹橙 var(--webos-folder)、URL 绿 #16b88d（浏览器同款），不参与 nth-child 位置彩色轮换
        $this->assertStringContainsString("entry.kind === 'folder' ? ' is-folder-badge' : (entry.kind === 'url' ? ' is-url-badge' : '')", $script);
        $this->assertStringContainsString('.desktop-icon .desktop-icon-badge.is-folder-badge', $stylesheet);
        $this->assertMatchesRegularExpression('/\.desktop-icon \.desktop-icon-badge\.is-url-badge,\n\.webos-folder-overlay-grid \.desktop-icon-badge\.is-url-badge \{\n    background: #16b88d;/', $stylesheet);
        $this->assertStringContainsString("ghost.classList.add('webos-folder-drag-ghost');", $script);
        $this->assertStringContainsString('document.body.appendChild(ghost);', $script);
        $this->assertStringContainsString("ghost.style.transform = 'translate(' + dx + 'px, ' + dy + 'px)';", $script);
        $this->assertStringContainsString('ghost.remove();', $script);
        $this->assertStringContainsString('.webos-folder-drag-ghost {', $stylesheet);
        $this->assertStringNotContainsString("button.style.position = 'fixed';", $script);
        // 拖动图标盖过文件夹浮层（2.1.38）：is-dragging 提升 z-index 到 4000（浮层 3500），
        // 否则桌面图标拖动改原按钮 left/top，会被打开中的文件夹浮层盖住
        $this->assertMatchesRegularExpression('/\.desktop-icon\.is-dragging \{[^}]*z-index: 4000;/', $stylesheet);
        // 拖出图标尺寸修复（2.1.38）：ghost 挂 body 脱离 data-icon-size/浮层作用域，badge 尺寸/字号回退默认档、
        // 应用图片图标透明底与 img 约束失配（非 SVG 图标拖出变大），从浮层内原按钮读计算样式固化为内联样式
        $this->assertStringContainsString('getComputedStyle(sourceBadge)', $script);
        $this->assertStringContainsString('ghostBadge.style.fontSize = badgeStyle.fontSize;', $script);
        $this->assertStringContainsString("ghostBadge.classList.contains('is-app-icon')", $script);
        $this->assertStringContainsString("ghostImg.style.width = '100%';", $script);
        // 拖出判定改浮层矩形几何计算（2.1.34）
        $this->assertStringContainsString('var startRect = button.getBoundingClientRect();', $script);
        $this->assertStringContainsString("var overlayNow = root.querySelector('.webos-folder-overlay[data-folder-id=", $script);
        $this->assertStringContainsString('overlayNow.getBoundingClientRect()', $script);
        $this->assertStringNotContainsString('var droppedOut = !overOwn', $script);
        // 拖入修复（2.1.34）：folderOverlayUnderPointer 叠加浮层矩形几何兜底（elementFromPoint 在玻璃拟态层不可靠）
        $this->assertStringContainsString('var rect = overlay.getBoundingClientRect();', $script);
        $this->assertStringContainsString("root.querySelector('.webos-folder-overlay[data-folder-id]');", $script);
        // 图标设置弹窗 616px 宽（2.1.34）+ 桌面入口叶子图标 22px（2.1.34）
        $this->assertStringContainsString("area: ['616px', '460px'],", $script);
        $this->assertStringContainsString('.webos-entry-pick-item > i {', $stylesheet);
        $this->assertStringContainsString('width: 22px;', $stylesheet);
        // 「新建 → 桌面入口」弹窗分支行应用图片图标 22px（2.1.39）：入口管理树的 img 约束挂在
        // .entry-tree-toggle 祖先下弹窗内失配，应用 icon 接口图片按原始尺寸渲染爆框
        $this->assertStringContainsString('.webos-entry-pick-branch .entry-tree-app-icon.is-app-icon img {', $stylesheet);
        $this->assertStringContainsString('toast(\'已移出到桌面\');', $script);
        $this->assertStringContainsString('.webos-folder-item.is-dragging {', $stylesheet);
        // 文件夹图标设置（2.1.33）：右键菜单最下面 + 内置图标目录弹窗点选替换
        $this->assertStringContainsString('items.push([\'图标设置\', \'fa-picture-o\', \'folder-icon\']);', $script);
        $this->assertStringContainsString("if (action === 'folder-icon') {", $script);
        $this->assertStringContainsString('function openFolderIconDialog(folderId)', $script);
        // 文件夹右键「释放文件夹」（2.1.40）：仅有子项时显示，全部子项清 parent_folder 逐项取空格回桌面，
        // 桌面满时部分释放并提示余量；无子项点击提示，浮层开着时重绘
        $this->assertStringContainsString("items.push(['释放文件夹', 'fa-th-large', 'folder-release']);", $script);
        // URL 快捷方式右键「图标设置」（2.1.50）：菜单最下面提供，复用文件夹的内置图标目录弹窗（kind 放宽 folder|url）
        $this->assertStringContainsString("context.entry.kind === 'url') {\n            items.push(['图标设置', 'fa-picture-o', 'folder-icon']);", $script);
        $this->assertStringContainsString("(folder.kind !== 'folder' && folder.kind !== 'url')", $script);
        $this->assertStringContainsString("if (action === 'folder-release') {", $script);
        $this->assertStringContainsString('function releaseFolderItems(folderId) {', $script);
        $this->assertStringContainsString('delete child.parent_folder;', $script);
        $this->assertStringContainsString("toast('桌面没有空位，已释放 ' + released + ' 项，剩余 ' + (children.length - released) + ' 项留在文件夹', 'error');", $script);
        $this->assertStringContainsString('folder.icon = this.dataset.iconSlug;', $script);
        $this->assertStringContainsString('.webos-icon-grid {', $stylesheet);
        $this->assertStringContainsString('.desktop-icon-badge.is-svg-icon svg {', $stylesheet);
        // 图标目录内置资产：静态 JS（来自博客 IconCatalog 全量导出）+ 视图引入 + 资产版本纳入
        $catalogPath = dirname(__DIR__, 2) . '/Assets/js/webos-icon-catalog.js';
        $this->assertFileExists($catalogPath);
        $this->assertStringContainsString('window.WEBOS_ICON_CATALOG = ', file_get_contents($catalogPath));
        $catalogView = file_get_contents(dirname(__DIR__, 2) . '/Views/Admin/desktop/index.blade.php');
        $this->assertStringContainsString('webos-icon-catalog.js', $catalogView);
        $this->assertStringContainsString('js/webos-icon-catalog.js', file_get_contents(dirname(__DIR__, 2) . '/Controllers/Admin/WebosController.php'));
        $this->assertMatchesRegularExpression("/dragItems\.some\(function \(entry\) \{ return entry\.kind === 'folder'; \}\)/", $script);
        $this->assertMatchesRegularExpression("/entry\.parent_folder = targetFolder\.id;/", $script);
        $this->assertStringContainsString('refreshFolderOverlay(targetFolder.id);', $script);
        $this->assertMatchesRegularExpression("/toast\('已移入「' \+ targetFolder\.title \+ '」'\);/", $script);
        // 持久化：工作区保存透传 kind/parent_folder；后端白名单净化
        $this->assertStringContainsString("kind: item.kind || '',", $script);
        $this->assertStringContainsString('parent_folder: item.parent_folder || \'\',', $script);
        $this->assertStringContainsString("in_array(\$item['kind'] ?? '', ['folder', 'url'], true)", $service);
        $this->assertStringContainsString("'URL 链接必须是 http(s) 地址'", $service);
        // 可见口径：桌面原生项（folder/url）始终可见；文件夹子项不占桌面网格
        $this->assertMatchesRegularExpression("/desktopItem\.kind === 'folder' \|\| desktopItem\.kind === 'url'/", $script);
        $this->assertStringContainsString('return entryAssigned(item.id) && !item.parent_folder;', $script);
        // 删除文件夹：子项移回桌面列优先找空格（findFreeDesktopSlot 抽取共用）
        $this->assertStringContainsString('function findFreeDesktopSlot()', $script);
        // 放置空格避开打开中的文件夹浮层（2.1.41）：浮层 z-index 高于桌面图标，
        // 第二次拖出若落到浮层矩形后面会被完全遮住（视觉上「桌面没任何响应」，接口已请求）
        $this->assertStringContainsString("var overlay = root.querySelector('.webos-folder-overlay');", $script);
        $this->assertStringContainsString('var overlayRect = overlay.getBoundingClientRect();', $script);
        $this->assertStringContainsString('occupied.add(ovColumn + \':\' + ovRow);', $script);
        // 连续保存竞态加固（2.1.41）：仅最新一次保存的响应可覆盖 state，晚到的旧响应丢弃防回滚
        $this->assertStringContainsString('var saveSeq = ++workspaceSaveSeq;', $script);
        $this->assertStringContainsString('if (saveSeq !== workspaceSaveSeq) {', $script);
        // 保存响应引用稳定回填（2.1.45）：整体替换数组元素会让浮层拖拽闭包持有脱离 state 的旧对象，
        // 表现为「拖出第一个成功、第二个起不成功、右键刷新 state 重建后才又能拖出」的循环；
        // 按 id 匹配现有元素逐字段更新（响应缺失字段删除），元素引用保持稳定
        $this->assertStringContainsString('function applyWorkspaceData(workspace) {', $script);
        $this->assertStringContainsString('applyWorkspaceData(workspace);', $script);
        $this->assertStringContainsString('delete existing[key];', $script);
        $this->assertMatchesRegularExpression('/if \(removing && removing\.kind === \'folder\'\)\s*\{\s*state\.workspace\.desktop_items\.forEach/', $script);
        // 关闭链：Esc 与窗口失焦均收起文件夹浮层
        $this->assertMatchesRegularExpression("/closeDesktopContextMenu\(\);\s*[\r\n]+\s*closeFolderOverlay\(\);\s*[\r\n]+\s*closeActionDialog\(\);/", $script);
        $this->assertMatchesRegularExpression("/closeAppRowMenus\(\);\s*[\r\n]+\s*closeFolderOverlay\(\);\s*[\r\n]+\s*\}\);/", $script);

        $this->assertIsString($stylesheet);
        // 浮层玻璃拟态面板 + 子项网格 + 拖动穿透与悬停高亮
        $this->assertStringContainsString('.webos-folder-overlay {', $stylesheet);
        // 2.1.42/2.1.44：浮层宽度 328px → 428px → 438px（用户需求两轮加宽）
        $this->assertMatchesRegularExpression('/\.webos-folder-overlay \{[^}]*width: 438px;/', $stylesheet);
        $this->assertStringContainsString('.webos-folder-overlay-grid {', $stylesheet);
        $this->assertStringContainsString('.webos-folder-item {', $stylesheet);
        // 2.1.32：浮层右上角关闭按钮 + 子项图标复用桌面 badge/label 结构（修复图片图标爆框）
        $this->assertStringContainsString('data-folder-close aria-label="关闭"', $script);
        $this->assertStringContainsString("event.target.closest('[data-folder-close]')", $script);
        $this->assertStringContainsString("entryIconMarkup(findEntry(item.id) || item, 'desktop-icon-badge')\n                        + '<span class=\"desktop-icon-label\">'", $script);
        $this->assertStringNotContainsString('webos-folder-item-icon', $script);
        $this->assertStringNotContainsString('webos-folder-item span', $stylesheet);
        $this->assertStringContainsString('.webos-folder-overlay-close {', $stylesheet);
        $this->assertStringContainsString('.webos-folder-item .desktop-icon-label {', $stylesheet);
        $this->assertStringContainsString('.webos-folder-overlay .desktop-icon-badge.is-app-icon img,', $stylesheet);
        $this->assertStringContainsString('.webos-folder-overlay-empty {', $stylesheet);
        $this->assertStringContainsString('.webos-url-form {', $stylesheet);
        // 浮层标题点击原地重命名（2.1.48）：标题节点 data-folder-rename，Enter/失焦确认、Esc 取消；
        // 仅改 desktop_items[].title 与 renameDesktopIcon 同口径，确认后 renderDesktop + 静默保存 + 重建浮层
        $this->assertStringContainsString('data-folder-rename title="点击重命名"', $script);
        $this->assertStringContainsString("input.className = 'webos-folder-overlay-rename';", $script);
        $this->assertStringContainsString('function finishRename(cancel) {', $script);
        // 编辑态误关修复（2.1.49）：replaceWith 后 detached target 沿旧路径冒泡到 document，
        // closest 恒空被误判「浮层外」导致浮层被关、对已移走节点 remove 抛 NotFoundError；须判 isConnected
        $this->assertStringContainsString("root.querySelector('.webos-folder-overlay') && event.target.isConnected && !event.target.closest('.webos-folder-overlay')", $script);
        $this->assertMatchesRegularExpression('/\.webos-folder-overlay-title\s*\{[^}]*cursor: pointer;/', $stylesheet);
        $this->assertStringContainsString('.webos-folder-overlay-rename {', $stylesheet);
        $this->assertStringContainsString('.webos-entry-pick-list {', $stylesheet);
        $this->assertStringContainsString('.webos-entry-pick-branch {', $stylesheet);
        // 「选择桌面入口」弹窗菜单徽章独立 22px 版本（2.1.47）：基础 .start-app-item-icon 是开始菜单
        // 42px 口径不能动，弹窗内按 .webos-entry-pick-list 作用域独立声明，内部 <i> 字号 12px
        $this->assertMatchesRegularExpression('/\.webos-entry-pick-list \.start-app-item-icon \{[^}]*width: 22px;[^}]*border-radius: 4px;/', $stylesheet);
        $this->assertStringContainsString('.webos-entry-pick-list .start-app-item-icon i {', $stylesheet);
        $this->assertMatchesRegularExpression('/\.webos-entry-pick-list \.start-app-item-icon i \{[^}]*font-size: 12px;/', $stylesheet);
        $this->assertMatchesRegularExpression('/\.desktop-icon\.is-folder-target \.desktop-icon-badge\s*\{/', $stylesheet);
        $this->assertMatchesRegularExpression('/\.desktop-icon\.is-dragging\s*\{[^}]*pointer-events:\s*none;/', $stylesheet);
        // 拖入打开中的浮层被遮挡修复（2.1.46）：.desktop-icons 容器自身 z-index 会创建层叠上下文，
        // 把 is-dragging 按钮的 z-index:4000 关在容器内（整体按容器值比较），容器必须保持 auto
        $this->assertMatchesRegularExpression('/\.desktop-icons \{(?:(?!z-index)[^}])*\}/', $stylesheet);
        // 浮层定位宽度按实际渲染值测量（2.1.43）：2.1.42 CSS 加宽到 428px 后 JS 硬编码 328 未同步，
        // 右侧越界翻转判定偏窄，浮层实际铺展区域超出计算值，拖出落点被压在浮层下（视觉无响应）
        $this->assertStringContainsString('var overlayWidth = overlay.offsetWidth || 438;', $script);
        // 拖出松手在浮层内（回弹）时有提示（2.1.43）：此前该分支完全无声，用户无法分辨拖出结果
        $this->assertStringContainsString("toast('图标保留在文件夹内');", $script);
    }

    /** WebOS 自身菜单隐藏：安装挂载位置与入口树（入口管理/新建桌面入口）均不再出现「WebOS 管理桌面」 */
    public function test_webos_self_menu_hidden_from_mount_and_entry_trees(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');

        $this->assertIsString($script);
        // 自身菜单判定：app_id 或内置路径任一命中即隐藏
        $this->assertStringContainsString("function isWebosSelfMenu(menu)", $script);
        $this->assertStringContainsString("menu.app_id === 'cmspro.webos' || menu.path === '/admin/cmspro/webos'", $script);
        // 安装挂载位置（常规安装与上传安装共用 installMenuFieldsMarkup）：渲染 option 前过滤自身菜单
        $this->assertStringContainsString("(menuTrees[terminal] || []).filter(function (menu) {", $script);
        $this->assertStringContainsString('return !isWebosSelfMenu(menu);', $script);
        // 入口树（入口管理可用菜单 + 新建桌面入口候选）：walk 聚合时跳过 WebOS 自身菜单
        $this->assertStringContainsString("if (appId === 'cmspro.webos') {", $script);
    }

    /** 应用更新「忽略」按钮：忽略偏好（app_id@版本号）持久化往返 + 前端过滤口径与净化链路 */
    public function test_workspace_api_persists_ignored_updates(): void
    {
        $this->actingAdmin();

        // 非法形态（bad key!）被服务层过滤，重复项去重，合法项保留
        $this->putJson('/admin/cmspro/webos/api/workspace', [
            'preferences' => [
                'ignored_updates' => ['cmspro.webos@2.1.29', 'demo-app@1.2.3', 'demo-app@1.2.3', 'bad key!', 'ok.app-x@10.0.0-rc1'],
            ],
        ])->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.preferences.ignored_updates', ['cmspro.webos@2.1.29', 'demo-app@1.2.3', 'ok.app-x@10.0.0-rc1']);

        // 再次读取验证持久化
        $this->getJson('/admin/cmspro/webos/api/workspace')
            ->assertOk()
            ->assertJsonPath('data.preferences.ignored_updates', ['cmspro.webos@2.1.29', 'demo-app@1.2.3', 'ok.app-x@10.0.0-rc1']);

        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $service = file_get_contents(dirname(__DIR__, 2) . '/Services/WorkspaceService.php');
        $controller = file_get_contents(dirname(__DIR__, 2) . '/Controllers/Admin/WebosController.php');

        $this->assertIsString($script);
        $this->assertIsString($service);
        $this->assertIsString($controller);
        // 前端：忽略键 app_id@latest_version（只忽略本次版本，发布更新版本后重新提示）
        $this->assertStringContainsString('function sanitizeIgnoredUpdates(list)', $script);
        $this->assertStringContainsString("/^[A-Za-z0-9_.-]{1,100}@[A-Za-z0-9_.-]{1,64}$/", $script);
        $this->assertStringContainsString('function updateIgnoreKey(app)', $script);
        $this->assertStringContainsString("(app.app_id || '') + '@' + (app.latest_version || app.version || '')", $script);
        $this->assertStringContainsString('function visibleUpdateApps()', $script);
        $this->assertStringContainsString('function ignoreUpdateApp(appId)', $script);
        // 更新卡片：更新按钮下方忽略按钮（容器纵向排列天然满足「下面」），点击委托分发
        $this->assertStringContainsString('data-ignore-update-id="', $script);
        // 更新列表版本号升级指向（2.1.52）：版本 xx => xxx（当前版本 → 最新版本）；更新来源详情补「最新版本」行
        $this->assertStringContainsString("version = (app.version || '-') + ' => ' + (app.latest_version || '-');", $script);
        $this->assertStringContainsString("items.push(['最新版本', 'v' + escapeHtml(app.latest_version || '-')]);", $script);
        $this->assertStringContainsString('title="忽略本次版本更新，应用发布更新版本后会重新提示"', $script);
        $this->assertStringContainsString(">忽略</button>", $script);
        $this->assertStringContainsString("event.target.closest('[data-ignore-update-id]')", $script);
        $this->assertStringContainsString('ignoreUpdateApp(ignoreUpdate.dataset.ignoreUpdateId);', $script);
        // 角标/状态栏/更新列表/WebOS 自身更新提示统一走可见口径（忽略后不计不显示）；9 处含函数定义本身
        $this->assertSame(9, substr_count($script, 'visibleUpdateApps()'));
        $this->assertStringNotContainsString("renderApplicationCards(state.updateApps, 'updates')", $script);
        $this->assertStringNotContainsString("state.updateApps.find(function (app) { return app.app_id === 'cmspro.webos'; })", $script);
        // 三层同步：state 默认偏好 / normalizePreferences / 服务层 DEFAULT + 净化 + 验证规则
        $this->assertStringContainsString('ignored_updates: []', $script);
        $this->assertStringContainsString("'ignored_updates' => [],", $service);
        $this->assertStringContainsString('$this->sanitizeIgnoredUpdates($preferences[\'ignored_updates\'] ?? [])', $service);
        $this->assertStringContainsString("'preferences.ignored_updates' => ['sometimes', 'array', 'max:200'],", $controller);
        $this->assertStringContainsString("'preferences.ignored_updates.*' => ['string', 'max:180'],", $controller);
    }
}
