<?php

namespace App\Apps\CmsproWebos\Tests\Feature;

use App\Apps\CmsproWebos\Tests\WebosTestCase;
use App\Models\AppModel;

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

        $this->get('/admin/cmspro/webos')
            ->assertOk()
            ->assertSee('CMSPRO WebOS')
            ->assertSee('webos-desktop')
            ->assertSee('任务栏位置')
            ->assertSee('data-taskbar-position="bottom"', false);
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
                'title' => '用户管理',
                'path' => '/admin/user',
                'icon' => 'fa fa-users',
                'group_title' => '系统管理',
                'x' => 1,
                'y' => 2,
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
            ->assertJsonPath('data.preferences.taskbar_position', 'left')
            ->assertJsonPath('data.preferences.usage_stats.folder:13.count', 4);
    }

    public function test_workspace_api_rejects_an_unknown_taskbar_position(): void
    {
        $this->actingAdmin();

        $this->putJson('/admin/cmspro/webos/api/workspace', [
            'preferences' => ['taskbar_position' => 'diagonal'],
        ])->assertUnprocessable()
            ->assertJsonPath('code', 40201);
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
        $this->assertStringContainsString("entry.folder_id === target.folder_id", $script);
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
    }

    public function test_catalog_exposes_app_root_icon_url_and_manifest_fallback(): void
    {
        $this->actingAdmin();
        AppModel::create([
            'app_id' => 'cmspro.webos',
            'name' => 'WebOS 管理桌面',
            'version' => '1.3.3',
            'icon' => 'fa fa-stale',
            'path' => 'app/Apps/CmsproWebos',
            'status' => 1,
            'is_system' => false,
            'manifest' => ['icon' => 'fa fa-stale'],
        ]);

        $this->getJson('/admin/cmspro/webos/api/catalog')
            ->assertOk()
            ->assertJsonPath('data.applications.0.icon_url', url('/api/app/cmspro.webos/icon'))
            ->assertJsonPath('data.applications.0.manifest_icon', 'fa fa-desktop')
            ->assertJsonPath('data.applications.0.is_system', false);
    }

    public function test_application_cards_fall_back_to_manifest_icon(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');

        $this->assertIsString($script);
        $this->assertStringContainsString('data-app-icon-primary', $script);
        $this->assertStringContainsString('data-app-icon-fallback', $script);
        $this->assertStringContainsString('data-app-icon-fallback hidden data-src', $script);
        $this->assertStringContainsString('fallback.src = fallback.dataset.src;', $script);
        $this->assertStringContainsString('app.manifest_icon || app.icon', $script);
    }

    public function test_application_window_sidebar_can_collapse_and_hides_single_menu(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');

        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('siblings.length <= 1', $script);
        $this->assertStringContainsString('data-window-action="toggle-sidebar"', $script);
        $this->assertStringContainsString('function toggleWindowSidebar', $script);
        $this->assertStringContainsString('is-sidebar-collapsed', $script);
        $this->assertStringContainsString('.app-window.is-sidebar-collapsed .window-sidebar', $stylesheet);
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

    public function test_install_dialog_uses_terminal_aware_menu_mounting(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $view = file_get_contents(dirname(__DIR__, 2) . '/Views/Admin/desktop/index.blade.php');

        $this->assertIsString($script);
        $this->assertIsString($view);
        $this->assertStringContainsString('/menu-terminals', $script);
        $this->assertStringContainsString('/prepare', $script);
        $this->assertStringContainsString('terminal_type=', $script);
        $this->assertStringContainsString('parent_menu_ids', $script);
        $this->assertStringContainsString('id="install-menu-parents"', $view);
    }

    public function test_catalog_exposes_config_flag_and_market_base_url(): void
    {
        $this->actingAdmin();
        config(['apps.market.api_url' => 'https://v5.cmspro.cn/']);
        AppModel::create([
            'app_id' => 'cmspro.webos',
            'name' => 'WebOS 管理桌面',
            'version' => '1.4.0',
            'icon' => 'fa fa-stale',
            'path' => 'app/Apps/CmsproWebos',
            'status' => 1,
            'is_system' => false,
            'manifest' => [
                'icon' => 'fa fa-desktop',
                'config_groups' => [
                    ['title' => '基础配置', 'name' => 'basic'],
                ],
            ],
        ]);

        $this->getJson('/admin/cmspro/webos/api/catalog')
            ->assertOk()
            ->assertJsonPath('data.applications.0.has_config', true);

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
        $this->assertStringContainsString('id="app-package-input"', $view);
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
        $this->assertStringContainsString("'/docs/content?path='", $script);
        $this->assertStringContainsString("'/config'", $script);
        $this->assertStringContainsString("'/export'", $script);
        $this->assertStringContainsString("'/uninstall'", $script);
        $this->assertStringContainsString("'/enable'", $script);
        $this->assertStringContainsString("'/disable'", $script);
        $this->assertStringContainsString('data-app-action="', $script);
        $this->assertStringContainsString('data-app-action="manual-upgrade" data-app-id="', $script);
        $this->assertStringContainsString('data-app-action="export" data-app-id="', $script);
        $this->assertStringContainsString('>手动</button>', $script);
        $this->assertStringContainsString('>导出</button>', $script);
        $this->assertStringContainsString("['manage-entry', 'fa-th', '管理入口']", $script);
        $this->assertStringContainsString("if (action === 'manage-entry')", $script);
        $this->assertStringNotContainsString("'手动升级'", $script);
        $this->assertStringContainsString("['backup', 'fa-archive', '备份']", $script);
        $this->assertStringContainsString("['docs', 'fa-book', '文档']", $script);
        $this->assertStringContainsString("['settings', 'fa-cog', '设置']", $script);
        $this->assertStringContainsString("['uninstall', 'fa-trash-o', '卸载', 'danger']", $script);
        $this->assertStringContainsString('请先禁用应用', $script);
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
}
