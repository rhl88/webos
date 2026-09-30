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
            ->assertSee('个人设置')
            ->assertSee('修改密码')
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
                'app_id' => 'cmspro.demo',
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
            ->assertJsonPath('data.desktop_items.0.app_id', 'cmspro.demo')
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
        $this->assertStringContainsString('countNavLeaves(navTree) <= 1', $script);
        $this->assertStringContainsString('data-window-action="toggle-sidebar"', $script);
        $this->assertStringContainsString('function toggleWindowSidebar', $script);
        $this->assertStringContainsString('is-sidebar-collapsed', $script);
        $this->assertStringContainsString('.app-window.is-sidebar-collapsed .window-sidebar', $stylesheet);
        $this->assertStringContainsString('flex: 0 0 180px;', $stylesheet);
        $this->assertStringNotContainsString('flex-basis: 176px;', $stylesheet);
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
        $this->assertStringContainsString('<div class="app-grid" data-market-grid></div>', $script);
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
        $this->assertMatchesRegularExpression("/toast\('应用更新完成'\);\s*\n\s*dropUpdatedApp\(appId\);/", $script);

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

        // “窗口默认尺寸”只作用于应用窗口；WebOS 内置窗口固定使用出厂默认百分比
        $this->assertStringContainsString("var BUILTIN_WINDOW_KEYS = ['webos-app-center', 'webos-settings'];", $script);
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

    /** 初始数据：首次进入桌面只固定「应用中心」，不再自动塞系统菜单 */
    public function test_initial_desktop_pins_only_application_center(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');

        $this->assertIsString($script);
        $this->assertStringContainsString('var selected = [applicationCenterEntry()];', $script);
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
        // 树的应用节点存真实应用引用并复用 applicationIconMarkup（含 icon_url → manifest_icon 兜底链）
        $this->assertStringContainsString('application ? application.name : appId,', $script);
        $this->assertStringContainsString("applicationIconMarkup(node.application, 'entry-tree-app-icon is-app-icon')", $script);
        // 桌面入口行与可用菜单叶子/分支复用 entryIconMarkup（应用项自动带 is-app-icon 图片链）
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
        // 树的应用节点也走 is-app-icon 图片降级链
        $this->assertStringContainsString("applicationIconMarkup(node.application, 'entry-tree-app-icon is-app-icon')", $script);
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
        $this->assertStringContainsString('title="\' + escapeHtml(appName)', $script);
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
}
