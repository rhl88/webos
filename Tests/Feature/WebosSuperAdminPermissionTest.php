<?php

namespace App\Apps\CmsproWebos\Tests\Feature;

use App\Apps\CmsproWebos\Services\WorkspaceService;
use App\Apps\CmsproWebos\Tests\WebosTestCase;
use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 「覆盖传统后台」与「管理桌面/用户桌面」预设的超级管理员专属权限（2.1.104）：
 * 预设接口 10 条路由仅超管可用（普通管理员 403）；saveWorkspace 中 override 双开关
 * 非超管静默剔除（保留库中原值，前端整包提交历史数据不误伤）；前端三处渲染入口
 * （Tab 追加、「覆盖传统后台」卡片、Tab 内容守卫）均加 isSuperAdmin 门槛。
 */
class WebosSuperAdminPermissionTest extends WebosTestCase
{
    /** 普通管理员访问全部预设路由：中间件先于参数校验拦截 403 + 统一错误码（无需合法入参） */
    public function test_preset_apis_reject_regular_admins(): void
    {
        $this->actingAdmin();

        $presetRoutes = [
            ['GET', '/user-desktop-preset'],
            ['PUT', '/user-desktop-preset'],
            ['GET', '/user-desktop/users'],
            ['GET', '/user-desktop'],
            ['PUT', '/user-desktop'],
            ['GET', '/admin-desktop-preset'],
            ['PUT', '/admin-desktop-preset'],
            ['GET', '/admin-desktop/admins'],
            ['GET', '/admin-desktop'],
            ['PUT', '/admin-desktop'],
        ];

        foreach ($presetRoutes as [$method, $path]) {
            $response = $method === 'GET'
                ? $this->getJson('/admin/cmspro/webos/api' . $path)
                : $this->putJson('/admin/cmspro/webos/api' . $path);

            $response->assertStatus(403)
                ->assertJsonPath('code', 40101)
                ->assertJsonPath('message', '无访问权限');
        }
    }

    /** 超级管理员可正常访问全部预设路由（用户桌面/管理桌面共 10 条全放行） */
    public function test_preset_apis_allow_super_admins(): void
    {
        $this->actingAsSuperAdmin();

        $role = AdminRole::create(['name' => '运营组', 'code' => 'webos_gate_' . uniqid(), 'status' => 1, 'sort' => 1]);
        $user = User::create([
            'username' => 'webos_gate_u_' . uniqid(),
            'password' => bcrypt('123456'),
            'nickname' => '门禁用户',
            'status' => 1,
        ]);
        $admin = AdminUser::create([
            'username' => 'webos_gate_a_' . uniqid(),
            'password' => bcrypt('123456'),
            'name' => '门禁管理员',
            'status' => 1,
        ]);

        $base = '/admin/cmspro/webos/api';
        $this->getJson($base . '/user-desktop-preset')->assertOk()->assertJsonPath('code', 0);
        $this->putJson($base . '/user-desktop-preset', ['desktop_items' => []])->assertOk()->assertJsonPath('code', 0);
        $this->getJson($base . '/user-desktop/users')->assertOk()->assertJsonPath('code', 0);
        $this->getJson($base . '/user-desktop?user_id=' . $user->id)->assertOk()->assertJsonPath('code', 0);
        $this->putJson($base . '/user-desktop', ['user_id' => $user->id, 'desktop_items' => []])->assertOk()->assertJsonPath('code', 0);
        $this->getJson($base . '/admin-desktop-preset')->assertOk()->assertJsonPath('code', 0);
        $this->putJson($base . '/admin-desktop-preset', ['role_id' => $role->id, 'desktop_items' => []])->assertOk()->assertJsonPath('code', 0);
        $this->getJson($base . '/admin-desktop/admins')->assertOk()->assertJsonPath('code', 0);
        $this->getJson($base . '/admin-desktop?admin_id=' . $admin->id)->assertOk()->assertJsonPath('code', 0);
        $this->putJson($base . '/admin-desktop', ['admin_id' => $admin->id, 'desktop_items' => []])->assertOk()->assertJsonPath('code', 0);
    }

