<?php

namespace App\Apps\CmsproWebos\Tests\Feature;

use App\Apps\CmsproWebos\Tests\WebosTestCase;
use Illuminate\Support\Facades\DB;

/**
 * OS 设置「界面主题」Tab（2.1.136/2.1.144）：ui_theme 内置主题（default=CmsPro 经典、
 * win11=Win11 浅色）与自定义主题库（ui_theme_customs/ui_theme_custom_id，8 项细节覆盖），
 * CSS 侧以 .webos-desktop[data-ui-theme="win11"] 变量块 + JS 内联覆盖变量实现换肤与细节微调。
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

    /** 前端接线：Tab 定义、主题面板（选择卡片/自定义库/细节调整/导入导出）、CSS 主题块 */
    public function test_ui_theme_frontend_wiring(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');

        // OS 设置 Tab 定义与面板渲染分支
        $this->assertStringContainsString("['theme', 'fa-paint-brush', '界面主题']", $script);
        $this->assertStringContainsString("osSettingsTab === 'theme'", $script);
        $this->assertStringContainsString('function themeSectionMarkup(', $script);
        // 内置主题选择卡片（2.1.144 重构：data-theme-select 驱动，保存经 selectBuiltinTheme）
        $this->assertStringContainsString("themeCardMarkup('win11', 'fa-windows', 'Win11 浅色'", $script);
        $this->assertStringContainsString("closest('[data-theme-select]')", $script);
        $this->assertStringContainsString('function selectBuiltinTheme(', $script);
        // 自定义主题（2.1.144）：选择/删除/导出/新建/保存/导入全链路函数与委托
        $this->assertStringContainsString('data-theme-select-custom', $script);
        $this->assertStringContainsString('data-theme-new', $script);
        $this->assertStringContainsString('data-theme-save-current', $script);
        $this->assertStringContainsString('data-theme-export-current', $script);
        $this->assertStringContainsString('data-theme-import', $script);
        $this->assertStringContainsString("closest('[data-theme-export]')", $script);
        $this->assertStringContainsString('function createThemeFromCurrent(', $script);
        $this->assertStringContainsString('function importThemeFile(', $script);
        $this->assertStringContainsString("payload.type !== 'cmspro-webos-theme'", $script);
        // 恢复默认（2.1.147）：细节调整标题行右侧按钮，清草稿+清启用主题覆盖值
        $this->assertStringContainsString("closest('[data-theme-reset]')", $script);
        $this->assertStringContainsString('function resetThemeTune(', $script);
        // 细节调整（2.1.144）：8 项字段定义、微调草稿预览与 CSS 变量写入
        $this->assertStringContainsString("{ key: 'accent', label: '主题强调色', type: 'color' }", $script);
        $this->assertStringContainsString("{ key: 'taskbar_height', label: '任务栏高度', type: 'range', min: 40, max: 64, step: 1, unit: 'px' }", $script);
        $this->assertStringContainsString("closest('[data-theme-tune]')", $script);
        $this->assertStringContainsString('function applyThemeTune(', $script);
        $this->assertStringContainsString("root.style.setProperty('--webos-wallpaper-dim'", $script);
        $this->assertStringContainsString('function desktopIconSize(', $script);
        $this->assertStringContainsString('Math.round(size.cellW * scale)', $script);
        // 桌面容器 data 属性同步（2.1.148 起由 applyThemeTune 统一驱动，切换主题即时生效）
        $this->assertStringContainsString("root.dataset.uiTheme = resolved.base;", $script);

        // CSS：Win11 浅色主题变量覆盖块
        $this->assertStringContainsString('.webos-desktop[data-ui-theme="win11"] {', $css);
        $this->assertStringContainsString('--webos-primary: #005fb8;', $css);
        $this->assertStringContainsString('--webos-taskbar: rgba(243, 243, 243, 0.88);', $css);
        // 浅色任务栏上任务栏文字必须反转，否则白字不可读
        $this->assertStringContainsString('.webos-desktop[data-ui-theme="win11"] .taskbar-button,', $css);

        // 2.1.142：通知角标间隙圈变量化——默认深蓝、Win11 浅色跟随浅任务栏（原硬编码深蓝在浅任务栏上呈黑圈）
        $this->assertStringContainsString('border: 2px solid var(--webos-badge-ring);', $css);
        $this->assertStringContainsString('--webos-badge-ring: #153b5d;', $css);
        $this->assertStringContainsString('--webos-badge-ring: #f3f3f3;', $css);

        // 2.1.144：细节调整样式与壁纸暗度遮罩
        $this->assertStringContainsString('.webos-wallpaper-dim {', $css);
        $this->assertStringContainsString('.theme-tune-row {', $css);
        $this->assertStringContainsString('.theme-custom-card {', $css);
        $this->assertStringContainsString('.settings-btn {', $css);
        // 2.1.147：恢复默认按钮推到标题行最右
        $this->assertStringContainsString('.settings-card-title .settings-card-action {', $css);
        $this->assertStringContainsString('font-size: var(--webos-font-size, 14px);', $css);
    }

    /** 自定义主题库 roundtrip（2.1.144）：合法条目保存生效；非法覆盖值净化、未声明键丢弃 */
    public function test_ui_theme_customs_roundtrip(): void
    {
        $admin = $this->actingAdmin();

        $theme = [
            'id' => 't' . bin2hex(random_bytes(4)),
            'name' => '我的主题',
            'base' => 'win11',
            'overrides' => [
                'accent' => '#ABC123',
                'taskbar_height' => 58,
                'window_radius' => 20, // 越界 clamp 到 16
                'shadow' => 30,
                'hack_key' => 'evil', // 未声明键必须丢弃
            ],
        ];

        $this->putJson('/admin/cmspro/webos/api/workspace', [
            'preferences' => ['ui_theme_customs' => [$theme], 'ui_theme_custom_id' => $theme['id']],
        ])->assertOk()
            ->assertJsonPath('data.preferences.ui_theme_custom_id', $theme['id']);

        $row = DB::table('app_cmspro_webos_workspaces')->where('admin_user_id', $admin->id)->first();
        $preferences = json_decode((string) $row->preferences, true);
        $customs = $preferences['ui_theme_customs'] ?? [];
        $this->assertCount(1, $customs);
        $this->assertSame('我的主题', $customs[0]['name'] ?? '');
        $this->assertSame('win11', $customs[0]['base'] ?? '');
        $overrides = $customs[0]['overrides'] ?? [];
        $this->assertSame('#abc123', $overrides['accent'] ?? '');
        $this->assertSame(58, $overrides['taskbar_height'] ?? 0);
        $this->assertSame(16, $overrides['window_radius'] ?? 0);
        $this->assertSame(30, $overrides['shadow'] ?? 0);
        $this->assertArrayNotHasKey('hack_key', $overrides);

        // 启用 ID 不在库中（删除主题后遗留）→ 回退空值；越界 ui_theme_customs 数量在验证层被拒（422）
        $this->putJson('/admin/cmspro/webos/api/workspace', [
            'preferences' => ['ui_theme_customs' => [], 'ui_theme_custom_id' => 'ghost-id'],
        ])->assertOk()
            ->assertJsonPath('data.preferences.ui_theme_custom_id', '');
    }

    /** 切回内置主题（2.1.145）：空字符串 custom_id 经 ConvertEmptyStringsToNull 转 null 后仍可保存成功 */
    public function test_ui_theme_custom_id_empty_string_passes_validation(): void
    {
        $this->actingAdmin();

        // 切换到内置主题：前端 selectBuiltinTheme 发送 ui_theme_custom_id=''（中间件转 null）
        $this->putJson('/admin/cmspro/webos/api/workspace', [
            'preferences' => ['ui_theme' => 'win11', 'ui_theme_custom_id' => ''],
        ])->assertOk()
            ->assertJsonPath('data.preferences.ui_theme', 'win11')
            ->assertJsonPath('data.preferences.ui_theme_custom_id', '');
    }

    /** 自定义主题数量上限：超过 20 条在验证层被拒（422），不足上限的合法保存不受影响 */
    public function test_ui_theme_customs_limit(): void
    {
        $this->actingAdmin();

        $themes = [];
        for ($i = 0; $i < 21; $i++) {
            $themes[] = [
                'id' => 'tcustom' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'name' => '主题 ' . $i,
                'base' => 'default',
                'overrides' => [],
            ];
        }

        $this->putJson('/admin/cmspro/webos/api/workspace', [
            'preferences' => ['ui_theme_customs' => $themes],
        ])->assertStatus(422);
    }
}
