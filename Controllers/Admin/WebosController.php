<?php

namespace App\Apps\CmsproWebos\Controllers\Admin;

use App\Apps\CmsproWebos\Services\CalendarService;
use App\Apps\CmsproWebos\Services\WallpaperService;
use App\Apps\CmsproWebos\Services\WorkspaceService;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AdminTodoRead;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class WebosController extends Controller
{
    public function __construct(
        protected WorkspaceService $workspaces,
        protected CalendarService $calendar,
        protected WallpaperService $wallpapers
    ) {
    }

    public function index(Request $request): View
    {
        $admin = $request->user('admin');

        return view('cmspro.webos::Admin.desktop.index', [
            'admin' => $admin,
            'webosRuntime' => [
                'admin' => [
                    'id' => $admin->id,
                    'name' => $admin->name ?: $admin->username,
                    'username' => $admin->username,
                    'avatar' => $admin->avatar,
                    // 应用中心与 WebOS 升级提醒仅对超级管理员开放
                    'is_super_admin' => $admin->isSuperAdmin(),
                ],
                'csrfToken' => csrf_token(),
                'baseUrl' => url('/'),
            ],
            'marketBaseUrl' => $this->marketBaseUrl(),
            'webosAssetVersion' => $this->assetVersion(),
            // 桌面标题与开始菜单版权：系统版本号动态读取（与框架后台页脚一致），WebOS 版本号读应用 manifest
            'cmsproVersion' => system_version(),
            'webosVersion' => $this->manifestVersion(),
        ]);
    }

    /**
     * 通知中心窗口「待办」数据源：全部待办（含已读）。
     *
     * 系统聚合接口（/api/admin/notifications/panel）按契约过滤已读条目，
     * 通知中心窗口需要展示完整待办列表，因此由应用侧提供全量视图：
     * 原始条目来自系统待办 hook 聚合（NotificationService::todos，
     * 各应用通过「admin.notifications.todos」filter 接入，含权限过滤/去重/容错），
     * 已读状态读 AdminTodoRead（user_id + todo_key → seen_count），seen_count ≥ count 视为已读。
     */
    public function allTodos(): JsonResponse
    {
        $userId = (int) Auth::guard('admin')->id();
        $seenMap = AdminTodoRead::where('user_id', $userId)->pluck('seen_count', 'todo_key');

        $todos = array_map(function (array $todo) use ($seenMap) {
            $seen = min((int) ($seenMap[$todo['key']] ?? 0), (int) $todo['count']);
            $todo['seen'] = $seen;
            $todo['is_read'] = $seen >= (int) $todo['count'];

            return $todo;
        }, app(NotificationService::class)->allTodos());

        return response()->json(ApiResponse::success($todos));
    }

    /**
     * 使用已发布资源的最新修改时间刷新浏览器缓存，避免应用升级后继续执行旧版 CSS/JS。
     */
    protected function assetVersion(): int
    {
        $version = 1;

        foreach (['css/webos.css', 'js/webos.js'] as $asset) {
            $path = public_path('apps/cmspro.webos/' . $asset);
            if (is_file($path)) {
                $version = max($version, (int) filemtime($path));
            }
        }

        return $version;
    }

    /**
     * WebOS 版本号：读取应用 manifest.json 的 version 字段（桌面标题展示）。
     */
    protected function manifestVersion(): string
    {
        $manifestPath = app_path('Apps/CmsproWebos/manifest.json');
        if (is_file($manifestPath)) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (is_array($manifest) && !empty($manifest['version'])) {
                return (string) $manifest['version'];
            }
        }

        return '';
    }

    /**
     * 应用市场资源前缀：与系统应用市场视图一致，使用协议相对地址。
     */
    protected function marketBaseUrl(): string
    {
        $url = (string) config('apps.market.api_url');

        return rtrim((string) preg_replace('#^https?:#i', '', $url), '/');
    }

    public function workspace(Request $request): JsonResponse
    {
        $workspace = $this->workspaces->getForAdmin((int) $request->user('admin')->id);

        return response()->json(ApiResponse::success($workspace));
    }

    public function saveWorkspace(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'desktop_items' => ['sometimes', 'array', 'max:48'],
            'desktop_items.*.id' => ['required', 'string', 'max:80'],
            'desktop_items.*.menu_id' => ['nullable', 'integer', 'min:0'],
            'desktop_items.*.app_id' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.-]*$/'],
            'desktop_items.*.title' => ['required', 'string', 'max:60'],
            'desktop_items.*.path' => [
                'required',
                'string',
                'max:500',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! str_starts_with($value, '/') || str_starts_with($value, '//')) {
                        $fail('桌面入口路径必须是站内路径');
                    }
                },
            ],
            'desktop_items.*.icon' => ['required', 'string', 'max:100'],
            'desktop_items.*.group_title' => ['nullable', 'string', 'max:60'],
            'desktop_items.*.x' => ['required', 'integer', 'between:0,99'],
            'desktop_items.*.y' => ['required', 'integer', 'between:0,99'],
            'taskbar_items' => ['sometimes', 'array', 'max:12'],
            'taskbar_items.*.id' => ['required', 'string', 'max:80'],
            'taskbar_items.*.menu_id' => ['nullable', 'integer', 'min:0'],
            'taskbar_items.*.app_id' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.-]*$/'],
            'taskbar_items.*.title' => ['required', 'string', 'max:60'],
            'taskbar_items.*.path' => [
                'required',
                'string',
                'max:500',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! str_starts_with($value, '/') || str_starts_with($value, '//')) {
                        $fail('任务栏入口路径必须是站内路径');
                    }
                },
            ],
            'taskbar_items.*.icon' => ['required', 'string', 'max:100'],
            'taskbar_items.*.group_title' => ['nullable', 'string', 'max:60'],
            'preferences' => ['sometimes', 'array'],
            'preferences.wallpaper' => ['sometimes', 'in:webos-default,deep-blue'],
            'preferences.wallpaper_url' => ['sometimes', 'nullable', 'string', 'max:200'],
            // 桌面图标三档尺寸（右键「查看」切换）：无规则声明时 $validated 会丢弃该键，导致勾选回退中图标
            'preferences.icon_size' => ['sometimes', 'in:small,medium,large'],
            'preferences.taskbar_alignment' => ['sometimes', 'in:left,center'],
            'preferences.taskbar_position' => ['sometimes', 'in:top,bottom,left,right'],
            'preferences.clock_format' => ['sometimes', 'in:12h,24h'],
            'preferences.show_seconds' => ['sometimes', 'boolean'],
            'preferences.motion' => ['sometimes', 'boolean'],
            'preferences.window_width' => ['sometimes', 'integer', 'between:40,100'],
            'preferences.window_height' => ['sometimes', 'integer', 'between:40,100'],
            'preferences.override_admin_home' => ['sometimes', 'boolean'],
            'preferences.usage_stats' => ['sometimes', 'array', 'max:80'],
        ]);

        $workspace = $this->workspaces->saveForAdmin(
            (int) $request->user('admin')->id,
            $validated
        );

        return response()->json(ApiResponse::success($workspace, '桌面布局已保存'));
    }

    /**
     * 重置工作区（OS 设置 → 系统设置）：桌面项/任务栏项清空、偏好恢复默认。
     * 自定义壁纸文件保留，仅当前背景偏好回退系统默认。
     */
    public function resetWorkspace(Request $request): JsonResponse
    {
        $workspace = $this->workspaces->resetForAdmin((int) $request->user('admin')->id);

        return response()->json(ApiResponse::success($workspace, '工作区已恢复默认'));
    }

    /**
     * 上传自定义桌面壁纸，保存到应用发布资源目录并返回相对路径。
     * 数据库中仅保存相对路径（应用路径字段规范），前端展示时拼接站点根路径。
     */
    public function uploadWallpaper(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'wallpaper' => ['required', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:5120'],
        ]);

        $url = $this->wallpapers->store($validated['wallpaper'], (int) $request->user('admin')->id);

        return response()->json(ApiResponse::success(['url' => $url], '壁纸已上传'));
    }

    /**
     * 当前管理员已上传的自定义壁纸列表，供背景设置中切换与删除。
     */
    public function wallpapers(Request $request): JsonResponse
    {
        $wallpapers = $this->wallpapers->listFor((int) $request->user('admin')->id);

        return response()->json(ApiResponse::success($wallpapers));
    }

    /**
     * 删除当前管理员自己上传的自定义壁纸；壁纸偏好中的引用由前端负责清理。
     */
    public function destroyWallpaper(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:200'],
        ]);

        if (! $this->wallpapers->delete($validated['url'], (int) $request->user('admin')->id)) {
            return response()->json(ApiResponse::error(40302, '壁纸不存在或无权删除'));
        }

        return response()->json(ApiResponse::success([], '壁纸已删除'));
    }

    public function calendar(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        $month = $this->calendar->month($validated['month']);

        return response()->json(ApiResponse::success($month));
    }
}