    /** 普通管理员 saveWorkspace 提交 override 双开关：静默剔除不落库，也不能关闭超管已开启的全局开关 */
    public function test_save_workspace_strips_override_preferences_for_regular_admins(): void
    {
        // 超管先开启「后台首页直达 WebOS」，建立全局生效基线
        $super = AdminUser::create([
            'username' => 'webos_super_' . uniqid(),
            'password' => bcrypt('123456'),
            'name' => '基线超管',
            'status' => 1,
        ]);
        $superRole = AdminRole::create(['name' => '超级管理员', 'code' => 'super_admin', 'status' => 1]);
        DB::table('admin_user_roles')->insert(['user_id' => $super->id, 'role_id' => $superRole->id]);
        $this->actingAs($super, 'admin');

        $this->putJson('/admin/cmspro/webos/api/workspace', [
            'desktop_items' => [],
            'preferences' => ['override_admin_home' => true],
        ])->assertOk()->assertJsonPath('data.preferences.override_admin_home', true);
        $this->assertTrue(WorkspaceService::anyAdminOverridesAdminHome());

        // 普通管理员：开启类提交（override_user_home）与关闭类提交（override_admin_home）均被剔除
        $this->actingAdmin();
        $this->putJson('/admin/cmspro/webos/api/workspace', [
            'desktop_items' => [],
            'preferences' => ['override_admin_home' => false, 'override_user_home' => true],
        ])->assertOk()
            ->assertJsonPath('data.preferences.override_admin_home', false)
            ->assertJsonPath('data.preferences.override_user_home', false);

        // 全局判定：超管开启的开关未被普通管理员的关闭提交影响；普通管理员的开启提交未生效
        $this->assertTrue(WorkspaceService::anyAdminOverridesAdminHome());
        $this->assertFalse(WorkspaceService::anyAdminOverridesUserHome());
    }

    /** 超级管理员 saveWorkspace 提交 override 双开关：正常落库且全局生效 */
    public function test_super_admin_can_persist_override_preferences(): void
    {
        $this->actingAsSuperAdmin();

        $this->putJson('/admin/cmspro/webos/api/workspace', [
            'desktop_items' => [],
            'preferences' => ['override_admin_home' => true, 'override_user_home' => true],
        ])->assertOk()
            ->assertJsonPath('data.preferences.override_admin_home', true)
            ->assertJsonPath('data.preferences.override_user_home', true);

        $this->assertTrue(WorkspaceService::anyAdminOverridesAdminHome());
        $this->assertTrue(WorkspaceService::anyAdminOverridesUserHome());
    }

    /** 用户端 saveWorkspace 提交 override 双开关：同样静默剔除（用户端无该能力） */
    public function test_user_terminal_save_workspace_strips_override_preferences(): void
    {
        $this->actingUser();

        $this->putJson('/user/cmspro/webos/api/workspace', [
            'desktop_items' => [],
            'preferences' => ['override_admin_home' => true, 'override_user_home' => true],
        ])->assertOk()
            ->assertJsonPath('data.preferences.override_admin_home', false)
            ->assertJsonPath('data.preferences.override_user_home', false);

        $this->assertFalse(WorkspaceService::anyAdminOverridesAdminHome());
        $this->assertFalse(WorkspaceService::anyAdminOverridesUserHome());
    }

    /** 前端与中间件接线：webos.js 三处 isSuperAdmin 门槛、EnsureSuperAdmin 中间件、路由挂载与 saveWorkspace 剔除逻辑 */
    public function test_super_admin_gating_wires_frontend_and_middleware(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $this->assertIsString($script);
        // Tab 追加条件：仅后台端、仅超管、且对应开关开启
        $this->assertStringContainsString('if (!isUserTerminal && isSuperAdmin && state.workspace.preferences.override_admin_home) {', $script);
        $this->assertStringContainsString('if (!isUserTerminal && isSuperAdmin && state.workspace.preferences.override_user_home) {', $script);
        // 「覆盖传统后台」卡片：仅后台端且仅超管渲染（普通管理员不可见）
        $this->assertStringContainsString("var overrideSection = (isUserTerminal || !isSuperAdmin) ? ''", $script);
        // Tab 内容守卫：非超管回退基本设置
        $this->assertStringContainsString("if (osSettingsTab === 'user-desktop' && (isUserTerminal || !isSuperAdmin || !preferences.override_user_home)) {", $script);
        $this->assertStringContainsString("if (osSettingsTab === 'admin-desktop' && (isUserTerminal || !isSuperAdmin || !preferences.override_admin_home)) {", $script);

        $middleware = file_get_contents(dirname(__DIR__, 2) . '/Http/Middleware/EnsureSuperAdmin.php');
        $this->assertIsString($middleware);
        $this->assertStringContainsString('class EnsureSuperAdmin', $middleware);
        $this->assertStringContainsString("ApiResponse::error(40101, '无访问权限')", $middleware);

        $routes = file_get_contents(dirname(__DIR__, 2) . '/Routes/admin.php');
        $this->assertIsString($routes);
        $this->assertStringContainsString('Route::middleware(EnsureSuperAdmin::class)', $routes);

        $controller = file_get_contents(dirname(__DIR__, 2) . '/Controllers/Admin/WebosController.php');
        $this->assertIsString($controller);
        $this->assertStringContainsString("if (! auth('admin')->user()?->isSuperAdmin()) {", $controller);
        $this->assertStringContainsString('unset($validated[\'preferences\'][\'override_admin_home\'], $validated[\'preferences\'][\'override_user_home\']);', $controller);
    }
}
