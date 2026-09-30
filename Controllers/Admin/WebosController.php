<?php

namespace App\Apps\CmsproWebos\Controllers\Admin;

use App\Apps\CmsproWebos\Services\AdminMenuCatalogService;
use App\Apps\CmsproWebos\Services\ApplicationIconService;
use App\Apps\CmsproWebos\Services\CalendarService;
use App\Apps\CmsproWebos\Services\WallpaperService;
use App\Apps\CmsproWebos\Services\WorkspaceService;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AppModel;
use App\Models\AppOperationLog;
use App\Services\MenuService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WebosController extends Controller
{
    public function __construct(
        protected WorkspaceService $workspaces,
        protected MenuService $menus,
        protected AdminMenuCatalogService $menuCatalog,
        protected ApplicationIconService $applicationIcons,
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
                ],
                'csrfToken' => csrf_token(),
                'baseUrl' => url('/'),
            ],
            'marketBaseUrl' => $this->marketBaseUrl(),
            'webosAssetVersion' => $this->assetVersion(),
        ]);
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
            'preferences' => ['sometimes', 'array'],
            'preferences.wallpaper' => ['sometimes', 'in:webos-default,deep-blue'],
            'preferences.wallpaper_url' => ['sometimes', 'nullable', 'string', 'max:200'],
            'preferences.taskbar_alignment' => ['sometimes', 'in:left,center'],
            'preferences.taskbar_position' => ['sometimes', 'in:top,bottom,left,right'],
            'preferences.clock_format' => ['sometimes', 'in:12h,24h'],
            'preferences.show_seconds' => ['sometimes', 'boolean'],
            'preferences.motion' => ['sometimes', 'boolean'],
            'preferences.window_width' => ['sometimes', 'integer', 'between:40,100'],
            'preferences.window_height' => ['sometimes', 'integer', 'between:40,100'],
            'preferences.usage_stats' => ['sometimes', 'array', 'max:80'],
        ]);

        $workspace = $this->workspaces->saveForAdmin(
            (int) $request->user('admin')->id,
            $validated
        );

        return response()->json(ApiResponse::success($workspace, '桌面布局已保存'));
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

    public function catalog(): JsonResponse
    {
        $menuResult = $this->menus->userMenus();
        $applications = AppModel::query()
            ->installed()
            ->get([
                'app_id', 'name', 'description', 'version', 'author', 'icon',
                'status', 'is_system', 'path', 'manifest', 'install_time', 'update_time',
            ])
            // 与后台“应用管理”列表排序一致：按安装时间与更新时间中较新者降序（最新在前），无时间记录排最后
            ->sort(function (AppModel $left, AppModel $right): int {
                $leftTime = $this->recentTimeOf($left);
                $rightTime = $this->recentTimeOf($right);
                if ($leftTime === $rightTime) {
                    return 0;
                }
                if ($leftTime === '') {
                    return 1;
                }
                if ($rightTime === '') {
                    return -1;
                }

                return $leftTime < $rightTime ? 1 : -1;
            })
            ->map(function (AppModel $app): array {
                $manifest = $app->manifest ?? [];

                return [
                    'app_id' => $app->app_id,
                    'name' => $app->name,
                    'description' => $app->description,
                    'version' => $app->version,
                    'author' => $app->author,
                    'icon' => $app->icon,
                    'icon_url' => url('/api/app/' . $app->app_id . '/icon'),
                    'manifest_icon' => $this->applicationIcons->resolveManifestIcon(
                        $app->resolvePath(),
                        $manifest
                    ),
                    'has_config' => ! empty($manifest['config_groups']),
                    'is_system' => $app->is_system,
                    'status' => $app->status->value,
                    'status_label' => $app->status->label(),
                ];
            })
            ->values();

        $logs = AppOperationLog::query()
            ->with('operator:id,name,username')
            ->orderByDesc('create_time')
            ->limit(30)
            ->get();

        return response()->json(ApiResponse::success([
            'menus' => $this->menuCatalog->filterAdminMenus($menuResult['data'] ?? []),
            'applications' => $applications,
            'operation_logs' => $logs,
        ]));
    }

    /** 取应用参与排序的时间：安装时间与更新时间中较新者（与后台“应用管理”默认排序一致），无时间返回空串 */
    protected function recentTimeOf(AppModel $app): string
    {
        $installTime = $app->install_time?->format('Y-m-d H:i:s') ?? '';
        $updateTime = $app->update_time?->format('Y-m-d H:i:s') ?? '';

        return $installTime > $updateTime ? $installTime : $updateTime;
    }
}
