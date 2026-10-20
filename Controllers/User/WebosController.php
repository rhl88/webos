<?php

namespace App\Apps\CmsproWebos\Controllers\User;

use App\Apps\CmsproWebos\Controllers\Admin\WebosController as AdminWebosController;
use App\Http\Responses\ApiResponse;
use App\Models\AdminMenu;
use App\Models\AppModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * WebOS 用户端控制器：复用 admin 端控制器的工作区/壁纸/日历能力，
 * 仅按 terminal 分流身份与入口渲染（UI 单版本，接口数据按端隔离）。
 *
 * 能力裁剪（用户端不提供）：应用中心、通知中心（all-todos/start-disabled-menus）、
 * 官网动态（home-menu-urls）、「覆盖传统后台」偏好——对应路由不在 Routes/user.php 注册。
 */
class WebosController extends AdminWebosController
{
    /**
     * 用户端桌面入口：未登录渲染与 admin 端同一份 WebOS 登录窗口（2.1.81，验证码场景分流 user，
     * 表单提交框架 /user/login 并携带 redirect 回跳桌面）；已登录渲染与 admin 端同一份桌面视图，
     * 身份以轻量对象注入（视图仅消费 name/username/avatar 三属性）。
     */
    public function index(Request $request): View
    {
        $user = $request->user('web');

        if (! $user) {
            return view('cmspro.webos::Admin.desktop.login', array_merge(
                $this->loginSecurity->issueCaptcha($request, 'user'),
                [
                    'webosAssetVersion' => $this->assetVersion(),
                    'webosLoginTerminal' => 'user',
                ]
            ));
        }

        return view('cmspro.webos::Admin.desktop.index', [
            'admin' => (object) [
                'name' => $user->nickname ?: $user->username,
                'username' => $user->username,
                'avatar' => $user->avatar,
            ],
            'webosRuntime' => [
                // 终端标识：前端按 admin/user 分流菜单源、API 前缀与能力开关
                'terminal' => 'user',
                'admin' => [
                    'id' => $user->id,
                    'name' => $user->nickname ?: $user->username,
                    'username' => $user->username,
                    'avatar' => $user->avatar,
                    // 用户端无应用中心与升级提醒能力
                    'is_super_admin' => false,
                ],
                'csrfToken' => csrf_token(),
                'baseUrl' => url('/'),
            ],
            // 应用市场资源前缀仅 admin 端应用中心使用，用户端传空
            'marketBaseUrl' => '',
            'webosAssetVersion' => $this->assetVersion(),
            // 桌面标题与开始菜单版权：系统版本号动态读取，WebOS 版本号读应用 manifest
            'cmsproVersion' => system_version(),
            'webosVersion' => $this->manifestVersion(),
        ]);
    }

    /** 当前用户标识（姓名/账号/头像）：前台用户资料字段为 nickname，与 admin 端 name 对应 */
    public function adminIdentity(Request $request): JsonResponse
    {
        $user = $request->user('web');

        return response()->json(ApiResponse::success([
            'name' => $user->nickname ?: $user->username,
            'username' => $user->username,
            'avatar' => $user->avatar,
        ]));
    }

    /** 用户端工作区终端标识固定 user */
    protected function terminalOf(Request $request): string
    {
        return 'user';
    }

    /** 用户端工作区归属取前台用户 ID */
    protected function userIdOf(Request $request): int
    {
        return (int) $request->user('web')->id;
    }

    /**
     * 用户端开始菜单数据源：与框架 admin 端 /api/admin/menus/user 输出同构的树形结构
     * （AdminMenu 全字段 + children），前端 flattenMenus 无需感知终端差异。
     * 与框架用户端菜单接口（User\ConfigController::menus）口径一致：
     * terminal_type=user、status/visible 开启即全员可见（用户端菜单不做角色授权过滤）。
     */
    public function menus(): JsonResponse
    {
        $menus = AdminMenu::query()
            ->where('status', 1)
            ->where('visible', 1)
            ->where('terminal_type', 'user')
            ->orderBy('sort')
            ->get()
            ->toArray();

        return response()->json(ApiResponse::success($this->buildMenuTree($menus)));
    }

    /**
     * 用户端可见应用清单：开始菜单、桌面入口选择树、桌面/任务栏图标、窗口标题与品牌图标等渲染点
     * 统一依赖应用的名称与图标（前端 findApplication 数据源），缺少该清单会退化为显示 app_id 与文件夹图标。
     * 数据源与 admin 端 /api/admin/apps 同构（apps 表 name/icon + 公开图标路由 icon_url），
     * 但仅返回当前用户菜单中出现过 app_id 的应用，不暴露未在用户端分配的应用。
     */
    public function applications(): JsonResponse
    {
        $appIds = AdminMenu::query()
            ->where('terminal_type', 'user')
            ->where('status', 1)
            ->where('visible', 1)
            ->whereNotNull('app_id')
            ->where('app_id', '!=', '')
            ->distinct()
            ->pluck('app_id')
            ->all();

        if (! $appIds) {
            return response()->json(ApiResponse::success([]));
        }

        $applications = AppModel::query()
            ->whereIn('app_id', $appIds)
            ->orderBy('id', 'asc')
            ->get()
            ->map(function (AppModel $app) {
                $data = $app->only(['app_id', 'name', 'icon', 'version', 'status', 'is_system']);
                // 应用图标公开路由（无需认证），与 admin 端 getInstalledApps 同一口径
                $data['icon_url'] = url('/api/app/' . $app->app_id . '/icon');
                return $data;
            })
            ->values()
            ->all();

        return response()->json(ApiResponse::success($applications));
    }

    /** 按 parent_id 组树（AdminMenu 全字段直传，与前端 flattenMenus 契约一致） */
    private function buildMenuTree(array $items, int $parentId = 0): array
    {
        $tree = [];

        foreach ($items as $item) {
            if ((int) ($item['parent_id'] ?? 0) !== $parentId) {
                continue;
            }

            $children = $this->buildMenuTree($items, (int) $item['id']);
            if ($children) {
                $item['children'] = $children;
            }

            $tree[] = $item;
        }

        return $tree;
    }

    /** 会话恢复：419 后静默换取新 CSRF Token（与框架 admin 端 AuthController::csrfToken 行为一致） */
    public function csrfToken(): JsonResponse
    {
        return response()->json(ApiResponse::success([
            'token' => csrf_token(),
        ]));
    }

    /** 当前登录用户标识（昵称/账号/头像），供 JS 会话心跳与恢复层轮询登录态 */
    public function identity(): JsonResponse
    {
        $user = Auth::guard('web')->user();

        return response()->json(ApiResponse::success([
            'name' => $user->nickname ?: $user->username,
            'username' => $user->username,
            'avatar' => $user->avatar,
        ]));
    }
}
