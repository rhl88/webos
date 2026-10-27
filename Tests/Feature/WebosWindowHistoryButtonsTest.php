<?php

namespace App\Apps\CmsproWebos\Tests\Feature;

use App\Apps\CmsproWebos\Tests\WebosTestCase;

/**
 * 应用窗口标题栏前进/后退按钮（2.1.105）：应用窗口刷新按钮左侧新增后退「<」、右侧新增前进「>」，
 * 用于操作 iframe 内页面的浏览历史。按钮对全部普通应用窗口渲染（含未开启多选项卡的窗口），
 * 跨域外链与组件页面因父页无法访问其内部历史，直接隐藏（同源策略限制）。
 */
class WebosWindowHistoryButtonsTest extends WebosTestCase
{
    private function script(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
    }

    /** 按钮渲染位置：后退在刷新按钮左侧、前进在刷新按钮右侧（同处品牌区右侧） */
    public function test_history_buttons_flank_refresh_button(): void
    {
        $script = $this->script();
        $back = strpos($script, 'data-window-action="history-back"');
        $refresh = strpos($script, 'window-control window-refresh');
        $forward = strpos($script, 'data-window-action="history-forward"');

        $this->assertNotFalse($back);
        $this->assertNotFalse($refresh);
        $this->assertNotFalse($forward);
        $this->assertTrue($back < $refresh && $refresh < $forward, '按钮顺序应为 后退 → 刷新 → 前进');
    }

    /** 点击接线：窗口动作委托分发到 history-back / history-forward */
    public function test_history_buttons_dispatch_window_actions(): void
    {
        $script = $this->script();
        $this->assertStringContainsString("windowAction.dataset.windowAction === 'history-back'", $script);
        $this->assertStringContainsString("windowAction.dataset.windowAction === 'history-forward'", $script);
        $this->assertStringContainsString('stepWindowHistory(key,', $script);
    }

    /** 历史操作：操作激活页面 iframe 的 contentWindow.history（同源页面） */
    public function test_step_uses_active_tab_frame_history(): void
    {
        $script = $this->script();
        $this->assertStringContainsString('function stepWindowHistory(key, direction)', $script);
        $this->assertStringContainsString('frame.contentWindow.history.back();', $script);
        $this->assertStringContainsString('frame.contentWindow.history.forward();', $script);
    }

    /** 显隐同步：仅站内同源 iframe 页面显示按钮，跨域与组件页面隐藏 */
    public function test_buttons_hidden_for_cross_origin_and_component_pages(): void
    {
        $script = $this->script();
        $this->assertStringContainsString('function syncWindowHistoryButtons(windowState, entry)', $script);
        $this->assertStringContainsString('function windowHistoryNavigable(entry)', $script);
        $this->assertStringContainsString('function isSameOriginUrl(path)', $script);
        $this->assertStringContainsString('button.hidden = !navigable;', $script);
        // 组件模式（无 iframe）排除
        $this->assertStringContainsString("entry.openType === '_component' || entry.open_type === '_component'", $script);
        // 显隐同步接入渲染链路与选项卡关闭链路（定义 1 处 + 调用 ≥2 处）
        $this->assertGreaterThanOrEqual(3, substr_count($script, 'syncWindowHistoryButtons('));
    }

    /** 样式：按钮间距与 [hidden] 强制隐藏（flex 布局下 display 覆盖） */
    public function test_css_hides_buttons_when_hidden_attr_present(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');
        $this->assertStringContainsString('.window-history {', $css);
        $this->assertStringContainsString('.window-history[hidden] {', $css);
    }

    /** 样式：前进/后退/刷新三按钮采用紧凑宽度（覆盖 .window-control 的 48px）靠得更近，
     *  且基础 .window-control 宽度保持 48px 不变，最小化/最大化/关闭等按钮不受影响 */
    public function test_compact_width_applies_only_to_refresh_and_history(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');
        $this->assertMatchesRegularExpression(
            '/\.window-control\.window-refresh,\s*\.window-control\.window-history\s*\{[^}]*width:\s*20px;/',
            $css,
            '前进/后退/刷新按钮应以更窄的 20px 宽度呈现'
        );
        $this->assertMatchesRegularExpression(
            '/\.window-control\s*\{[^}]*width:\s*48px;/',
            $css,
            '基础窗口按钮宽度应保持 48px，未被导航按钮的紧凑样式影响'
        );
    }
}