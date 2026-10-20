<?php

namespace App\Apps\CmsproWebos\Tests\User;

use App\Apps\CmsproWebos\Services\WorkspaceService;
use App\Apps\CmsproWebos\Tests\WebosTestCase;

/**
 * 用户端 WebOS 桌面（2.1.80）：入口分流、终端隔离与 API 能力验证。
 */
class WebosUserTerminalTest extends WebosTestCase
{
    /** 未登录访问桌面入口：302 到框架用户登录页并携带回跳地址 */
    public function test_guest_index_redirects_to_user_login(): void
    {
        $this->get('/user/cmspro/webos')
            ->assertRedirect('/user/login?redirect=%2Fuser%2Fcmspro%2Fwebos');
    }

    /** 登录用户访问桌面入口：渲染桌面视图，runtime.terminal 标识为 user */
    public function test_user_index_renders_desktop_with_user_terminal(): void
    {
        $this->actingUser();

        $this->get('/user/cmspro/webos')
            ->assertOk()
            ->assertViewHas('webosRuntime.terminal', 'user');
    }

    /** 用户端 API 必须登录：未认证请求被拒绝（401 JSON 或 302 重定向，取决于框架处理器） */
    public function test_user_workspace_api_requires_auth(): void
    {
        $response = $this->getJson('/user/cmspro/webos/api/workspace');

        $this->assertContains($response->status(), [302, 401]);
    }

    /** 同一账号 ID 在 admin/user 两端各自持有独立工作区，互不串数据 */
    public function test_user_and_admin_workspaces_are_isolated(): void
    {
        $service = app(WorkspaceService::class);

        $adminWorkspace = $service->getFor('admin', 777);
        $userWorkspace = $service->getFor('user', 777);

        $this->assertSame('admin', $adminWorkspace->terminal);
        $this->assertSame('user', $userWorkspace->terminal);
        $this->assertNotSame($adminWorkspace->id, $userWorkspace->id);

        // 后台端改偏好不影响用户端
        $service->saveFor('admin', 777, ['preferences' => ['wallpaper' => 'deep-blue']]);

        $this->assertSame('webos-default', $service->getFor('user', 777)->preferences['wallpaper']);
        $this->assertSame('deep-blue', $service->getFor('admin', 777)->preferences['wallpaper']);
    }

    /** 用户端工作区读写闭环：读取默认值 → 保存偏好 → 再读取验证落库 */
    public function test_user_workspace_api_roundtrip(): void
    {
        $this->actingUser();

        $this->getJson('/user/cmspro/webos/api/workspace')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.terminal', 'user');

        $this->putJson('/user/cmspro/webos/api/workspace', [
            'preferences' => ['wallpaper' => 'deep-blue'],
        ])->assertOk();

        $this->getJson('/user/cmspro/webos/api/workspace')
            ->assertOk()
            ->assertJsonPath('data.preferences.wallpaper', 'deep-blue');
    }

    /** 用户端日历接口可用（与 admin 端同一实现） */
    public function test_user_calendar_api(): void
    {
        $this->actingUser();

        $this->getJson('/user/cmspro/webos/api/calendar?month=' . date('Y-m'))
            ->assertOk()
            ->assertJsonPath('code', 0);
    }

    /** 「覆盖传统后台」判定仅看 admin 终端：用户端工作区开启该偏好不参与 */
    public function test_any_admin_overrides_ignores_user_terminal(): void
    {
        $service = app(WorkspaceService::class);
        $userWorkspace = $service->getFor('user', 888);
        $userWorkspace->preferences = ['override_admin_home' => true];
        $userWorkspace->save();

        $this->assertFalse(WorkspaceService::anyAdminOverridesAdminHome());
    }
}
