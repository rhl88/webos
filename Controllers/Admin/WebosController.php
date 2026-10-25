<?php

namespace App\Apps\CmsproWebos\Controllers\Admin;

use App\Apps\CmsproWebos\Services\CalendarService;
use App\Apps\CmsproWebos\Services\PageMetaService;
use App\Apps\CmsproWebos\Services\WallpaperService;
use App\Apps\CmsproWebos\Services\WebosNotificationService;
use App\Apps\CmsproWebos\Services\WorkspaceService;
use App\Enums\AppStatus;
use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AdminMenu;
use App\Models\AdminRole;
use App\Models\AdminTodoRead;
use App\Models\AdminUser;
use App\Models\AppModel;
use App\Models\User;
use App\Services\LoginSecurityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class WebosController extends Controller
{
    public function __construct(
        protected WorkspaceService $workspaces,
        protected CalendarService $calendar,
        protected WallpaperService $wallpapers,
        protected PageMetaService $pageMeta,
        protected LoginSecurityService $loginSecurity
    ) {
    }

    // 返回类型不声明：admin 端恒返回 View，user 端子类未登录分支返回 RedirectResponse（协变限制）
    public function index(Request $request)
    {
        $admin = $request->user('admin');

        // 未登录：渲染 WebOS 登录窗口（2.1.77）——Windows 风格登录页承载于应用自有路由与
        // 自有视图/样式（零 layui 依赖），认证逻辑仍走框架 /admin/login（CSRF/验证码/锁定/
        // 记住登录全套复用），表单携带 redirect=/admin/cmspro/webos，登录成功 302 直达桌面
        if (! $admin) {
            return view('cmspro.webos::Admin.desktop.login', array_merge(
                $this->loginSecurity->issueCaptcha($request, 'admin'),
                ['webosAssetVersion' => $this->assetVersion()]
            ));
        }

        // URL/表单直达启动（2.1.102）：携带 app=应用标识 时注入 launch（GET query 与 POST 表单同口径），
        // 仅在参数合法时写入，避免页面脚本出现 "launch":null
        $webosRuntime = [
            // 终端标识：前端按 admin/user 分流菜单源、API 前缀与能力开关
            'terminal' => 'admin',
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
        ];
        $launch = $this->launchParams($request);
        if ($launch !== null) {
            $webosRuntime['launch'] = $launch;
        }

        return view('cmspro.webos::Admin.desktop.index', [
            'admin' => $admin,
            'webosRuntime' => $webosRuntime,
            'marketBaseUrl' => $this->marketBaseUrl(),
            'webosAssetVersion' => $this->assetVersion(),
            // 桌面标题与开始菜单版权：系统版本号动态读取（与框架后台页脚一致），WebOS 版本号读应用 manifest
            'cmsproVersion' => system_version(),
            'webosVersion' => $this->manifestVersion(),
        ]);
    }

    /**
     * URL/表单直达启动参数（2.1.102）：app=应用标识、to=菜单路径（可选，完整 path
     * 与尾段兜底的匹配口径由前端执行）。携带 app 时返回 launch 结构注入 webosRuntime，
     * 前端桌面就绪后自动打开对应应用窗口；匹配仅限当前账号已授权菜单（flatMenus），
     * 无权限或停用的应用自然匹配不到。app 截断 64 字符、to 截断 255 字符，防垃圾参数撑爆页面。
     *
     * @return array{app: string, to: string}|null
     */
    protected function launchParams(Request $request): ?array
    {
        $app = $request->input('app');
        if (! is_string($app) || $app === '') {
            return null;
        }

        $to = $request->input('to');

        return [
            'app' => mb_substr($app, 0, 64),
            'to' => is_string($to) && $to !== '' ? mb_substr($to, 0, 255) : '',
        ];
    }

    /**
     * 内置浏览器页面元信息（title/favicon）：跨域 iframe 受同源策略限制读不到，
     * 由后端代理抓取（结果缓存 1 天）；失败返回空值，前端回退域名与默认图标。
     */
    public function pageMeta(Request $request): JsonResponse
    {
        return response()->json(ApiResponse::success($this->pageMeta->fetch((string) $request->query('url', ''))));
    }

    /**
     * 当前管理员标识（姓名/账号/头像）：头像与姓名可在个人设置窗口（/admin/account）内修改，
     * 窗口关闭后前端重新拉取本接口，同步更新任务栏账号按钮与账号菜单面板两处展示。
     */
    public function adminIdentity(Request $request): JsonResponse
    {
        $admin = $request->user('admin');

        return response()->json(ApiResponse::success([
            'name' => $admin->name ?: $admin->username,
            'username' => $admin->username,
            'avatar' => $admin->avatar,
        ]));
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

        // 全量待办走应用内子类公开（全局服务线上版本无 allTodos 包装方法，子类公开 protected 聚合能力）
        return response()->json(ApiResponse::success(array_map(function (array $todo) use ($seenMap) {
            $seen = min((int) ($seenMap[$todo['key']] ?? 0), (int) $todo['count']);
            $todo['seen'] = $seen;
            $todo['is_read'] = $seen >= (int) $todo['count'];

            return $todo;
        }, app(WebosNotificationService::class)->allTodos())));
    }

    /**
     * 当前管理员可见的「停用应用菜单」扁平列表（开始菜单灰化展示用）。
     *
     * 应用停用时框架会把其菜单联动置为禁用（AppManagerService::disableMenus），
     * /api/admin/menus/user 因此不再返回这些菜单，开始菜单无从灰化展示停用应用；
     * 本接口按 userMenus 相同的可见性口径（超管全量 / 普通管理员按角色授权），
     * 仅返回「已停用应用」的禁用菜单，由前端并入开始菜单数据源（桌面仍隐藏停用应用）。
     */
    public function startDisabledMenus(): JsonResponse
    {
        $disabledAppIds = AppModel::query()
            ->where('status', '!=', AppStatus::ENABLED->value)
            ->pluck('app_id');
        if ($disabledAppIds->isEmpty()) {
            return response()->json(ApiResponse::success([]));
        }

        $user = Auth::guard('admin')->user();
        $query = AdminMenu::query()
            ->whereIn('app_id', $disabledAppIds)
            ->where('status', Status::DISABLED->value)
            ->where('visible', 1)
            ->orderBy('sort', 'asc');
        if (! $user->isSuperAdmin()) {
            // 与 userMenus 一致：普通管理员仅返回角色已授权的菜单
            $query->whereHas('roles', function ($query) use ($user) {
                $query->whereIn('admin_roles.id', $user->roles->pluck('id'));
            });
        }

        return response()->json(ApiResponse::success(
            $query->get(['id', 'parent_id', 'app_id', 'name', 'path', 'icon', 'open_type', 'sort'])->toArray()
        ));
    }

    /**
     * 用户端开始菜单数据源（前台用户菜单树）：与框架 admin 端 /api/admin/menus/user 输出同构的树形结构
     * （AdminMenu 全字段 + children），前端 flattenMenus 无需感知终端差异。
     * 与框架用户端菜单接口（User\ConfigController::menus）口径一致：
     * terminal_type=user、status/visible 开启即全员可见（用户端菜单不做角色授权过滤）。
     * 定义于 admin 控制器：后台「用户桌面」预设编辑器需要前台用户端目录，
     * 子类 User\WebosController 直接复用（避免后台会话跨端调用用户端接口触发前台认证）。
     */
    protected function userTerminalMenus(): JsonResponse
    {
        return response()->json(ApiResponse::success($this->userTerminalMenuTree()));
    }

    /** @return array<int,array<string,mixed>> 前台用户端菜单树 */
    protected function userTerminalMenuTree(): array
    {
        $menus = AdminMenu::query()
            ->where('status', 1)
            ->where('visible', 1)
            ->where('terminal_type', 'user')
            ->orderBy('sort')
            ->get()
            ->toArray();

        return $this->buildMenuTree($menus);
    }

    /** 按 parent_id 组树（AdminMenu 全字段直传，与前端 flattenMenus 契约一致） */
    protected function buildMenuTree(array $items, int $parentId = 0): array
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

    /**
     * 用户端可见应用清单：开始菜单、桌面入口选择树、桌面/任务栏图标、窗口标题与品牌图标等渲染点
     * 统一依赖应用的名称与图标（前端 findApplication 数据源），缺少该清单会退化为显示 app_id 与文件夹图标。
     * 数据源与 admin 端 /api/admin/apps 同构（apps 表 name/icon + 公开图标路由 icon_url），
     * 但仅返回当前用户菜单中出现过 app_id 的应用，不暴露未在用户端分配的应用。
     */
    protected function userTerminalApplications(): JsonResponse
    {
        return response()->json(ApiResponse::success($this->userTerminalApplicationList()));
    }

    /** @return array<int,array<string,mixed>> 前台用户端可见应用清单 */
    protected function userTerminalApplicationList(): array
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

        return $this->applicationListByAppIds($appIds);
    }

    /**
     * 前台菜单 URL 兼容转换：按 app_id 返回其 home 终端菜单的「原始路径 → 访问地址」映射。
     *
     * WebOS 前台菜单从系统菜单树接口拿到的是原始存储路径（如论坛 /forum），当应用设置为
     * 域名绑定模式时该路径在主站不可达，需换算为绑定域名；子域名部署场景还需换算主站绝对地址。
     * 复用框架 menu_path() 完成转换，与框架后台前台菜单（admin.blade.php）行为完全一致：
     * 域名绑定应用 → 绑定域名根 URL；普通应用 → main_url 主站地址；外链原样返回。
     */
    public function homeMenuUrls(Request $request): JsonResponse
    {
        $appIds = collect((array) $request->input('app_ids', []))
            ->filter(fn ($id): bool => is_string($id) && preg_match('/^[A-Za-z0-9_.-]{1,100}$/', $id) === 1)
            ->unique()
            ->values()
            ->take(50);

        $result = [];
        if ($appIds->isNotEmpty()) {
            $menus = AdminMenu::query()
                ->whereIn('app_id', $appIds->all())
                ->where('terminal_type', 'home')
                ->where('status', 1)
                ->where('visible', 1)
                ->orderBy('sort')
                ->get(['app_id', 'path', 'terminal_type']);

            foreach ($menus as $menu) {
                if (! $menu->path) {
                    continue;
                }
                $result[$menu->app_id][] = [
                    'path' => $menu->path,
                    'url' => menu_path($menu->path, $menu),
                ];
            }
        }

        return response()->json(ApiResponse::success($result));
    }

    /**
     * 使用已发布资源的最新修改时间刷新浏览器缓存，避免应用升级后继续执行旧版 CSS/JS。
     */
    protected function assetVersion(): int
    {
        $version = 1;

        foreach (['css/webos.css', 'js/webos.js', 'js/webos-icon-catalog.js'] as $asset) {
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

    /** 桌面项字段校验规则（工作区保存与「用户桌面」预设保存共用同一口径） */
    protected function desktopItemsRules(): array
    {
        return [
            'desktop_items' => ['sometimes', 'array', 'max:48'],
            'desktop_items.*.id' => ['required', 'string', 'max:80'],
            'desktop_items.*.menu_id' => ['nullable', 'integer', 'min:0'],
            'desktop_items.*.app_id' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.-]*$/'],
            'desktop_items.*.title' => ['required', 'string', 'max:60'],
            // 桌面项类型与所属文件夹：无规则声明时 $validated 会丢弃该键，服务层收不到 kind 会把
            // 文件夹（空 path）当普通入口拒绝；parent_folder 非法字符由服务层净化（与 id 处理风格一致）
            'desktop_items.*.kind' => ['nullable', 'in:folder,url'],
            'desktop_items.*.parent_folder' => ['nullable', 'string', 'max:64'],
            // 内置浏览器入口（id=webos-browser）path 为空（内容由前端管理、按 id 特判打开）仅该 id 放行；
            // 桌面文件夹（kind=folder）path 不参与打开逻辑（服务层强制置空）、URL 链接（kind=url）放行 http(s) 外链；
            // 注意不能用 nullable/string：空串经 ConvertEmptyStringsToNull 转 null 后规则被跳过或误报，
            // 普通/超长/外链路径与空路径的裁决全部收进闭包（服务层白名单再兜底一层）
            'desktop_items.*.path' => [
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    $kind = (string) request()->input(preg_replace('/\.path$/', '.kind', $attribute), '');
                    if ($kind === 'folder') {
                        return;
                    }
                    if ($kind === 'url') {
                        if (! is_string($value) || mb_strlen($value) > 500 || ! preg_match('/^https?:\/\/[^\s]+$/i', $value)) {
                            $fail('URL 链接必须是 http(s) 地址');
                        }
                        return;
                    }
                    if ($value === null || $value === '') {
                        if ((string) request()->input(preg_replace('/\.path$/', '.id', $attribute)) !== 'webos-browser') {
                            $fail('桌面入口路径必须是站内路径');
                        }
                        return;
                    }
                    if (! is_string($value) || mb_strlen($value) > 500 || ! str_starts_with($value, '/') || str_starts_with($value, '//')) {
                        $fail('桌面入口路径必须是站内路径');
                    }
                },
            ],
            'desktop_items.*.icon' => ['required', 'string', 'max:100'],
            'desktop_items.*.group_title' => ['nullable', 'string', 'max:60'],
            'desktop_items.*.x' => ['required', 'integer', 'between:0,99'],
            'desktop_items.*.y' => ['required', 'integer', 'between:0,99'],
        ];
    }

    /**
     * 「用户桌面」预设读取（OS 设置 → 用户桌面 Tab）：返回后台预设的用户端桌面图标
     * 与前台用户端目录（菜单树/应用清单）。预设面向前台用户，编辑器必须按用户端目录
     * 渲染与校验（与后台目录不同），因此目录随预设一并下发。
     */
    public function userDesktopPreset(Request $request): JsonResponse
    {
        return response()->json(ApiResponse::success([
            'desktop_items' => $this->workspaces->getPreset()->desktop_items ?? [],
            'catalog' => [
                'menus' => $this->userTerminalMenuTree(),
                'applications' => $this->userTerminalApplicationList(),
            ],
        ]));
    }

    /**
     * 「用户桌面」预设保存：前台用户进入用户端 WebOS 时按该预设做差异化增量同步
     * （后台新增且用户桌面缺失的项补齐、已存在等价项不重复添加；已推送过的项不再重复下发）。
     * 校验与净化口径与用户工作区保存完全一致，保证下发数据必然通过前台保存校验。
     */
    public function saveUserDesktopPreset(Request $request): JsonResponse
    {
        $validated = $request->validate($this->desktopItemsRules());
        $preset = $this->workspaces->savePresetItems($validated['desktop_items'] ?? []);

        return response()->json(ApiResponse::success(
            ['desktop_items' => $preset->desktop_items ?? []],
            '用户桌面预设已保存'
        ));
    }

    /**
     * 「管理桌面」预设读取（OS 设置 → 管理桌面 Tab）：返回后台启用角色列表与指定角色
     * （缺省取第一角色）预设的管理员桌面图标，及该角色被分配的后台菜单目录（菜单树/
     * 应用清单）。预设面向后台管理员，编辑器只能从角色被分配的菜单中选取入口
     * （角色未分配的菜单不出现在目录，与框架后台菜单授权口径一致）。
     */
    public function adminDesktopPreset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'role_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $roles = AdminRole::query()->enabled()->orderBy('sort')->orderBy('id')->get(['id', 'name']);
        $roleId = (int) ($validated['role_id'] ?? 0);
        $role = $roleId > 0 ? $roles->firstWhere('id', $roleId) : $roles->first();

        return response()->json(ApiResponse::success([
            'roles' => $roles->map(fn (AdminRole $item): array => ['id' => $item->id, 'name' => $item->name])->values()->all(),
            'role_id' => $role?->id,
            'desktop_items' => $role !== null ? ($this->workspaces->getAdminPreset((int) $role->id)->desktop_items ?? []) : [],
            'catalog' => $role !== null ? [
                'menus' => $this->roleMenuTree((int) $role->id),
                'applications' => $this->roleApplicationList((int) $role->id),
            ] : ['menus' => [], 'applications' => []],
        ]));
    }

    /**
     * 「管理桌面」预设保存：拥有该角色的后台管理员进入 WebOS 时，按其全部启用角色
     * 的预设做并集差量同步（补齐管理员桌面缺失的初始图标；已推送过的项不再重复下发）。
     * 校验与净化口径与工作区保存完全一致，保证下发数据必然通过保存校验。
     */
    public function saveAdminDesktopPreset(Request $request): JsonResponse
    {
        $validated = $request->validate(array_merge($this->desktopItemsRules(), [
            'role_id' => ['required', 'integer', 'min:1', 'exists:admin_roles,id'],
        ]));
        $preset = $this->workspaces->saveAdminPresetItems(
            (int) $validated['role_id'],
            $validated['desktop_items'] ?? []
        );

        return response()->json(ApiResponse::success(
            ['desktop_items' => $preset->desktop_items ?? []],
            '管理桌面预设已保存'
        ));
    }

    /**
     * 搜索前台用户（「用户桌面」Tab 搜索框，2.1.99）：用户名/昵称模糊匹配，
     * 排除禁用用户（status≠1）；仅返回列表展示所需的最小字段（不暴露邮箱/手机号等敏感信息）。
     */
    public function searchUsers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:60'],
        ]);

        $keyword = trim((string) ($validated['keyword'] ?? ''));
        $query = User::query()
            ->where('status', Status::ENABLED->value)
            ->orderBy('id')
            ->limit(20);
        if ($keyword !== '') {
            $query->where(function ($query) use ($keyword) {
                $query->where('username', 'like', '%' . $keyword . '%')
                    ->orWhere('nickname', 'like', '%' . $keyword . '%');
            });
        }

        return response()->json(ApiResponse::success(
            $query->get(['id', 'username', 'nickname'])
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'username' => $user->username,
                    'nickname' => $user->nickname,
                ])
                ->values()
                ->all()
        ));
    }

    /**
     * 读取指定前台用户的桌面数据（「用户桌面」Tab 搜索选中后查看，2.1.99）：
     * 返回用户信息 + 桌面图标 + 任务栏固定项 + 用户端目录（菜单树/应用清单）。
     * 只读不落库：用户尚无工作区行时图标/任务栏返回空列表（首次进入用户端时才建行），
     * 也不触发后台预设的差量同步（查看行为不得改变用户桌面数据）。
     */
    public function userDesktop(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'min:1', 'exists:users,id'],
        ]);

        $userId = (int) $validated['user_id'];
        $user = User::query()->findOrFail($userId);
        $workspace = $this->workspaces->getUserWorkspace($userId);

        return response()->json(ApiResponse::success([
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'nickname' => $user->nickname,
            ],
            'desktop_items' => $workspace?->desktop_items ?? [],
            'taskbar_items' => $workspace?->taskbar_items ?? [],
            'catalog' => [
                'menus' => $this->userTerminalMenuTree(),
                'applications' => $this->userTerminalApplicationList(),
            ],
        ]));
    }

    /**
     * 后台代改用户桌面保存（「用户桌面」Tab 查看用户后的保存，2.1.99）：
     * 仅覆盖 desktop_items，任务栏固定项/偏好/预设推送台账不动；用户无工作区行时自动建行。
     * 校验与净化口径与用户端工作区保存完全一致，保证代改数据与用户自行保存的数据同构。
     */
    public function saveUserDesktop(Request $request): JsonResponse
    {
        $validated = $request->validate(array_merge($this->desktopItemsRules(), [
            'user_id' => ['required', 'integer', 'min:1', 'exists:users,id'],
        ]));

        $workspace = $this->workspaces->saveUserDesktopItems(
            (int) $validated['user_id'],
            $validated['desktop_items'] ?? []
        );

        return response()->json(ApiResponse::success(
            ['desktop_items' => $workspace->desktop_items ?? []],
            '用户桌面已保存'
        ));
    }

    /**
     * 搜索后台管理员（「管理桌面」Tab 预设角色行右侧搜索框，2.1.100）：账号/姓名模糊匹配，
     * 排除禁用管理员（status≠1）；仅返回列表展示所需的最小字段（不暴露邮箱/手机号等敏感信息）。
     */
    public function searchAdmins(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:60'],
        ]);

        $keyword = trim((string) ($validated['keyword'] ?? ''));
        $query = AdminUser::query()
            ->where('status', Status::ENABLED->value)
            ->orderBy('id')
            ->limit(20);
        if ($keyword !== '') {
            $query->where(function ($query) use ($keyword) {
                $query->where('username', 'like', '%' . $keyword . '%')
                    ->orWhere('name', 'like', '%' . $keyword . '%');
            });
        }

        return response()->json(ApiResponse::success(
            $query->get(['id', 'username', 'name'])
                ->map(fn (AdminUser $admin): array => [
                    'id' => $admin->id,
                    'username' => $admin->username,
                    'name' => $admin->name,
                ])
                ->values()
                ->all()
        ));
    }

    /**
     * 读取指定管理员的后台桌面数据（「管理桌面」Tab 搜索选中后查看，2.1.100）：
     * 返回管理员信息 + 桌面图标 + 任务栏固定项 + 该管理员的后台目录（菜单树/应用清单）。
     * 只读不落库：管理员尚无工作区行时图标/任务栏返回空列表（首次进入后台时才建行），
     * 也不触发其角色「管理桌面」预设的差量同步（查看行为不得改变管理员桌面数据）。
     */
    public function adminWorkspaceDesktop(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'admin_id' => ['required', 'integer', 'min:1', 'exists:admin_users,id'],
        ]);

        $adminId = (int) $validated['admin_id'];
        $admin = AdminUser::query()->with('roles')->findOrFail($adminId);
        $workspace = $this->workspaces->getAdminWorkspace($adminId);

        return response()->json(ApiResponse::success([
            'admin' => [
                'id' => $admin->id,
                'username' => $admin->username,
                'name' => $admin->name,
            ],
            'desktop_items' => $workspace?->desktop_items ?? [],
            'taskbar_items' => $workspace?->taskbar_items ?? [],
            'catalog' => [
                'menus' => $this->adminMenuTreeForAdmin($admin),
                'applications' => $this->adminApplicationListForAdmin($admin),
            ],
        ]));
    }

    /**
     * 后台代改指定管理员桌面保存（「管理桌面」Tab 查看管理员后的保存，2.1.100）：
     * 仅覆盖 desktop_items，任务栏固定项/偏好/角色预设推送台账不动；管理员无工作区行时自动建行。
     * 校验与净化口径与后台工作区保存完全一致，保证代改数据与管理员自行保存的数据同构。
     */
    public function saveAdminWorkspaceDesktop(Request $request): JsonResponse
    {
        $validated = $request->validate(array_merge($this->desktopItemsRules(), [
            'admin_id' => ['required', 'integer', 'min:1', 'exists:admin_users,id'],
        ]));

        $workspace = $this->workspaces->saveAdminDesktopItems(
            (int) $validated['admin_id'],
            $validated['desktop_items'] ?? []
        );

        return response()->json(ApiResponse::success(
            ['desktop_items' => $workspace->desktop_items ?? []],
            '管理员桌面已保存'
        ));
    }

    /**
     * 指定后台角色的菜单树：与框架后台菜单授权口径一致（MenuService::userMenus
     * 的按角色版本）——角色被分配的菜单（status/visible 开启）+ 祖先补全（子菜单
     * 被授权时补齐其父级，避免树形结构断裂），不叠加 terminal_type 过滤。
     *
     * @return array<int,array<string,mixed>>
     */
    protected function roleMenuTree(int $roleId): array
    {
        return $this->roleMenuTreeForRoles([$roleId]);
    }

    /**
     * 指定角色集合的菜单树（2.1.100 泛化）：全部角色（不过滤角色启用状态，与
     * MenuService::userMenus 口径一致）被分配菜单的并集 + 祖先补全；供单角色预设
     * 与「查看管理员桌面」目录共用（管理员目录 = 其全部角色的并集）。
     *
     * @param array<int,int> $roleIds
     * @return array<int,array<string,mixed>>
     */
    protected function roleMenuTreeForRoles(array $roleIds): array
    {
        $roleIds = array_values(array_unique(array_filter(array_map('intval', $roleIds))));
        if (! $roleIds) {
            return [];
        }

        $menus = AdminMenu::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('admin_roles.id', $roleIds))
            ->where('status', 1)
            ->where('visible', 1)
            ->orderBy('sort')
            ->get();

        // 补全父级菜单：沿 parent_id 逐级上溯收集缺失祖先，缺失祖先按 status/visible 开启口径补齐
        $menuIds = $menus->pluck('id')->all();
        $missingIds = [];
        $allMenus = null;
        foreach ($menus as $menu) {
            $parentId = $menu->parent_id;
            while ($parentId != 0 && ! in_array($parentId, $menuIds)) {
                $missingIds[] = $parentId;
                $menuIds[] = $parentId;
                if ($allMenus === null) {
                    $allMenus = AdminMenu::query()->where('status', 1)->where('visible', 1)->get()->keyBy('id');
                }
                $parentId = isset($allMenus[$parentId]) ? $allMenus[$parentId]->parent_id : 0;
            }
        }

        if (! empty($missingIds)) {
            $parentMenus = AdminMenu::query()
                ->whereIn('id', array_unique($missingIds))
                ->where('status', 1)
                ->where('visible', 1)
                ->orderBy('sort')
                ->get();
            $menus = $menus->merge($parentMenus)->sortBy('sort')->values();
        }

        return $this->buildMenuTree($menus->toArray());
    }

    /**
     * 指定管理员的后台菜单树（「查看管理员桌面」目录，2.1.100）：与框架后台菜单授权
     * 口径一致——超级管理员可见全部 status/visible 开启菜单；普通管理员可见其全部角色
     * （不过滤角色启用状态）被分配菜单的并集 + 祖先补全；不叠加 terminal_type 过滤。
     *
     * @return array<int,array<string,mixed>>
     */
    protected function adminMenuTreeForAdmin(AdminUser $admin): array
    {
        if ($admin->isSuperAdmin()) {
            $menus = AdminMenu::query()
                ->where('status', 1)
                ->where('visible', 1)
                ->orderBy('sort')
                ->get()
                ->toArray();

            return $this->buildMenuTree($menus);
        }

        return $this->roleMenuTreeForRoles($admin->roles->pluck('id')->all());
    }

    /**
     * 指定后台角色可见的应用清单：仅返回该角色菜单中出现过 app_id 的应用
     * （与 userTerminalApplicationList 同构，目录渲染依赖应用的名称与图标）。
     *
     * @return array<int,array<string,mixed>>
     */
    protected function roleApplicationList(int $roleId): array
    {
        return $this->roleApplicationListForRoleIds([$roleId]);
    }

    /**
     * 指定角色集合可见的应用清单（2.1.100 泛化）：全部角色被分配菜单中出现过
     * app_id 的应用的并集；供单角色预设与「查看管理员桌面」目录共用。
     *
     * @param array<int,int> $roleIds
     * @return array<int,array<string,mixed>>
     */
    protected function roleApplicationListForRoleIds(array $roleIds): array
    {
        $roleIds = array_values(array_unique(array_filter(array_map('intval', $roleIds))));
        if (! $roleIds) {
            return [];
        }

        $appIds = AdminMenu::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('admin_roles.id', $roleIds))
            ->where('status', 1)
            ->where('visible', 1)
            ->whereNotNull('app_id')
            ->where('app_id', '!=', '')
            ->distinct()
            ->pluck('app_id')
            ->all();

        return $this->applicationListByAppIds($appIds);
    }

    /**
     * 指定管理员可见的应用清单（「查看管理员桌面」目录，2.1.100）：菜单树口径的
     * 应用侧补充——超级管理员取全部开启菜单、普通管理员取其全部角色被分配菜单
     * 中出现过的 app_id 的应用并集（目录渲染依赖应用的名称与图标）。
     *
     * @return array<int,array<string,mixed>>
     */
    protected function adminApplicationListForAdmin(AdminUser $admin): array
    {
        $menus = AdminMenu::query()
            ->where('status', 1)
            ->where('visible', 1)
            ->whereNotNull('app_id')
            ->where('app_id', '!=', '');
        if (! $admin->isSuperAdmin()) {
            $roleIds = $admin->roles->pluck('id')->all();
            if (! $roleIds) {
                return [];
            }
            $menus->whereHas('roles', fn ($query) => $query->whereIn('admin_roles.id', $roleIds));
        }

        return $this->applicationListByAppIds(
            $menus->distinct()->pluck('app_id')->all()
        );
    }

    /**
     * 按 app_id 集合返回应用清单（应用名称/图标等目录渲染数据源）：
     * 数据源与 admin 端 /api/admin/apps 同构（apps 表 name/icon + 公开图标路由 icon_url）。
     *
     * @param array<int,string> $appIds
     * @return array<int,array<string,mixed>>
     */
    protected function applicationListByAppIds(array $appIds): array
    {
        if (! $appIds) {
            return [];
        }

        return AppModel::query()
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
    }

    public function workspace(Request $request): JsonResponse
    {
        $workspace = $this->workspaces->getFor($this->terminalOf($request), $this->userIdOf($request));

        return response()->json(ApiResponse::success($workspace));
    }

    public function saveWorkspace(Request $request): JsonResponse
    {
        $validated = $request->validate(array_merge($this->desktopItemsRules(), [
            'taskbar_items' => ['sometimes', 'array', 'max:12'],
            'taskbar_items.*.id' => ['required', 'string', 'max:80'],
            'taskbar_items.*.menu_id' => ['nullable', 'integer', 'min:0'],
            'taskbar_items.*.app_id' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.-]*$/'],
            'taskbar_items.*.title' => ['required', 'string', 'max:60'],
            // 任务栏项结构同桌面项：空 path 仅内置浏览器入口（id=webos-browser）放行，其余空路径/外链仍拒绝（裁决收进闭包，原因同桌面项）
            'taskbar_items.*.path' => [
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
                        if ((string) request()->input(preg_replace('/\.path$/', '.id', $attribute)) !== 'webos-browser') {
                            $fail('任务栏入口路径必须是站内路径');
                        }
                        return;
                    }
                    if (! is_string($value) || mb_strlen($value) > 500 || ! str_starts_with($value, '/') || str_starts_with($value, '//')) {
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
            // 应用窗口多选项卡（OS 设置开关）
            'preferences.window_tabs' => ['sometimes', 'boolean'],
            // 点击菜单进入（OS 设置开关：默认打开系统菜单面板，开启后直接进入全部功能启动台）
            'preferences.menu_open_launcher' => ['sometimes', 'boolean'],
            'preferences.taskbar_alignment' => ['sometimes', 'in:left,center'],
            'preferences.taskbar_position' => ['sometimes', 'in:top,bottom,left,right'],
            'preferences.clock_format' => ['sometimes', 'in:12h,24h'],
            'preferences.show_seconds' => ['sometimes', 'boolean'],
            'preferences.motion' => ['sometimes', 'boolean'],
            'preferences.window_width' => ['sometimes', 'integer', 'between:40,100'],
            'preferences.window_height' => ['sometimes', 'integer', 'between:40,100'],
            'preferences.override_admin_home' => ['sometimes', 'boolean'],
            'preferences.override_user_home' => ['sometimes', 'boolean'],
            'preferences.taskbar_autohide' => ['sometimes', 'boolean'],
            'preferences.taskbar_locked' => ['sometimes', 'boolean'],
            'preferences.taskbar_button_style' => ['sometimes', 'in:icon,text'],
            'preferences.usage_stats' => ['sometimes', 'array', 'max:80'],
            // 被忽略的可用更新（应用中心「忽略」按钮，app_id@版本号）：无规则声明时 $validated 会丢弃该键
            'preferences.ignored_updates' => ['sometimes', 'array', 'max:200'],
            'preferences.ignored_updates.*' => ['string', 'max:180'],
        ]));

        // 「覆盖传统后台」双开关为全局设置，仅超级管理员可改（2.1.104）：非超管提交的 override 键
        // 静默剔除、保留库中原值——前端整包提交 preferences（普通管理员的 UI 已隐藏该开关，
        // 但历史本地状态仍可能整包携带这两个键），剔除而非 403 以免误伤其正常保存
        if (! auth('admin')->user()?->isSuperAdmin()) {
            unset($validated['preferences']['override_admin_home'], $validated['preferences']['override_user_home']);
        }

        $workspace = $this->workspaces->saveFor(
            $this->terminalOf($request),
            $this->userIdOf($request),
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
        $workspace = $this->workspaces->resetFor($this->terminalOf($request), $this->userIdOf($request));

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

        $url = $this->wallpapers->store($validated['wallpaper'], $this->userIdOf($request));

        return response()->json(ApiResponse::success(['url' => $url], '壁纸已上传'));
    }

    /**
     * 当前管理员已上传的自定义壁纸列表，供背景设置中切换与删除。
     */
    public function wallpapers(Request $request): JsonResponse
    {
        $wallpapers = $this->wallpapers->listFor($this->userIdOf($request));

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

        if (! $this->wallpapers->delete($validated['url'], $this->userIdOf($request))) {
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

    /**
     * 当前请求的工作区终端标识：admin 端固定 admin；
     * 用户端控制器（User\WebosController）覆写为 user，实现两端工作区隔离。
     */
    protected function terminalOf(Request $request): string
    {
        return 'admin';
    }

    /** 当前请求的工作区归属用户 ID：admin 端取管理员，用户端控制器覆写取前台用户 */
    protected function userIdOf(Request $request): int
    {
        return (int) $request->user('admin')->id;
    }
}
