<?php

namespace App\Apps\CmsproWebos\Controllers\Admin;

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
        protected MenuService $menus
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
        ]);
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
            'preferences.taskbar_alignment' => ['sometimes', 'in:left,center'],
            'preferences.taskbar_position' => ['sometimes', 'in:top,bottom,left,right'],
            'preferences.clock_format' => ['sometimes', 'in:12h,24h'],
            'preferences.show_seconds' => ['sometimes', 'boolean'],
            'preferences.motion' => ['sometimes', 'boolean'],
        ]);

        $workspace = $this->workspaces->saveForAdmin(
            (int) $request->user('admin')->id,
            $validated
        );

        return response()->json(ApiResponse::success($workspace, '桌面布局已保存'));
    }

    public function catalog(): JsonResponse
    {
        $menuResult = $this->menus->userMenus();
        $applications = AppModel::query()
            ->installed()
            ->orderBy('name')
            ->get(['app_id', 'name', 'description', 'version', 'author', 'icon', 'status'])
            ->map(function (AppModel $app): array {
                return [
                    'app_id' => $app->app_id,
                    'name' => $app->name,
                    'description' => $app->description,
                    'version' => $app->version,
                    'author' => $app->author,
                    'icon' => $app->icon,
                    'icon_url' => url('/api/app/' . $app->app_id . '/icon'),
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
            'menus' => $menuResult['data'] ?? [],
            'applications' => $applications,
            'operation_logs' => $logs,
        ]));
    }
}
