<?php

namespace App\Apps\CmsproWebos\Tests\Feature;

use App\Apps\CmsproWebos\Tests\WebosTestCase;
use Illuminate\Support\Facades\DB;

/**
 * OS 设置「界面主题」Tab（2.1.136）：新增 ui_theme 偏好（default=CmsPro 经典、win11=Win11 浅色），
 * 前端经 data-set-ui-theme 选择卡片走 setWebosPreference 通道保存，CSS 侧以
 * .webos-desktop[data-ui-theme="win11"] 覆盖 --webos-* 变量实现变量级换肤。
 */
class WebosUiThemeTest extends WebosTestCase
{
    /** ui_theme 进入偏好白名单：合法值保存生效，非法值在验证层被拒且不落库 */
    public function test_ui_theme_preference_roundtrip(): void
    {
        $admin = $this->actingAdmin();

        $this->putJson('/admin/cmspro/webos/api/workspace', [
            'preferences' => ['ui_theme' => 'win11'],
        ])->assertOk()
            ->assertJsonPath('data.preferences.ui_theme', 'win11');

        $row = DB::table('app_cmspro_webos_workspaces')->where('admin_user_id', $admin->id)->first();
        $preferences = json_decode((string) $row->preferences, true);
        $this->assertSame('win11', $preferences['ui_theme'] ?? '');

        // 非法值在验证层被拒（与 wallpaper 的 in: 规则同范式），且不落库（仍为上次保存的 win11）
        $this->putJson('/admin/cmspro/webos/api/workspace', [
            'preferences' => ['ui_theme' => 'dark'],
        ])->assertStatus(422);

        $row = DB::table('app_cmspro_webos_workspaces')->where('admin_user_id', $admin->id)->first();
        $preferences = json_decode((string) $row->preferences, true);
        $this->assertSame('win11', $preferences['ui_theme'] ?? '');
    }

    /** 未设置过时返回默认主题 default */
    public function test_ui_theme_defaults_to_default(): void
    {
        $this->actingAdmin();

        $this->getJson('/admin/cmspro/webos/api/workspace')
            ->assertOk()
            ->assertJsonPath('data.preferences.ui_theme', 'default');
    }

    /** 前端接线：Tab 定义、主题面板、选择卡片委托、data 属性同步与 CSS 主题块 */
    public function test_ui_theme_frontend_wiring(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');

        // OS 设置 Tab 定义与面板渲染分支
        $this->assertStringContainsString("['theme', 'fa-paint-brush', '界面主题']", $script);
        $this->assertStringContainsString("osSettingsTab === 'theme'", $script);
        $this->assertStringContainsString('function themeSectionMarkup(', $script);
        // 选择卡片与保存通道（与壁纸风格同一 settingsChoice/setWebosPreference 范式）
        $this->assertStringContainsString("settingsChoice('set-ui-theme', 'win11', 'fa-windows', 'Win11 浅色'", $script);
        $this->assertStringContainsString("closest('[data-set-ui-theme]')", $script);
        $this->assertStringContainsString("setWebosPreference('ui_theme', uiTheme.dataset.setUiTheme", $script);
        // 桌面容器 data 属性同步（非法值回退 default）
        $this->assertStringContainsString("root.dataset.uiTheme = preferences.ui_theme === 'win11' ? 'win11' : 'default';", $script);

        // CSS：Win11 浅色主题变量覆盖块
        $this->assertStringContainsString('.webos-desktop[data-ui-theme="win11"] {', $css);
        $this->assertStringContainsString('--webos-primary: #005fb8;', $css);
        $this->assertStringContainsString('--webos-taskbar: rgba(243, 243, 243, 0.88);', $css);
        // 浅色任务栏上任务栏文字必须反转，否则白字不可读
        $this->assertStringContainsString('.webos-desktop[data-ui-theme="win11"] .taskbar-button,', $css);
    }
}
