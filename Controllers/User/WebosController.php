<?php

namespace App\Apps\CmsproWebos\Controllers\User;

use App\Apps\CmsproWebos\Controllers\Admin\WebosController as AdminWebosController;
use App\Http\Responses\ApiResponse;
use App\Services\MenuService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * WebOS 用户端控制器：复用 admin 端控制器的工作区/壁纸/日历能力，
 * 仅按 terminal 分流身份与入口渲染（UI 单版本，接口数据按端隔离）。
 *
 * 能力裁剪（用户端不提供）：应用中心、通知中心（all-todos/start-disabled-menus）、
 * 「覆盖传统后台」偏好——对应路由不在 Routes/user.php 注册。
 * 窗口前台菜单（menus/tree、home-menu-urls）由用户端自行提供，避免请求 admin 受保护接口。
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
     * 用户端开始菜单数据源：复用父类（admin 控制器）的前台用户菜单树查询，
     * 后台「用户桌面」预设编辑器与前台使用同一口径，避免两端菜单实现分叉。
     */
    public function menus(): JsonResponse
    {
        return $this->userTerminalMenus();
    }

    /**
     * 窗口「前台菜单」数据源：home 终端菜单树。
     *
     * 与框架 /api/admin/menus/tree?terminal_type=home 完全同构（复用框架 MenuService::tree，
     * 含 menu.rendering 渲染钩子），仅返回 terminal_type=home 菜单，不泄露后台菜单；
     * 用户端不得请求 admin 受保护接口，否则未登录后台时会触发会话恢复层误报「会话过期」。
     */
    public function homeMenuTree(MenuService $menuService): JsonResponse
    {
        return response()->json($menuService->tree('home'));
    }

    /**
     * 用户端可见应用清单（开始菜单/桌面入口选择树/图标渲染的 findApplication 数据源）：
     * 复用父类的前台用户应用清单查询。
     */
    public function applications(): JsonResponse
    {
        return $this->userTerminalApplications();
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
