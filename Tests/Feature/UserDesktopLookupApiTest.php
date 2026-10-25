<?php

namespace App\Apps\CmsproWebos\Tests\Feature;

use App\Apps\CmsproWebos\Services\WorkspaceService;
use App\Apps\CmsproWebos\Tests\WebosTestCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 「用户桌面」搜索查看/代改指定前台用户桌面（2.1.99）：
 * 搜索口径（用户名/昵称模糊 + 禁用过滤）、只读查看（不建行不触发差量同步）、
 * 代改保存（仅覆盖 desktop_items，台账/任务栏不动）、前端接线。
 */
class UserDesktopLookupApiTest extends WebosTestCase
{
    /** 搜索口径：用户名/昵称模糊匹配，禁用用户被过滤，仅返回 id/username/nickname 最小字段 */
    public function test_search_users_matches_username_and_nickname_and_filters_disabled(): void
    {
        $this->actingAsSuperAdmin();

        $hitByUsername = User::create([
            'username' => 'webos_lookup_zhang' . uniqid(),
            'password' => bcrypt('123456'),
            'nickname' => '张三',
            'status' => 1,
        ]);
        $hitByNickname = User::create([
            'username' => 'webos_lookup_li' . uniqid(),
            'password' => bcrypt('123456'),
            'nickname' => '搜索昵称李四',
            'status' => 1,
        ]);
        // 禁用用户：即便关键字命中也不得出现在结果中
        User::create([
            'username' => 'webos_lookup_disabled' . uniqid(),
            'password' => bcrypt('123456'),
            'nickname' => '已停用王五',
            'status' => 0,
        ]);

        $response = $this->getJson('/admin/cmspro/webos/api/user-desktop/users?keyword=' . urlencode('zhang'))
            ->assertOk()
            ->assertJsonPath('code', 0);

        $users = collect($response->json('data'));
        $this->assertSame([$hitByUsername->id], $users->pluck('id')->all(), '用户名命中且禁用用户被过滤');
        $this->assertSame(['id', 'username', 'nickname'], array_keys($users->first()), '仅返回最小字段，不暴露敏感信息');

        $this->getJson('/admin/cmspro/webos/api/user-desktop/users?keyword=' . urlencode('昵称李四'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $hitByNickname->id);

        // 关键字命中禁用用户的用户名：结果为空（禁用过滤先于模糊匹配）
        $this->getJson('/admin/cmspro/webos/api/user-desktop/users?keyword=' . urlencode('webos_lookup_disabled'))
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    /** 只读查看：无工作区行时返回空列表且不建行；有行时返回桌面/任务栏数据 */
    public function test_user_desktop_is_read_only_and_creates_no_row(): void
    {
        $this->actingAsSuperAdmin();

        $user = User::create([
            'username' => 'webos_lookup_read' . uniqid(),
            'password' => bcrypt('123456'),
            'nickname' => '只读查看用户',
            'status' => 1,
        ]);

        // 用户尚无工作区行：图标/任务栏返回空列表
        $this->getJson('/admin/cmspro/webos/api/user-desktop?user_id=' . $user->id)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.desktop_items', [])
            ->assertJsonPath('data.taskbar_items', [])
            ->assertJsonStructure(['data' => ['user', 'desktop_items', 'taskbar_items', 'catalog' => ['menus', 'applications']]]);

        // 只读原则：查看不创建工作区行（getFor 的 firstOrCreate 副作用不可接受）
        $this->assertSame(0, DB::table('app_cmspro_webos_workspaces')
            ->where('terminal', 'user')
            ->where('admin_user_id', $user->id)
            ->count(), '查看用户桌面不得创建工作区行');

        // 用户已有工作区行：返回其桌面图标与任务栏固定项
        $service = app(WorkspaceService::class);
        $workspace = $service->getFor('user', $user->id);
        $workspace->desktop_items = [[
            'id' => 'user-desktop-entry',
            'title' => '我的入口',
            'path' => '/user/some-page',
            'icon' => 'fa fa-star',
            'x' => 0,
            'y' => 0,
        ]];
        $workspace->taskbar_items = [['id' => 'user-taskbar', 'title' => '任务栏固定项']];
        $workspace->save();

        $this->getJson('/admin/cmspro/webos/api/user-desktop?user_id=' . $user->id)
            ->assertOk()
            ->assertJsonPath('data.desktop_items.0.id', 'user-desktop-entry')
            ->assertJsonPath('data.taskbar_items.0.id', 'user-taskbar');

        // user_id 强校验：必填且必须是存在的前台用户
        $this->getJson('/admin/cmspro/webos/api/user-desktop')->assertStatus(422);
        $this->getJson('/admin/cmspro/webos/api/user-desktop?user_id=999999')->assertStatus(422);
    }

    /** 代改保存往返：仅覆盖 desktop_items，任务栏固定项/偏好/预设推送台账保持不动 */
    public function test_save_user_desktop_overwrites_items_only_and_keeps_taskbar_and_ledger(): void
    {
        $this->actingAsSuperAdmin();

        $user = User::create([
            'username' => 'webos_lookup_save' . uniqid(),
            'password' => bcrypt('123456'),
            'nickname' => '代改保存用户',
            'status' => 1,
        ]);

        // 用户已有工作区行：含任务栏固定项与已推送预设台账
        $service = app(WorkspaceService::class);
        $workspace = $service->getFor('user', $user->id);
        $workspace->taskbar_items = [['id' => 'user-taskbar-keep', 'title' => '须保留的任务栏项']];
        $workspace->preset_sync_ids = ['preset-pushed-a', 'preset-pushed-b'];
        $workspace->desktop_items = [[
            'id' => 'old-item',
            'title' => '旧图标',
            'path' => '/user/old',
            'icon' => 'fa fa-circle',
            'x' => 0,
            'y' => 0,
        ]];
        $workspace->save();

        $this->putJson('/admin/cmspro/webos/api/user-desktop', [
            'user_id' => $user->id,
            'desktop_items' => [
                [
                    'id' => 'new-item',
                    'title' => '新图标',
                    'path' => '/user/new',
                    'icon' => 'fa fa-star',
                    'x' => 0,
                    'y' => 0,
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('message', '用户桌面已保存')
            ->assertJsonPath('data.desktop_items.0.id', 'new-item');

        $row = DB::table('app_cmspro_webos_workspaces')
            ->where('terminal', 'user')
            ->where('admin_user_id', $user->id)
            ->first();

        $this->assertNotNull($row);
        $desktopItems = json_decode((string) $row->desktop_items, true);
        $this->assertSame(['new-item'], array_column($desktopItems, 'id'), 'desktop_items 已被代改覆盖');
        $this->assertSame([['id' => 'user-taskbar-keep', 'title' => '须保留的任务栏项']], json_decode((string) $row->taskbar_items, true), '任务栏固定项保持不动');
        $this->assertSame(['preset-pushed-a', 'preset-pushed-b'], json_decode((string) $row->preset_sync_ids, true), '预设推送台账保持不动（差量同步规则不受代改影响）');

        // 校验口径与工作区保存一致：user_id 必填且存在、desktop_items 结构强校验
        $this->putJson('/admin/cmspro/webos/api/user-desktop', ['desktop_items' => []])->assertStatus(422);
        $this->putJson('/admin/cmspro/webos/api/user-desktop', ['user_id' => 999999, 'desktop_items' => []])->assertStatus(422);
        $this->putJson('/admin/cmspro/webos/api/user-desktop', [
            'user_id' => $user->id,
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

    /** 首次建行口径：用户无工作区行时代改保存自动建行，任务栏空数组/偏好默认值/台账空数组 */
    public function test_save_user_desktop_creates_workspace_row_for_new_user(): void
    {
        $this->actingAsSuperAdmin();

        $user = User::create([
            'username' => 'webos_lookup_new' . uniqid(),
            'password' => bcrypt('123456'),
            'nickname' => '首次建行用户',
            'status' => 1,
        ]);

        $this->putJson('/admin/cmspro/webos/api/user-desktop', [
            'user_id' => $user->id,
            'desktop_items' => [],
        ])->assertOk()->assertJsonPath('code', 0);

        $row = DB::table('app_cmspro_webos_workspaces')
            ->where('terminal', 'user')
            ->where('admin_user_id', $user->id)
            ->first();

        $this->assertNotNull($row, '代改保存应按用户端建行口径自动创建工作区行');
        $this->assertSame([], json_decode((string) $row->taskbar_items, true), '新建行任务栏为空数组');
        $this->assertSame([], json_decode((string) $row->preset_sync_ids, true), '新建行预设推送台账为空数组');
        $this->assertNotEmpty(json_decode((string) $row->preferences, true), '新建行偏好为默认值');
        $this->assertSame(1, (int) $row->status, '新建行 status 为启用');
    }

    /** 接口仅后台端提供：未登录访问返回 401，用户端不注册该路由（404） */
    public function test_user_desktop_apis_require_admin_authentication(): void
    {
        $this->getJson('/admin/cmspro/webos/api/user-desktop/users')->assertUnauthorized();
        $this->getJson('/admin/cmspro/webos/api/user-desktop?user_id=1')->assertUnauthorized();
        $this->putJson('/admin/cmspro/webos/api/user-desktop', ['user_id' => 1, 'desktop_items' => []])->assertUnauthorized();

        $this->actingUser();
        $this->getJson('/user/cmspro/webos/api/user-desktop/users')->assertNotFound();
        $this->getJson('/user/cmspro/webos/api/user-desktop?user_id=1')->assertNotFound();
        $this->putJson('/user/cmspro/webos/api/user-desktop', ['user_id' => 1, 'desktop_items' => []])->assertNotFound();
    }

    /** 「用户桌面」搜索查看前端接线：控制器/路由/脚本/视图/CSS 五处齐备（仅后台端） */
    public function test_user_desktop_lookup_binds_frontend_wiring(): void
    {
        // 控制器：搜索用户、读用户桌面、代改保存三个接口
        $controller = file_get_contents(dirname(__DIR__, 2) . '/Controllers/Admin/WebosController.php');
        $this->assertIsString($controller);
        $this->assertStringContainsString('public function searchUsers(Request $request): JsonResponse', $controller);
        $this->assertStringContainsString('public function userDesktop(Request $request): JsonResponse', $controller);
        $this->assertStringContainsString('public function saveUserDesktop(Request $request): JsonResponse', $controller);
        // 只读口径：查看走 getUserWorkspace（纯查询），不得走有建行/差量同步副作用的 getFor
        $this->assertStringContainsString('$workspace = $this->workspaces->getUserWorkspace($userId);', $controller);

        // 路由：搜索/读取/保存按 RESTful 语义注册
        $routes = file_get_contents(dirname(__DIR__, 2) . '/Routes/admin.php');
        $this->assertIsString($routes);
        $this->assertStringContainsString("Route::get('/user-desktop/users', [WebosController::class, 'searchUsers'])->name('user-desktop-users.index');", $routes);
        $this->assertStringContainsString("Route::get('/user-desktop', [WebosController::class, 'userDesktop'])->name('user-desktop.show');", $routes);
        $this->assertStringContainsString("Route::put('/user-desktop', [WebosController::class, 'saveUserDesktop'])->name('user-desktop.update');", $routes);

        // 服务：只读查询与代改保存（台账不动）
        $service = file_get_contents(dirname(__DIR__, 2) . '/Services/WorkspaceService.php');
        $this->assertIsString($service);
        $this->assertStringContainsString('public function getUserWorkspace(int $userId): ?WebosWorkspace', $service);
        $this->assertStringContainsString('public function saveUserDesktopItems(int $userId, array $items): WebosWorkspace', $service);

        // 脚本：搜索框/下拉/提示条、查看会话（userId 参数）、显式保存分流与事件绑定
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $this->assertIsString($script);
        $this->assertStringContainsString('function presetUserBarMarkup() {', $script);
        $this->assertStringContainsString('function openUserDesktop(userId) {', $script);
        $this->assertStringContainsString('function saveUserDesktopChanges() {', $script);
        $this->assertStringContainsString('function discardUserDesktopChanges() {', $script);
        $this->assertStringContainsString('function backToUserPreset() {', $script);
        // 查看会话参数化：enterPresetSession 支持 userId，会话与提示条按目标用户渲染
        $this->assertStringContainsString('function enterPresetSession(type, userId) {', $script);
        $this->assertStringContainsString("state.presetSession.userId = userId;", $script);
        // 保存分流：查看用户时编辑只落内存（置脏），由提示条按钮显式提交
        $this->assertStringContainsString("if (session.type === 'user' && session.userId) {", $script);
        $this->assertStringContainsString('function markUserDesktopDirty() {', $script);
        $this->assertStringContainsString("var pick = event.target.closest('[data-preset-user-pick]');", $script);
        $this->assertStringContainsString("if (event.target.closest('[data-preset-user-save]')) {", $script);

        // Blade：用户桌面接口地址仅后台端注入
        $view = file_get_contents(dirname(__DIR__, 2) . '/Views/Admin/desktop/index.blade.php');
        $this->assertIsString($view);
        $this->assertStringContainsString('data-user-desktop-url="{{ $webosApiBase }}/user-desktop"', $view);

        // CSS：搜索框、结果下拉与查看提示条样式
        $css = file_get_contents(dirname(__DIR__, 2) . '/Assets/css/webos.css');
        $this->assertIsString($css);
        $this->assertStringContainsString('.preset-user-search {', $css);
        $this->assertStringContainsString('.preset-user-dropdown {', $css);
        $this->assertStringContainsString('.preset-user-banner {', $css);
    }
}
