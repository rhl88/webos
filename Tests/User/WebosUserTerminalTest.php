<?php

namespace App\Apps\CmsproWebos\Tests\User;

use App\Apps\CmsproWebos\Services\WorkspaceService;
use App\Apps\CmsproWebos\Tests\WebosTestCase;
use App\Models\AdminMenu;

/**
 * 用户端 WebOS 桌面（2.1.80）：入口分流、终端隔离与 API 能力验证。
 */
class WebosUserTerminalTest extends WebosTestCase
{
    /** 未登录访问桌面入口：渲染 WebOS 登录窗口（2.1.81），表单提交框架 /user/login 并携带回跳地址 */
    public function test_guest_index_renders_webos_login_window(): void
    {
        $response = $this->get('/user/cmspro/webos');
        $response->assertOk();

        $content = $response->getContent();
        // 表单走用户登录路由，redirect 隐藏字段回跳用户端桌面
        $this->assertStringContainsString('action="' . url('/user/login') . '"', $content);
        $this->assertStringContainsString('name="redirect" value="/user/cmspro/webos"', $content);
        $this->assertStringContainsString('请登录用户账号', $content);
        $this->assertStringNotContainsString('/admin/login', $content);
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

    /** 用户端菜单接口：仅返回 terminal_type=user 的启用可见菜单（树形嵌套），admin 菜单不外泄 */
    public function test_user_menus_api_returns_user_terminal_menus(): void
    {
        $this->actingUser();

        // 菜单名避开框架默认 user 菜单（如「用户中心」），确保 firstWhere 命中自建数据
        $root = AdminMenu::create(['name' => 'WebOS 用户中心', 'parent_id' => 0, 'terminal_type' => 'user', 'status' => 1, 'visible' => 1, 'sort' => 1]);
        AdminMenu::create(['name' => 'WebOS 我的订单', 'parent_id' => $root->id, 'path' => '/user/order', 'terminal_type' => 'user', 'status' => 1, 'visible' => 1, 'sort' => 2]);
        AdminMenu::create(['name' => 'WebOS 后台专属', 'parent_id' => 0, 'path' => '/admin/only', 'terminal_type' => 'admin', 'status' => 1, 'visible' => 1, 'sort' => 3]);
        AdminMenu::create(['name' => 'WebOS 停用菜单', 'parent_id' => 0, 'path' => '/user/disabled', 'terminal_type' => 'user', 'status' => 0, 'visible' => 1, 'sort' => 4]);

        // 框架基础迁移自带 user 终端默认菜单，故按名称过滤校验自建菜单的结构与隔离性
        $data = $this->getJson('/user/cmspro/webos/api/menus')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->json('data');

        $rootNode = collect($data)->firstWhere('name', 'WebOS 用户中心');
        $this->assertNotNull($rootNode);
        $this->assertSame('user', $rootNode['terminal_type']);
        $this->assertSame('WebOS 我的订单', $rootNode['children'][0]['name']);
        // admin 终端菜单与停用菜单不外泄
        $this->assertNull(collect($data)->firstWhere('name', 'WebOS 后台专属'));
        $this->assertNull(collect($data)->firstWhere('name', 'WebOS 停用菜单'));
    }

    /** 用户端 CSRF Token 接口：返回当前会话 Token（419 后静默续期用） */
    public function test_user_csrf_token_api(): void
    {
        $this->actingUser();

        $this->getJson('/user/cmspro/webos/api/csrf-token')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.token', csrf_token());
    }

    /** 用户端身份接口：昵称/账号/头像（会话心跳与恢复层轮询用） */
    public function test_user_identity_api(): void
    {
        $user = $this->actingUser();

        $this->getJson('/user/cmspro/webos/api/identity')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.username', $user->username);
    }
}
