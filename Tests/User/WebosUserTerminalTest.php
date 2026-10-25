<?php

namespace App\Apps\CmsproWebos\Tests\User;

use App\Apps\CmsproWebos\Http\Middleware\RedirectUserHomeToWebos;
use App\Apps\CmsproWebos\Services\WorkspaceService;
use App\Apps\CmsproWebos\Tests\WebosTestCase;
use App\Enums\AppStatus;
use App\Models\AdminMenu;
use App\Models\AppModel;

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

    /** 「用户首页直达 WebOS」判定仅看 admin 终端：用户端工作区开启该偏好不参与 */
    public function test_any_admin_overrides_user_home_ignores_user_terminal(): void
    {
        $service = app(WorkspaceService::class);
        $userWorkspace = $service->getFor('user', 888);
        $userWorkspace->preferences = ['override_user_home' => true];
        $userWorkspace->save();

        $this->assertFalse(WorkspaceService::anyAdminOverridesUserHome());
    }

    /** 用户首页直达（2.1.82）：任一管理员开启后，登录用户 GET /user 302 到用户端桌面；skip_webos=1 与关闭开关均恢复框架首页 */
    public function test_user_home_redirects_to_webos_when_any_admin_enabled(): void
    {
        $this->actingUser();
        $service = app(WorkspaceService::class);

        // 未开启：用户首页按框架原逻辑渲染
        $this->get('/user')->assertOk();

        // 任一管理员开启直达：302 到 WebOS 用户端桌面（临时跳转，非 301）
        $adminWorkspace = $service->getFor('admin', 1);
        $adminWorkspace->preferences = ['override_user_home' => true];
        $adminWorkspace->save();
        $this->assertTrue(WorkspaceService::anyAdminOverridesUserHome());

        $this->get('/user')->assertRedirect('/user/cmspro/webos');

        // skip_webos=1 临时绕过（与后台首页覆盖一致的逃生通道）
        $this->get('/user?skip_webos=1')->assertOk();

        // 关闭开关：用户首页恢复框架渲染
        $adminWorkspace->preferences = ['override_user_home' => false];
        $adminWorkspace->save();
        $this->get('/user')->assertOk();
    }

    /**
     * 「用户首页直达」注册时序契约（2.1.84）：中间件必须挂在 web 组内 StartSession 之后。
     * 其前置判断 auth('web')->check() 依赖会话已启动；若注册到组首（prepend），Laravel 会按
     * 中间件优先级把它排到 StartSession 之前，判定恒为 false 而静默漏判（线上失效根因）。
     */
    public function test_user_home_redirect_middleware_runs_after_session_start(): void
    {
        $middleware = app('router')->gatherRouteMiddleware(
            app('router')->getRoutes()->getByName('user.index')
        );

        $sessionIndex = array_search(\Illuminate\Session\Middleware\StartSession::class, $middleware, true);
        $redirectIndex = array_search(RedirectUserHomeToWebos::class, $middleware, true);

        $this->assertNotFalse($sessionIndex, 'user.index 路由缺少 StartSession');
        $this->assertNotFalse($redirectIndex, '「用户首页直达」中间件未挂载到 user.index 路由');
        $this->assertGreaterThan($sessionIndex, $redirectIndex, '中间件必须在 StartSession 之后执行，否则登录态判定失效');
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

    /** 用户端应用清单接口（2.1.85）：返回菜单涉及应用的名称/图标，供开始菜单与桌面入口树渲染；
     *  未在用户菜单中分配的应用（含仅 admin 终端菜单引用者）不外泄 */
    public function test_user_applications_api_returns_menu_apps_with_identity(): void
    {
        $this->actingUser();

        AppModel::create([
            'app_id' => 'webos.app.visible',
            'name' => '用户端可见应用',
            'icon' => 'fa fa-cube',
            'version' => '1.0.0',
            'status' => AppStatus::ENABLED,
            'is_system' => 0,
        ]);
        AppModel::create([
            'app_id' => 'webos.app.hidden',
            'name' => '未分配应用',
            'icon' => 'fa fa-cube',
            'version' => '1.0.0',
            'status' => AppStatus::ENABLED,
            'is_system' => 0,
        ]);

        // 仅 user 终端菜单引用的应用对用户端可见；admin 终端菜单引用的应用不进入清单
        AdminMenu::create(['name' => 'WebOS 可见应用菜单', 'parent_id' => 0, 'app_id' => 'webos.app.visible', 'terminal_type' => 'user', 'status' => 1, 'visible' => 1, 'sort' => 1]);
        AdminMenu::create(['name' => 'WebOS 后台应用菜单', 'parent_id' => 0, 'app_id' => 'webos.app.hidden', 'terminal_type' => 'admin', 'status' => 1, 'visible' => 1, 'sort' => 2]);

        $data = $this->getJson('/user/cmspro/webos/api/applications')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->json('data');

        $app = collect($data)->firstWhere('app_id', 'webos.app.visible');
        $this->assertNotNull($app, '用户端菜单涉及的应用应出现在应用清单中');
        $this->assertSame('用户端可见应用', $app['name']);
        $this->assertSame('fa fa-cube', $app['icon']);
        $this->assertSame(url('/api/app/webos.app.visible/icon'), $app['icon_url']);
        $this->assertNull(collect($data)->firstWhere('app_id', 'webos.app.hidden'), '仅 admin 终端菜单引用的应用不应外泄');
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

    /** 用户端账号标识接口（昵称/账号/头像）：个人设置窗口关闭后刷新状态栏与账号菜单所依赖 */
    public function test_user_admin_identity_api_returns_current_account(): void
    {
        $user = $this->actingUser();

        $this->getJson('/user/cmspro/webos/api/admin-identity')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.username', $user->username)
            ->assertJsonPath('data.name', $user->nickname ?: $user->username)
            ->assertJsonStructure(['data' => ['name', 'username', 'avatar']]);
    }

    /**
     * 窗口「前台菜单」数据源（用户端路由）：仅返回 terminal_type=home 的启用菜单树。
     * 用户端不得请求 /api/admin/menus/tree，否则未登录后台时会话恢复层会误报「会话过期」。
     */
    public function test_user_home_menu_tree_api_returns_home_terminal_menus(): void
    {
        $this->actingUser();

        // 菜单名避开框架默认 home 菜单，确保下面的筛选命中自建数据
        $root = AdminMenu::create(['name' => 'WebOS 前台栏目', 'parent_id' => 0, 'terminal_type' => 'home', 'status' => 1, 'visible' => 1, 'sort' => 1]);
        AdminMenu::create(['name' => 'WebOS 前台子页', 'parent_id' => $root->id, 'path' => '/forum/list', 'terminal_type' => 'home', 'status' => 1, 'visible' => 1, 'sort' => 2]);
        AdminMenu::create(['name' => 'WebOS 前台停用页', 'parent_id' => 0, 'path' => '/forum/off', 'terminal_type' => 'home', 'status' => 0, 'visible' => 1, 'sort' => 3]);
        AdminMenu::create(['name' => 'WebOS 后台菜单不外泄', 'parent_id' => 0, 'path' => '/admin/secret', 'terminal_type' => 'admin', 'status' => 1, 'visible' => 1, 'sort' => 4]);

        $data = $this->getJson('/user/cmspro/webos/api/menus/tree')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->json('data');

        $rootNode = collect($data)->firstWhere('name', 'WebOS 前台栏目');
        $this->assertNotNull($rootNode, '前台菜单树应包含 home 终端菜单');
        $this->assertSame('home', $rootNode['terminal_type']);
        $this->assertSame('WebOS 前台子页', $rootNode['children'][0]['name']);
        // 停用菜单与后台终端菜单不外泄
        $this->assertNull(collect($data)->firstWhere('name', 'WebOS 前台停用页'));
        $this->assertNull(collect($data)->firstWhere('name', 'WebOS 后台菜单不外泄'));
    }

    /** 前台菜单 URL 兼容转换（用户端路由）：按 app_id 返回「原始路径 → 访问地址」映射，隐藏菜单不参与 */
    public function test_user_home_menu_urls_api_returns_path_url_map(): void
    {
        $this->actingUser();

        AdminMenu::create(['name' => 'WebOS 论坛首页', 'parent_id' => 0, 'app_id' => 'webos.app.forum', 'path' => '/forum', 'terminal_type' => 'home', 'status' => 1, 'visible' => 1, 'sort' => 1]);
        AdminMenu::create(['name' => 'WebOS 论坛隐藏页', 'parent_id' => 0, 'app_id' => 'webos.app.forum', 'path' => '/forum/hidden', 'terminal_type' => 'home', 'status' => 1, 'visible' => 0, 'sort' => 2]);

        $data = $this->getJson('/user/cmspro/webos/api/home-menu-urls?app_ids[]=webos.app.forum')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->json('data');

        $this->assertArrayHasKey('webos.app.forum', $data);
        $items = collect($data['webos.app.forum']);
        $visible = $items->firstWhere('path', '/forum');
        $this->assertNotNull($visible, '可见前台菜单应出现在 URL 映射中');
        $this->assertNotEmpty($visible['url'], '前台菜单应换算为可访问地址');
        $this->assertNull($items->firstWhere('path', '/forum/hidden'), '隐藏菜单不参与 URL 映射');
    }

    /** 用户端前台菜单接口需登录：未认证请求被拒绝（与其它用户端 API 一致） */
    public function test_user_home_menu_apis_require_auth(): void
    {
        $this->assertContains($this->getJson('/user/cmspro/webos/api/menus/tree')->status(), [302, 401]);
        $this->assertContains($this->getJson('/user/cmspro/webos/api/home-menu-urls')->status(), [302, 401]);
    }
}
