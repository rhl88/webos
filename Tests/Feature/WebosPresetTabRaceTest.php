<?php

namespace App\Apps\CmsproWebos\Tests\Feature;

use App\Apps\CmsproWebos\Tests\WebosTestCase;

/**
 * 预设编辑会话快速切 Tab 竞态修复（2.1.103）：OS 设置「管理桌面/用户桌面」Tab 连续快速
 * 切换时，多个在途的预设读取响应交错执行「拍快照→接管桌面数据源」，迟到的响应把预设数据
 * 拍进 presetSnapshot，leavePresetSession 还原污染快照后真实桌面被预设数据渲染——预设项
 * 无坐标导致图标全部堆叠到左上角，进一步交错时 entryAssigned 过滤使图标全部消失（刷新后
 * 恢复，污染仅内存未落库）。修复口径：会话请求序号守卫，与「用户桌面/管理桌面」搜索查看
 * 的响应序号丢弃过期响应模式一致。
 */
class WebosPresetTabRaceTest extends WebosTestCase
{
    /** 序号守卫齐备：进入登记序号、响应校验过期即丢（then/catch 各一处）、退出作废在途请求 */
    public function test_preset_session_requests_are_guarded_by_sequence(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $this->assertIsString($script);

        // 请求序号变量：每次进入/退出会话递增，响应按序号判定是否过期
        $this->assertStringContainsString('var presetSessionRequestSeq = 0;', $script);
        // 进入会话登记本次请求序号
        $this->assertStringContainsString('var requestSeq = ++presetSessionRequestSeq;', $script);
        // 响应返回时校验：被后续进入/退出取代则直接丢弃，不再接管数据源或拍摄快照（then 与 catch 各一处）
        $this->assertSame(2, substr_count($script, 'if (requestSeq !== presetSessionRequestSeq) {'));
        // 退出会话时作废全部在途请求（须置于幂等返回之前，未在会话中也能作废在途请求）
        $this->assertStringContainsString('presetSessionRequestSeq += 1;', $script);
    }

    /** 切 Tab 接线：跨类切换与切离预设 Tab 均无条件退出会话（幂等），迟到的预设响应不再污染真实桌面 */
    public function test_switching_tabs_always_leaves_preset_session(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $this->assertIsString($script);

        // 跨 Tab 切换（user ↔ admin）：退出旧会话不再以 presetEditing 为前置条件
        // （未在会话中时 leavePresetSession 仅作废在途请求），随后进入新会话
        $this->assertStringContainsString(
            "leavePresetSession();\n                enterPresetSession(presetSourceType);",
            $script
        );
        $this->assertStringNotContainsString(
            "if (state.presetEditing) {\n                    leavePresetSession();\n                }",
            $script
        );
        // 切离预设 Tab（API 在途尚未返回时 presetEditing 仍为 false）：同样无条件退出，
        // 不得残留 else if (state.presetEditing) 条件导致在途响应漏网
        $this->assertStringNotContainsString('} else if (state.presetEditing) {', $script);
    }
}
