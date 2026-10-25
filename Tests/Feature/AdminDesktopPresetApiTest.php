<?php

namespace App\Apps\CmsproWebos\Tests\Feature;

use App\Apps\CmsproWebos\Services\WorkspaceService;
use App\Apps\CmsproWebos\Tests\WebosTestCase;
use App\Enums\AppStatus;
use App\Models\AdminMenu;
use App\Models\AdminRole;
use App\Models\AppModel;
use Illuminate\Support\Facades\DB;

/** 「管理桌面」按后台角色预设管理员桌面（2.1.98）：接口往返、角色缺省与目录授权口径、前端接线 */
class AdminDesktopPresetApiTest extends WebosTestCase
{
    /** 预设接口读写闭环：角色列表/角色预设/角色菜单目录一并下发，保存后读回一致，校验口径与工作区保存一致 */
    public function test_admin_desktop_preset_api_roundtrip(): void
    {
        $this->actingAsSuperAdmin();

        $role = AdminRole::create([
            'name' => '运营组',
            'code' => 'webos_admin_preset_' . uniqid(),
            'status' => 1,
            'sort' => 1,
        ]);

        $this->getJson('/admin/cmspro/webos/api/admin-desktop-preset?role_id=' . $role->id)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.role_id', $role->id)
            ->assertJsonPath('data.roles.0.id', $role->id)
            ->assertJsonPath('data.roles.0.name', '运营组')
            ->assertJsonPath('data.desktop_items', [])
            ->assertJsonStructure(['data' => ['roles', 'role_id', 'desktop_items', 'catalog' => ['menus', 'applications']]]);

        $this->putJson('/admin/cmspro/webos/api/admin-desktop-preset', [
            'role_id' => $role->id,
            'desktop_items' => [
                [
                    'id' => 'role-preset-folder',
                    'title' => '运营工具',
                    'path' => '',
                    'kind' => 'folder',
                    'icon' => 'fa fa-folder',
                    'x' => 0,
                    'y' => 0,
                ],
                [
                    'id' => 'role-preset-menu',
                    'menu_id' => 66,
                    'title' => '内容审核',
                    'path' => '/admin/content/review',
                    'icon' => 'fa fa-check-square-o',
                    'x' => 1,
                    'y' => 0,
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('message', '管理桌面预设已保存')
            ->assertJsonPath('data.desktop_items.0.kind', 'folder')
            ->assertJsonPath('data.desktop_items.1.path', '/admin/content/review');

        // 落库后可读回（隔离行：terminal=admin_preset + 角色 ID）
        $this->assertCount(2, app(WorkspaceService::class)->getAdminPreset($role->id)->desktop_items);
        $this->getJson('/admin/cmspro/webos/api/admin-desktop-preset?role_id=' . $role->id)
            ->assertOk()
            ->assertJsonPath('data.desktop_items.0.id', 'role-preset-folder');

        // role_id 必填且必须是存在的角色：保存接口的强校验
        $this->putJson('/admin/cmspro/webos/api/admin-desktop-preset', ['desktop_items' => []])
            ->assertStatus(422);
        $this->putJson('/admin/cmspro/webos/api/admin-desktop-preset', ['role_id' => 999999, 'desktop_items' => []])
            ->assertStatus(422);

        // 非法站外路径被同一套校验拒绝，保证播种数据必然通过工作区保存校验
        $this->putJson('/admin/cmspro/webos/api/admin-desktop-preset', [
            'role_id' => $role->id,
            'desktop_items' => [[
                'id' => 'preset-unsafe',
                'title' => '外部站点',
                'path' => 'https://evil.example.com',
                'icon' => 'fa fa-link',
                'x' => 0,
                'y' => 0,
            ]],
        ])->assertStatus(422);
    }

    /** role_id 缺省取第一启用角色：角色列表按 sort 排序且不含停用角色 */
    public function test_admin_desktop_preset_defaults_to_the_first_enabled_role(): void
    {
        $this->actingAsSuperAdmin();

        AdminRole::create(['name' => '低优先级', 'code' => 'webos_default_b_' . uniqid(), 'status' => 1, 'sort' => 9]);
        $firstRole = AdminRole::create(['name' => '高优先级', 'code' => 'webos_default_a_' . uniqid(), 'status' => 1, 'sort' => 1]);
        AdminRole::create(['name' => '停用角色', 'code' => 'webos_default_c_' . uniqid(), 'status' => 0, 'sort' => 0]);

        $response = $this->getJson('/admin/cmspro/webos/api/admin-desktop-preset')
            ->assertOk()
            ->assertJsonPath('data.role_id', $firstRole->id);

        // 角色列表：启用角色按 sort 升序，停用角色不下发；末位为超管身份自带的内置角色（sort=999，2.1.104）
        $roles = collect($response->json('data.roles'));
        $this->assertSame(['高优先级', '低优先级', '超级管理员'], $roles->pluck('name')->all());
    }

    /** 角色菜单目录口径：仅该角色被分配的菜单（含祖先补全），未分配/停用/隐藏菜单不出现，应用清单按角色菜单收敛 */
    public function test_admin_desktop_preset_catalog_follows_role_menu_grants(): void
    {
        $this->actingAsSuperAdmin();

        $role = AdminRole::create(['name' => '运营组', 'code' => 'webos_catalog_' . uniqid(), 'status' => 1, 'sort' => 1]);

        // 三层菜单：根分组 → 应用分组 → 叶子；仅把叶子分配给角色
        $root = AdminMenu::create(['name' => '管理', 'parent_id' => 0, 'terminal_type' => 'admin', 'status' => 1, 'visible' => 1, 'sort' => 1]);
        $group = AdminMenu::create(['name' => '儿康管理', 'parent_id' => $root->id, 'app_id' => 'cmspro.rolecatalog', 'terminal_type' => 'admin', 'status' => 1, 'visible' => 1, 'sort' => 2]);
        $leaf = AdminMenu::create(['name' => '工作台', 'parent_id' => $group->id, 'app_id' => 'cmspro.rolecatalog', 'path' => '/admin/rolecatalog/dashboard', 'terminal_type' => 'admin', 'status' => 1, 'visible' => 1, 'sort' => 3]);
        // 未分配给角色的菜单：不出现在目录
        $ungranted = AdminMenu::create(['name' => '未授权菜单', 'parent_id' => 0, 'path' => '/admin/other', 'terminal_type' => 'admin', 'status' => 1, 'visible' => 1, 'sort' => 4]);
        // 停用/隐藏菜单：即便分配了角色也因 status/visible 过滤不出现
        $disabled = AdminMenu::create(['name' => '停用菜单', 'parent_id' => 0, 'path' => '/admin/disabled', 'terminal_type' => 'admin', 'status' => 0, 'visible' => 1, 'sort' => 5]);
        $hidden = AdminMenu::create(['name' => '隐藏菜单', 'parent_id' => 0, 'path' => '/admin/hidden', 'terminal_type' => 'admin', 'status' => 1, 'visible' => 0, 'sort' => 6]);

        DB::table('admin_role_menus')->insert(['role_id' => $role->id, 'menu_id' => $leaf->id]);
        DB::table('admin_role_menus')->insert(['role_id' => $role->id, 'menu_id' => $disabled->id]);

        AppModel::create([
            'app_id' => 'cmspro.rolecatalog',
            'name' => '角色目录测试应用',
            'icon' => 'fa fa-cube',
            'version' => '1.0.0',
            'status' => AppStatus::ENABLED,
            'is_system' => 0,
        ]);

        $response = $this->getJson('/admin/cmspro/webos/api/admin-desktop-preset?role_id=' . $role->id)
            ->assertOk();

        // 祖先补全：根分组在树根部，应用分组挂在根下，授权叶子挂在分组之下
        $menus = collect($response->json('data.catalog.menus'));
        $rootNode = $menus->firstWhere('id', $root->id);
        $this->assertNotNull($rootNode, '补全后的根分组应出现在菜单树根部');
        $groupNode = collect($rootNode['children'] ?? [])->firstWhere('id', $group->id);
        $this->assertNotNull($groupNode, '补全后的父级分组应出现在根分组之下');
        $this->assertEquals([$leaf->id], collect($groupNode['children'] ?? [])->pluck('id')->all(), '授权叶子挂在补全的父级之下');

        // 未授权/停用/隐藏菜单不出现（递归收集全树 id 比对）
        $allMenuIds = collect($response->json('data.catalog.menus'))
            ->flatMap(fn (array $node): array => $this->flattenMenuIds($node))
            ->all();
        $this->assertNotContains($ungranted->id, $allMenuIds);
        $this->assertNotContains($disabled->id, $allMenuIds);
        $this->assertNotContains($hidden->id, $allMenuIds);

        // 应用清单：仅角色菜单出现过的应用，图标走应用公开图标路由
        $applications = collect($response->json('data.catalog.applications'));
        $this->assertSame(['cmspro.rolecatalog'], $applications->pluck('app_id')->all());
        $this->assertStringContainsString('/api/app/cmspro.rolecatalog/icon', $applications->first()['icon_url']);
    }

    /** 接口仅后台端提供：未登录访问返回 401，用户端不注册该路由（404） */
    public function test_admin_desktop_preset_requires_admin_authentication(): void
    {
        $this->getJson('/admin/cmspro/webos/api/admin-desktop-preset')->assertUnauthorized();

        $this->actingUser();
        $this->getJson('/user/cmspro/webos/api/admin-desktop-preset')->assertNotFound();
        $this->putJson('/user/cmspro/webos/api/admin-desktop-preset', ['role_id' => 1, 'desktop_items' => []])->assertNotFound();
    }

    /** terminal 列口径回归：三处建表口径须为 VARCHAR(20)（容纳 admin_preset 12 字符），防止回退到 2.1.80 的 VARCHAR(10)（真实库 MySQL 1406 根因） */
    public function test_workspace_terminal_column_accommodates_admin_preset_value(): void
    {
        // SQLite 建表不保留 varchar 长度（故真实库才暴露 1406），口径定义以源码断言防回退：
        // 测试基类建表、生产扩容迁移、人工升级脚本三处必须同为 VARCHAR(20) 口径
        $testCase = file_get_contents(dirname(__DIR__) . '/WebosTestCase.php');
        $this->assertIsString($testCase);
        $this->assertStringContainsString("string('terminal', 20)->default('admin')", $testCase);

        $migration = file_get_contents(dirname(__DIR__, 2) . '/Migrations/2026_10_24_000001_extend_terminal_length_on_app_cmspro_webos_workspaces_table.php');
        $this->assertIsString($migration);
        $this->assertStringContainsString("string('terminal', 20)->default('admin')", $migration);

        $upgradeSql = file_get_contents(dirname(__DIR__, 2) . '/Database/upgrade.sql');
        $this->assertIsString($upgradeSql);
        $this->assertStringContainsString('MODIFY COLUMN `terminal` VARCHAR(20) NOT NULL', $upgradeSql);

        // 业务回归：管理桌面角色预设按 terminal=admin_preset + 角色ID 完整落库
        $this->actingAsSuperAdmin();
        $role = AdminRole::create(['name' => '口径回归', 'code' => 'webos_terminal_fix_' . uniqid(), 'status' => 1, 'sort' => 1]);
        $this->putJson('/admin/cmspro/webos/api/admin-desktop-preset', [
            'role_id' => $role->id,
            'desktop_items' => [],
        ])->assertOk()->assertJsonPath('code', 0);

        $row = DB::table('app_cmspro_webos_workspaces')
            ->where('terminal', 'admin_preset')
            ->where('admin_user_id', $role->id)
            ->first();
        $this->assertNotNull($row, '管理桌面预设应按 terminal=admin_preset + 角色ID 落库');
    }

    /** 「管理桌面」Tab 前端接线：显示条件、角色下拉、会话参数化、保存分流、路由与视图/CSS 齐备（仅后台端） */
    public function test_admin_desktop_tab_binds_frontend_wiring(): void
    {
        // 控制器与路由：预设读写接口按角色维度注册
        $controller = file_get_contents(dirname(__DIR__, 2) . '/Controllers/Admin/WebosController.php');
        $this->assertIsString($controller);
        $this->assertStringContainsString('public function adminDesktopPreset(Request $request): JsonResponse', $controller);
        $this->assertStringContainsString('public function saveAdminDesktopPreset(Request $request): JsonResponse', $controller);
        $this->assertStringContainsString('protected function roleMenuTree(int $roleId): array', $controller);
        $this->assertStringContainsString('protected function roleApplicationList(int $roleId): array', $controller);

        $routes = file_get_contents(dirname(__DIR__, 2) . '/Routes/admin.php');
        $this->assertIsString($routes);
        $this->assertStringContainsString("Route::get('/admin-desktop-preset', [WebosController::class, 'adminDesktopPreset'])->name('admin-desktop-preset.show');", $routes);
        $this->assertStringContainsString("Route::put('/admin-desktop-preset', [WebosController::class, 'saveAdminDesktopPreset'])->name('admin-desktop-preset.update');", $routes);

        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $this->assertIsString($script);
        // Tab 追加条件：仅后台端、仅超管且「后台首页直达 WebOS」开启，且排在「用户桌面」之前
        $this->assertStringContainsString('if (!isUserTerminal && isSuperAdmin && state.workspace.preferences.override_admin_home) {', $script);
        $this->assertStringContainsString("tabs.push(['admin-desktop', 'fa-users', '管理桌面']);", $script);
        // 配置关闭/非超管/用户端访问时内容回退基本设置；Tab 内容与角色选择区
        $this->assertStringContainsString("if (osSettingsTab === 'admin-desktop' && (isUserTerminal || !isSuperAdmin || !preferences.override_admin_home)) {", $script);
        $this->assertStringContainsString('function adminDesktopSectionMarkup() {', $script);
        $this->assertStringContainsString('function presetRoleSelectMarkup() {', $script);
        // 预设会话来源参数化：user=「用户桌面」Tab、admin=「管理桌面」Tab，跨 Tab 切换先退出旧会话
        $this->assertStringContainsString("var presetSourceType = osSettingsTab === 'admin-desktop' ? 'admin' : (osSettingsTab === 'user-desktop' ? 'user' : '');", $script);
        $this->assertStringContainsString('enterPresetSession(presetSourceType);', $script);
        $this->assertStringContainsString('function enterPresetSession(type, userId) {', $script);
        $this->assertStringContainsString('state.presetSession = { type: type };', $script);
        // 角色下拉切换：切换编辑角色并按新角色重载预设
        $this->assertStringContainsString('function switchAdminPresetRole(roleId) {', $script);
        $this->assertStringContainsString("var select = event.target.closest('[data-preset-role]');", $script);
        $this->assertStringContainsString('switchAdminPresetRole(select.value);', $script);
        // 保存分流：管理桌面预设保存到当前编辑的角色（body 携带 role_id）
        $this->assertStringContainsString("var session = state.presetSession || {};", $script);
        $this->assertStringContainsString("var isAdminPreset = session.type === 'admin';", $script);
        $this->assertStringContainsString('body.role_id = state.adminPresetRoleId;', $script);

        // Blade：管理桌面预设接口地址仅后台端注入
        $view = file_get_contents(dirname(__DIR__, 2) . '/Views/Admin/desktop/index.blade.php');
        $this->assertIsString($view);
        $this->assertStringContainsString('data-admin-preset-url="{{ $webosApiBase }}/admin-desktop-preset"', $view);

        // CSS：角色选择行与下拉样式
        $css = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');
        $this->assertIsString($css);
        $this->assertStringContainsString('.preset-role-row {', $css);
        $this->assertStringContainsString('.preset-role-select {', $css);
    }

    /**
     * 递归收集菜单树节点 id（含 children），供「未授权/停用/隐藏菜单不出现」的全树比对。
     *
     * @return array<int,int|string>
     */
    private function flattenMenuIds(array $node): array
    {
        $ids = [$node['id']];

        foreach ($node['children'] ?? [] as $child) {
            $ids = array_merge($ids, $this->flattenMenuIds($child));
        }

        return $ids;
    }
}
