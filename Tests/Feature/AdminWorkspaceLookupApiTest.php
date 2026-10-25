<?php

namespace App\Apps\CmsproWebos\Tests\Feature;

use App\Apps\CmsproWebos\Services\WorkspaceService;
use App\Apps\CmsproWebos\Tests\WebosTestCase;
use App\Enums\AppStatus;
use App\Models\AdminMenu;
use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\AppModel;
use Illuminate\Support\Facades\DB;

/**
 * 「管理桌面」搜索查看/代改指定管理员桌面（2.1.100）：
 * 搜索口径（账号/姓名模糊 + 禁用过滤）、只读查看（不建行不触发角色预设差量同步）、
 * 目录口径（超管全部开启菜单/普通管理员角色并集 + 祖先补全）、
 * 代改保存（仅覆盖 desktop_items，台账/任务栏不动）、前端接线。
 */
class AdminWorkspaceLookupApiTest extends WebosTestCase
{
    /** 搜索口径：账号/姓名模糊匹配，禁用管理员被过滤，仅返回 id/username/name 最小字段 */
    public function test_search_admins_matches_username_and_name_and_filters_disabled(): void
    {
        $this->actingAsSuperAdmin();

        $hitByUsername = $this->createAdmin('webos_admin_zhang_', '张三');
        $hitByName = $this->createAdmin('webos_admin_li_', '搜索姓名李四');
        // 禁用管理员：即便关键字命中也不得出现在结果中
        $this->createAdmin('webos_admin_disabled_', '已停用王五', 0);

        $response = $this->getJson('/admin/cmspro/webos/api/admin-desktop/admins?keyword=' . urlencode('zhang'))
            ->assertOk()
            ->assertJsonPath('code', 0);

        $admins = collect($response->json('data'));
        $this->assertSame([$hitByUsername->id], $admins->pluck('id')->all(), '账号命中且禁用管理员被过滤');
        $this->assertSame(['id', 'username', 'name'], array_keys($admins->first()), '仅返回最小字段，不暴露邮箱/手机号等敏感信息');

        $this->getJson('/admin/cmspro/webos/api/admin-desktop/admins?keyword=' . urlencode('姓名李四'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $hitByName->id);

        // 关键字命中禁用管理员的账号：结果为空（禁用过滤先于模糊匹配）
        $this->getJson('/admin/cmspro/webos/api/admin-desktop/admins?keyword=' . urlencode('webos_admin_disabled'))
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    /** 只读查看：无工作区行时返回空列表且不建行；有行时返回桌面/任务栏数据 */
    public function test_admin_desktop_is_read_only_and_creates_no_row(): void
    {
        $this->actingAsSuperAdmin();

        $admin = $this->createAdmin('webos_admin_read_', '只读查看管理员');

        // 管理员尚无工作区行：图标/任务栏返回空列表
        $this->getJson('/admin/cmspro/webos/api/admin-desktop?admin_id=' . $admin->id)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.admin.id', $admin->id)
            ->assertJsonPath('data.admin.username', $admin->username)
            ->assertJsonPath('data.desktop_items', [])
            ->assertJsonPath('data.taskbar_items', [])
            ->assertJsonStructure(['data' => ['admin', 'desktop_items', 'taskbar_items', 'catalog' => ['menus', 'applications']]]);

        // 只读原则：查看不创建工作区行（getForAdmin 的 firstOrCreate 与角色预设差量同步副作用不可接受）
        $this->assertSame(0, DB::table('app_cmspro_webos_workspaces')
            ->where('terminal', 'admin')
            ->where('admin_user_id', $admin->id)
            ->count(), '查看管理员桌面不得创建工作区行');

        // 管理员已有工作区行：返回其桌面图标与任务栏固定项
        $workspace = app(WorkspaceService::class)->getForAdmin($admin->id);
        $workspace->desktop_items = [[
            'id' => 'admin-desktop-entry',
            'title' => '我的入口',
            'path' => '/admin/some-page',
            'icon' => 'fa fa-star',
            'x' => 0,
            'y' => 0,
        ]];
        $workspace->taskbar_items = [['id' => 'admin-taskbar', 'title' => '任务栏固定项']];
        $workspace->save();

        $this->getJson('/admin/cmspro/webos/api/admin-desktop?admin_id=' . $admin->id)
            ->assertOk()
            ->assertJsonPath('data.desktop_items.0.id', 'admin-desktop-entry')
            ->assertJsonPath('data.taskbar_items.0.id', 'admin-taskbar');

        // admin_id 强校验：必填且必须是存在的管理员
        $this->getJson('/admin/cmspro/webos/api/admin-desktop')->assertStatus(422);
        $this->getJson('/admin/cmspro/webos/api/admin-desktop?admin_id=999999')->assertStatus(422);
    }

    /** 目录口径（超级管理员）：全部 status/visible 开启菜单可见（含未分配给任何角色的），隐藏/停用菜单不可见 */
    public function test_super_admin_catalog_includes_all_enabled_menus(): void
    {
        $this->actingAsSuperAdmin();

        // 被查看对象挂超管角色：角色与操作者共用（super_admin 幂等复用，2.1.104）
        $superRole = AdminRole::where('code', 'super_admin')->firstOrFail();
        $superAdmin = $this->createAdmin('webos_admin_super_', '超管目录管理员');
        DB::table('admin_user_roles')->insert(['user_id' => $superAdmin->id, 'role_id' => $superRole->id]);

        // 未分配给任何角色的开启菜单：超管仍可见；隐藏/停用菜单：超管也不可见
        $openMenu = AdminMenu::create(['name' => '未分配菜单', 'parent_id' => 0, 'terminal_type' => 'admin', 'path' => '/admin/open', 'status' => 1, 'visible' => 1, 'sort' => 1]);
        $hiddenMenu = AdminMenu::create(['name' => '隐藏菜单', 'parent_id' => 0, 'terminal_type' => 'admin', 'path' => '/admin/hidden', 'status' => 1, 'visible' => 0, 'sort' => 2]);
        $disabledMenu = AdminMenu::create(['name' => '停用菜单', 'parent_id' => 0, 'terminal_type' => 'admin', 'path' => '/admin/disabled', 'status' => 0, 'visible' => 1, 'sort' => 3]);

        $response = $this->getJson('/admin/cmspro/webos/api/admin-desktop?admin_id=' . $superAdmin->id)
            ->assertOk();

        $allMenuIds = collect($response->json('data.catalog.menus'))
            ->flatMap(fn (array $node): array => $this->flattenMenuIds($node))
            ->all();
        $this->assertContains($openMenu->id, $allMenuIds, '超管可见未分配给任何角色的开启菜单');
        $this->assertNotContains($hiddenMenu->id, $allMenuIds, '隐藏菜单对超管也不可见');
        $this->assertNotContains($disabledMenu->id, $allMenuIds, '停用菜单对超管也不可见');

        // 应用清单：其可见菜单中无 app_id 时为空列表
        $this->assertSame([], $response->json('data.catalog.applications'));
    }

    /** 目录口径（普通管理员）：全部角色被分配菜单并集 + 祖先补全，应用清单按其菜单收敛；无角色时目录为空 */
    public function test_staff_admin_catalog_follows_role_union_and_applications(): void
    {
        $this->actingAsSuperAdmin();

        // 未分配给下方两角色的开启菜单：普通管理员不可见（与超管口径的差异点）
        $ungrantedMenu = AdminMenu::create(['name' => '未授权菜单', 'parent_id' => 0, 'terminal_type' => 'admin', 'path' => '/admin/other', 'status' => 1, 'visible' => 1, 'sort' => 1]);

        $roleX = AdminRole::create(['name' => '甲组', 'code' => 'webos_admin_catalog_x_' . uniqid(), 'status' => 1, 'sort' => 1]);
        $roleY = AdminRole::create(['name' => '乙组', 'code' => 'webos_admin_catalog_y_' . uniqid(), 'status' => 1, 'sort' => 2]);
        $staffAdmin = $this->createAdmin('webos_admin_staff_', '多角色管理员');
        DB::table('admin_user_roles')->insert(['user_id' => $staffAdmin->id, 'role_id' => $roleX->id]);
        DB::table('admin_user_roles')->insert(['user_id' => $staffAdmin->id, 'role_id' => $roleY->id]);

        $groupX = AdminMenu::create(['name' => '甲组菜单', 'parent_id' => 0, 'app_id' => 'cmspro.admincatalog', 'terminal_type' => 'admin', 'status' => 1, 'visible' => 1, 'sort' => 2]);
        $leafX = AdminMenu::create(['name' => '甲组工作台', 'parent_id' => $groupX->id, 'app_id' => 'cmspro.admincatalog', 'path' => '/admin/x/dashboard', 'terminal_type' => 'admin', 'status' => 1, 'visible' => 1, 'sort' => 3]);
        $leafY = AdminMenu::create(['name' => '乙组工作台', 'parent_id' => 0, 'app_id' => 'cmspro.admincatalog', 'path' => '/admin/y/dashboard', 'terminal_type' => 'admin', 'status' => 1, 'visible' => 1, 'sort' => 4]);
        DB::table('admin_role_menus')->insert(['role_id' => $roleX->id, 'menu_id' => $leafX->id]);
        DB::table('admin_role_menus')->insert(['role_id' => $roleY->id, 'menu_id' => $leafY->id]);

        AppModel::create([
            'app_id' => 'cmspro.admincatalog',
            'name' => '管理员目录测试应用',
            'icon' => 'fa fa-cubes',
            'version' => '1.0.0',
            'status' => AppStatus::ENABLED,
            'is_system' => 0,
        ]);

        $response = $this->getJson('/admin/cmspro/webos/api/admin-desktop?admin_id=' . $staffAdmin->id)
            ->assertOk();

        // 多角色并集：两角色各自的授权叶子均出现；祖先补全：授权叶子的父级分组出现
        $allMenuIds = collect($response->json('data.catalog.menus'))
            ->flatMap(fn (array $node): array => $this->flattenMenuIds($node))
            ->all();
        $this->assertContains($leafX->id, $allMenuIds, '角色甲的授权叶子出现');
        $this->assertContains($leafY->id, $allMenuIds, '角色乙的授权叶子出现（多角色并集）');
        $this->assertContains($groupX->id, $allMenuIds, '祖先补全：授权叶子的父级分组出现');
        $this->assertNotContains($ungrantedMenu->id, $allMenuIds, '未分配给其角色的菜单不出现');

        // 应用清单：仅其菜单出现过的应用，图标走应用公开图标路由
        $applications = collect($response->json('data.catalog.applications'));
        $this->assertSame(['cmspro.admincatalog'], $applications->pluck('app_id')->all());
        $this->assertStringContainsString('/api/app/cmspro.admincatalog/icon', $applications->first()['icon_url']);

        // 无角色管理员：目录为空（角色并集为空集）
        $rolelessAdmin = $this->createAdmin('webos_admin_roleless_', '无角色管理员');
        $this->getJson('/admin/cmspro/webos/api/admin-desktop?admin_id=' . $rolelessAdmin->id)
            ->assertOk()
            ->assertJsonPath('data.catalog.menus', [])
            ->assertJsonPath('data.catalog.applications', []);
    }

    /** 代改保存往返：仅覆盖 desktop_items，任务栏固定项/偏好/角色预设推送台账保持不动 */
    public function test_save_admin_desktop_overwrites_items_only_and_keeps_taskbar_and_ledger(): void
    {
        $this->actingAsSuperAdmin();

        $admin = $this->createAdmin('webos_admin_save_', '代改保存管理员');

        // 管理员已有工作区行：含任务栏固定项与已推送角色预设台账
        $workspace = app(WorkspaceService::class)->getForAdmin($admin->id);
        $workspace->taskbar_items = [['id' => 'admin-taskbar-keep', 'title' => '须保留的任务栏项']];
        $workspace->preset_sync_ids = ['role-preset-pushed-a', 'role-preset-pushed-b'];
        $workspace->desktop_items = [[
            'id' => 'old-item',
            'title' => '旧图标',
            'path' => '/admin/old',
            'icon' => 'fa fa-circle',
            'x' => 0,
            'y' => 0,
        ]];
        $workspace->save();

        $this->putJson('/admin/cmspro/webos/api/admin-desktop', [
            'admin_id' => $admin->id,
            'desktop_items' => [
                [
                    'id' => 'new-item',
                    'title' => '新图标',
                    'path' => '/admin/new',
                    'icon' => 'fa fa-star',
                    'x' => 0,
                    'y' => 0,
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('message', '管理员桌面已保存')
            ->assertJsonPath('data.desktop_items.0.id', 'new-item');

        $row = DB::table('app_cmspro_webos_workspaces')
            ->where('terminal', 'admin')
            ->where('admin_user_id', $admin->id)
            ->first();

        $this->assertNotNull($row);
        $desktopItems = json_decode((string) $row->desktop_items, true);
        $this->assertSame(['new-item'], array_column($desktopItems, 'id'), 'desktop_items 已被代改覆盖');
        $this->assertSame([['id' => 'admin-taskbar-keep', 'title' => '须保留的任务栏项']], json_decode((string) $row->taskbar_items, true), '任务栏固定项保持不动');
        $this->assertSame(['role-preset-pushed-a', 'role-preset-pushed-b'], json_decode((string) $row->preset_sync_ids, true), '角色预设推送台账保持不动（差量同步规则不受代改影响）');

        // 校验口径与工作区保存一致：admin_id 必填且存在、desktop_items 结构强校验（外站路径拒绝）
        $this->putJson('/admin/cmspro/webos/api/admin-desktop', ['desktop_items' => []])->assertStatus(422);
        $this->putJson('/admin/cmspro/webos/api/admin-desktop', ['admin_id' => 999999, 'desktop_items' => []])->assertStatus(422);
        $this->putJson('/admin/cmspro/webos/api/admin-desktop', [
            'admin_id' => $admin->id,
            'desktop_items' => [[
                'id' => 'unsafe-item',
                'title' => '外部站点',
                'path' => 'https://evil.example.com',
                'icon' => 'fa fa-link',
                'x' => 0,
                'y' => 0,
            ]],
        ])->assertStatus(422);
    }

    /** 首次建行口径：管理员无工作区行时代改保存自动建行，任务栏空数组/偏好默认值/台账空数组 */
    public function test_save_admin_desktop_creates_workspace_row_for_new_admin(): void
    {
        $this->actingAsSuperAdmin();

        $admin = $this->createAdmin('webos_admin_new_', '首次建行管理员');

        $this->putJson('/admin/cmspro/webos/api/admin-desktop', [
            'admin_id' => $admin->id,
            'desktop_items' => [],
        ])->assertOk()->assertJsonPath('code', 0);

        $row = DB::table('app_cmspro_webos_workspaces')
            ->where('terminal', 'admin')
            ->where('admin_user_id', $admin->id)
            ->first();

        $this->assertNotNull($row, '代改保存应按后台建行口径自动创建工作区行');
        $this->assertSame([], json_decode((string) $row->taskbar_items, true), '新建行任务栏为空数组');
        $this->assertSame([], json_decode((string) $row->preset_sync_ids, true), '新建行角色预设推送台账为空数组');
        $this->assertNotEmpty(json_decode((string) $row->preferences, true), '新建行偏好为默认值');
        $this->assertSame(1, (int) $row->status, '新建行 status 为启用');
    }

    /** 接口仅后台端提供：未登录访问返回 401，用户端不注册该路由（404） */
    public function test_admin_desktop_apis_require_admin_authentication(): void
    {
        $this->getJson('/admin/cmspro/webos/api/admin-desktop/admins')->assertUnauthorized();
        $this->getJson('/admin/cmspro/webos/api/admin-desktop?admin_id=1')->assertUnauthorized();
        $this->putJson('/admin/cmspro/webos/api/admin-desktop', ['admin_id' => 1, 'desktop_items' => []])->assertUnauthorized();

        $this->actingUser();
        $this->getJson('/user/cmspro/webos/api/admin-desktop/admins')->assertNotFound();
        $this->getJson('/user/cmspro/webos/api/admin-desktop?admin_id=1')->assertNotFound();
        $this->putJson('/user/cmspro/webos/api/admin-desktop', ['admin_id' => 1, 'desktop_items' => []])->assertNotFound();
    }

    /** 「管理桌面」搜索查看前端接线：控制器/路由/服务/脚本/视图/CSS 六处齐备（仅后台端） */
    public function test_admin_desktop_lookup_binds_frontend_wiring(): void
    {
        // 控制器：搜索管理员、读管理员桌面、代改保存三个接口
        $controller = file_get_contents(dirname(__DIR__, 2) . '/Controllers/Admin/WebosController.php');
        $this->assertIsString($controller);
        $this->assertStringContainsString('public function searchAdmins(Request $request): JsonResponse', $controller);
        $this->assertStringContainsString('public function adminWorkspaceDesktop(Request $request): JsonResponse', $controller);
        $this->assertStringContainsString('public function saveAdminWorkspaceDesktop(Request $request): JsonResponse', $controller);
        // 只读口径：查看走 getAdminWorkspace（纯查询），不得走有建行/差量同步副作用的 getForAdmin
        $this->assertStringContainsString('$workspace = $this->workspaces->getAdminWorkspace($adminId);', $controller);
        // 目录口径：超管全部开启菜单、普通管理员角色并集（+ 应用清单同口径）
        $this->assertStringContainsString('protected function adminMenuTreeForAdmin(AdminUser $admin): array', $controller);
        $this->assertStringContainsString('protected function adminApplicationListForAdmin(AdminUser $admin): array', $controller);

        // 路由：搜索/读取/保存按 RESTful 语义注册
        $routes = file_get_contents(dirname(__DIR__, 2) . '/Routes/admin.php');
        $this->assertIsString($routes);
        $this->assertStringContainsString("Route::get('/admin-desktop/admins', [WebosController::class, 'searchAdmins'])->name('admin-desktop-admins.index');", $routes);
        $this->assertStringContainsString("Route::get('/admin-desktop', [WebosController::class, 'adminWorkspaceDesktop'])->name('admin-desktop.show');", $routes);
        $this->assertStringContainsString("Route::put('/admin-desktop', [WebosController::class, 'saveAdminWorkspaceDesktop'])->name('admin-desktop.update');", $routes);

        // 服务：只读查询与代改保存（台账不动）
        $service = file_get_contents(dirname(__DIR__, 2) . '/Services/WorkspaceService.php');
        $this->assertIsString($service);
        $this->assertStringContainsString('public function getAdminWorkspace(int $adminUserId): ?WebosWorkspace', $service);
        $this->assertStringContainsString('public function saveAdminDesktopItems(int $adminUserId, array $items): WebosWorkspace', $service);

        // 脚本：搜索框/下拉/提示条、查看会话（adminId 参数）、显式保存分流与事件绑定
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $this->assertIsString($script);
        $this->assertStringContainsString('function adminDesktopBarMarkup() {', $script);
        $this->assertStringContainsString('function openAdminWorkspace(adminId) {', $script);
        $this->assertStringContainsString('function saveAdminDesktopChanges() {', $script);
        $this->assertStringContainsString('function discardAdminWorkspaceChanges() {', $script);
        $this->assertStringContainsString('function backToAdminPreset() {', $script);
        $this->assertStringContainsString('function adminDisplayLabel(admin) {', $script);
        // 查看会话参数化：enterPresetSession 以 adminId 区分角色预设编辑与查看管理员桌面
        $this->assertStringContainsString("var isAdminDesktop = type === 'admin' && !!userId;", $script);
        $this->assertStringContainsString('state.presetSession.adminId = userId;', $script);
        // 保存分流：查看管理员时编辑只落内存（置脏），由提示条按钮显式提交
        $this->assertStringContainsString("if (session.type === 'admin' && session.adminId) {", $script);
        $this->assertStringContainsString('function markAdminDesktopDirty() {', $script);
        $this->assertStringContainsString("var pick = event.target.closest('[data-admin-desktop-pick]');", $script);
        $this->assertStringContainsString("if (event.target.closest('[data-admin-desktop-save]')) {", $script);

        // Blade：管理员桌面接口地址仅后台端注入
        $view = file_get_contents(dirname(__DIR__, 2) . '/Views/Admin/desktop/index.blade.php');
        $this->assertIsString($view);
        $this->assertStringContainsString('data-admin-desktop-url="{{ $webosApiBase }}/admin-desktop"', $view);

        // CSS：角色行内搜索框与查看提示条的行内布局覆盖（间距归零 + 基准宽度）
        $css = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');
        $this->assertIsString($css);
        $this->assertStringContainsString('.preset-role-picker {', $css);
        $this->assertStringContainsString('.preset-role-row .preset-user-search {', $css);
        $this->assertStringContainsString('.preset-role-row .preset-user-banner {', $css);
    }

    /** 创建测试管理员：账号后缀 uniqid 防撞，启用状态可覆盖（禁用管理员搜索过滤用） */
    private function createAdmin(string $usernamePrefix, string $name, int $status = 1): AdminUser
    {
        return AdminUser::create([
            'username' => $usernamePrefix . uniqid(),
            'password' => bcrypt('123456'),
            'name' => $name,
            'status' => $status,
        ]);
    }

    /**
     * 递归收集菜单树节点 id（含 children），供「隐藏/停用/未授权菜单不出现」的全树比对。
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
