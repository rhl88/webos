<?php

namespace App\Apps\CmsproWebos\Services;

use App\Apps\CmsproWebos\Models\WebosWorkspace;
use App\Enums\Status;
use App\Models\AdminUser;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use InvalidArgumentException;

class WorkspaceService
{
    public const DEFAULT_PREFERENCES = [
        'wallpaper' => 'webos-default',
        'wallpaper_url' => '',
        'icon_size' => 'medium',
        'label_effect' => 'none',
        // 界面主题（2.1.136 OS 设置「界面主题」Tab）：default=CmsPro 经典、win11=Win11 浅色（Fluent Design）
        'ui_theme' => 'default',
        // 自定义主题库（2.1.144「细节调整」）：[{id,name,base,overrides}]，overrides 为 8 项细节覆盖值
        'ui_theme_customs' => [],
        // 当前启用的自定义主题 ID（非空且存在时优先于 ui_theme 生效）
        'ui_theme_custom_id' => '',
        'window_tabs' => false,
        'tab_reload' => false,
        'menu_open_launcher' => false,
        'taskbar_alignment' => 'left',
        'taskbar_position' => 'bottom',
        'clock_format' => '24h',
        'show_seconds' => false,
        'motion' => true,
        'window_width' => 78,
        'window_height' => 80,
        'taskbar_autohide' => false,
        'taskbar_locked' => false,
        'taskbar_button_style' => 'icon',
        'override_admin_home' => false,
        'override_user_home' => false,
        'usage_stats' => [],
        'ignored_updates' => [],
        // 站点入口快捷访问菜单（2.1.129）：任务栏地球图标弹出菜单按端取用；
        // 用户端默认不配置「用户中心」（用户本身就在用户中心）
        'quick_access' => [
            'admin' => [
                ['label' => '访问首页', 'url' => '/', 'icon' => 'fa fa-home'],
                ['label' => '用户中心', 'url' => '/user', 'icon' => 'fa fa-user-circle-o'],
            ],
            'user' => [
                ['label' => '访问首页', 'url' => '/', 'icon' => 'fa fa-home'],
            ],
        ],
    ];

    /** 入口使用统计保留上限（2.1.137）：与前端 USAGE_STATS_LIMIT 同一口径。
     *  超出时按最近打开时间裁剪（而非整单拒绝），统计键随打开过的菜单持续增长，
     *  上限过小会让保存请求连同桌面布局一起失败。 */
    public const USAGE_STATS_LIMIT = 200;

    /** 后台预设的用户端桌面在 workspaces 表中的占位归属（terminal=preset + 用户 ID 0） */
    protected const PRESET_TERMINAL = 'preset';

    protected const PRESET_USER_ID = 0;

    /** 「管理桌面」按后台角色预设的管理员桌面占位归属（terminal=admin_preset + 角色 ID） */
    protected const ADMIN_PRESET_TERMINAL = 'admin_preset';

    /** 全局设置占位归属（terminal=setting + 用户 ID 0）：承载跨账号的全局开关，与账号工作区以联合唯一约束隔离 */
    protected const SETTING_TERMINAL = 'setting';

    protected const SETTING_USER_ID = 0;

    /** 全局开关键（OS 设置「覆盖传统后台」双开关）：任一超级管理员配置即对所有账号生效，不按账号隔离 */
    public const GLOBAL_PREFERENCES = ['override_admin_home', 'override_user_home'];

    /** 自定义主题数量上限（2.1.144「细节调整」）：超限从列表尾部丢弃 */
    public const MAX_UI_THEME_CUSTOMS = 20;

    /**
     * 自定义主题细节覆盖键的净化规则（2.1.144，与前端 THEME_FIELDS 同口径）：
     * 字符串值为十六进制色值正则，数值规则为 [最小值, 最大值] 区间（越界 clamp）；
     * 未声明或非法的键直接丢弃（不回退默认——缺键即「跟随基础主题默认」语义）。
     */
    protected const THEME_OVERRIDE_RULES = [
        'accent' => '/^#[0-9a-f]{6}$/',
        'taskbar_height' => [40, 64],
        'window_radius' => [0, 16],
        'taskbar_opacity' => [50, 100],
        'icon_scale' => [80, 130],
        'font_size' => [12, 16],
        'shadow' => [0, 100],
        'wallpaper_dim' => [0, 60],
    ];

    /** 自定义主题 ID 规则（前端 Date.now()+随机段生成的小写字母数字串） */
    protected const THEME_ID_PATTERN = '/^[a-z0-9]{4,24}$/';

    /** 小工具桌面项实例参数上限（键数量）：多实例场景由 picker 选中值写入 */
    public const MAX_WIDGET_PARAMS = 3;

    /** 实例参数键名规则（与注册表 picker.param 一致） */
    protected const WIDGET_PARAM_KEY_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';

    /** 实例参数值最大长度 */
    protected const MAX_WIDGET_PARAM_VALUE_LENGTH = 64;

    /** 小工具卡片占格上限（宽x高，2.1.113）：与注册表 SIZE_WHITELIST 的 4x2 / 2x3 档位一致 */
    public const MAX_WIDGET_SPAN_WIDTH = 4;

    public const MAX_WIDGET_SPAN_HEIGHT = 3;

    /** 小工具像素尺寸上限（2.1.115）：像素宽高与像素位置的防御性上限，实际范围由前端按桌面可视区约束 */
    public const MAX_WIDGET_PIXEL_SIZE = 4096;

    public const MAX_WIDGET_PIXEL_OFFSET = 20000;

    public function getForAdmin(int $adminUserId): WebosWorkspace
    {
        return $this->getFor('admin', $adminUserId);
    }

    /**
     * 按终端取工作区：terminal 区分后台管理员（admin）与前台用户（user），
     * 同一账号在两端各自持有独立工作区（联合唯一约束 terminal + admin_user_id）。
     * admin_user_id 列继续承载「当前终端用户 ID」（历史列名，避免结构性迁移）。
     */
    public function getFor(string $terminal, int $userId): WebosWorkspace
    {
        $workspace = WebosWorkspace::query()->firstOrCreate(
            ['terminal' => $terminal, 'admin_user_id' => $userId],
            [
                'desktop_items' => [],
                'taskbar_items' => [],
                'preferences' => self::DEFAULT_PREFERENCES,
                'status' => 1,
            ]
        );

        $workspace->preferences = array_replace(
            self::DEFAULT_PREFERENCES,
            $workspace->preferences ?? []
        );

        // 旧数据兼容：迁移前无任务栏记录时，沿用桌面项前 4 位作为初始任务栏固定项
        if (! is_array($workspace->taskbar_items)) {
            $workspace->taskbar_items = array_slice($workspace->desktop_items ?? [], 0, 4);
        }

        // 前台用户端：按后台预设差异化补齐桌面图标（2.1.89）。
        // 已推送过的预设项不再下发（用户删除/修改后也不回推）；后台管理员端不受影响。
        if ($terminal === 'user') {
            $this->syncPresetDesktop($workspace);
        } elseif ($terminal === 'admin') {
            // 后台管理员端：按其后台角色预设的「管理桌面」差量补齐初始图标（2.1.98），
            // 推送台账与前台预设一致（preset_sync_ids，已推送不回推）。
            $this->syncAdminRolePresets($workspace, $userId);
        }

        // 全局开关不随账号隔离：以全局设置为准回填，所有账号读取到同一状态
        // （放在预设同步之后，避免回填值被同步流程连带写入账号工作区）
        return $this->withGlobalPreferences($workspace);
    }

    /**
     * 读取后台预设的用户端桌面（terminal='preset' + admin_user_id=0 单独一行，
     * 与真实账号的工作区以联合唯一约束隔离，无需新增数据表/字段）。
     */
    public function getPreset(): WebosWorkspace
    {
        $workspace = WebosWorkspace::query()->firstOrCreate(
            ['terminal' => self::PRESET_TERMINAL, 'admin_user_id' => self::PRESET_USER_ID],
            [
                'desktop_items' => [],
                'taskbar_items' => [],
                'preferences' => self::DEFAULT_PREFERENCES,
                'status' => 1,
            ]
        );

        if (! is_array($workspace->desktop_items)) {
            $workspace->desktop_items = [];
        }

        return $workspace;
    }

    /**
     * 保存后台预设的用户端桌面项：预设仅作用于桌面图标（不预设任务栏与偏好），
     * 净化规则与用户工作区完全一致，保证播种到前台的数据一定能通过保存校验。
     *
     * @param array<int,array<string,mixed>> $items
     */
    public function savePresetItems(array $items): WebosWorkspace
    {
        $workspace = $this->getPreset();
        $workspace->desktop_items = $this->sanitizeDesktopItems($items);
        $workspace->save();

        return $workspace->refresh();
    }

    /**
     * 读取指定后台角色的「管理桌面」预设（terminal='admin_preset' + 角色 ID 单独一行，
     * 与真实账号工作区以联合唯一约束隔离，无需新增数据表/字段）。
     */
    public function getAdminPreset(int $roleId): WebosWorkspace
    {
        $workspace = WebosWorkspace::query()->firstOrCreate(
            ['terminal' => self::ADMIN_PRESET_TERMINAL, 'admin_user_id' => $roleId],
            [
                'desktop_items' => [],
                'taskbar_items' => [],
                'preferences' => self::DEFAULT_PREFERENCES,
                'status' => 1,
            ]
        );

        if (! is_array($workspace->desktop_items)) {
            $workspace->desktop_items = [];
        }

        return $workspace;
    }

    /**
     * 保存指定后台角色的「管理桌面」预设项：净化规则与管理员工作区完全一致，
     * 保证播种到管理员桌面的数据一定能通过保存校验。
     *
     * @param array<int,array<string,mixed>> $items
     */
    public function saveAdminPresetItems(int $roleId, array $items): WebosWorkspace
    {
        $workspace = $this->getAdminPreset($roleId);
        $workspace->desktop_items = $this->sanitizeDesktopItems($items);
        $workspace->save();

        return $workspace->refresh();
    }

    /**
     * 读取指定前台用户的用户端工作区（只读）：不创建缺失行、不触发预设差量同步
     * （getFor 的 firstOrCreate 与 syncPresetDesktop 副作用对后台只读查看场景不可接受），
     * 供后台「用户桌面」搜索查看指定用户桌面使用。
     */
    public function getUserWorkspace(int $userId): ?WebosWorkspace
    {
        return WebosWorkspace::query()
            ->where('terminal', 'user')
            ->where('admin_user_id', $userId)
            ->first();
    }

    /**
     * 后台代改前台用户桌面（「用户桌面」搜索选中用户后的保存，2.1.99）：
     * 仅覆盖 desktop_items，任务栏固定项/偏好/预设推送台账（preset_sync_ids）保持不动——
     * 台账不动意味着此后用户进入用户端时，后台预设的差量同步规则不受本次代改影响；
     * 用户尚无工作区行时按用户端建行口径自动创建（与 getFor 首次建行一致）。
     *
     * @param array<int,array<string,mixed>> $items
     */
    public function saveUserDesktopItems(int $userId, array $items): WebosWorkspace
    {
        $workspace = $this->getUserWorkspace($userId) ?? new WebosWorkspace();
        $isNew = ! $workspace->exists;

        $workspace->terminal = 'user';
        $workspace->admin_user_id = $userId;
        $workspace->desktop_items = $this->sanitizeDesktopItems($items);
        if ($isNew) {
            $workspace->taskbar_items = [];
            $workspace->preferences = self::DEFAULT_PREFERENCES;
            $workspace->preset_sync_ids = [];
            $workspace->status = 1;
        }
        $workspace->save();

        return $workspace->refresh();
    }

    /**
     * 读取指定管理员的后台工作区（只读）：不创建缺失行、不触发预设差量同步
     * （getFor 的 firstOrCreate 与 syncAdminRolePresets 副作用对后台只读查看场景不可接受），
     * 供后台「管理桌面」搜索查看指定管理员桌面使用（2.1.100）。
     */
    public function getAdminWorkspace(int $adminUserId): ?WebosWorkspace
    {
        return WebosWorkspace::query()
            ->where('terminal', 'admin')
            ->where('admin_user_id', $adminUserId)
            ->first();
    }

    /**
     * 后台代改指定管理员桌面（「管理桌面」搜索选中管理员后的保存，2.1.100）：
     * 仅覆盖 desktop_items，任务栏固定项/偏好/角色预设推送台账（preset_sync_ids）保持不动——
     * 台账不动意味着此后管理员进入后台时，角色「管理桌面」预设的差量同步规则不受本次代改影响；
     * 管理员尚无工作区行时按后台建行口径自动创建（与 getFor 首次建行一致）。
     *
     * @param array<int,array<string,mixed>> $items
     */
    public function saveAdminDesktopItems(int $adminUserId, array $items): WebosWorkspace
    {
        $workspace = $this->getAdminWorkspace($adminUserId) ?? new WebosWorkspace();
        $isNew = ! $workspace->exists;

        $workspace->terminal = 'admin';
        $workspace->admin_user_id = $adminUserId;
        $workspace->desktop_items = $this->sanitizeDesktopItems($items);
        if ($isNew) {
            $workspace->taskbar_items = [];
            $workspace->preferences = self::DEFAULT_PREFERENCES;
            $workspace->preset_sync_ids = [];
            $workspace->status = 1;
        }
        $workspace->save();

        return $workspace->refresh();
    }

    /** 前台用户端：按后台预设（terminal=preset）差量同步桌面（同步规则见 syncPresetItems） */
    protected function syncPresetDesktop(WebosWorkspace $workspace): void
    {
        $this->syncPresetItems($workspace, $this->getPreset()->desktop_items ?? []);
    }

    /**
     * 后台管理员桌面与其后台角色「管理桌面」预设的差量同步（2.1.98）：
     * 取管理员全部启用角色（按角色 sort/id 顺序）的预设做并集（presetItemId 去重，
     * 先出现的角色优先），复用与前台预设相同的差量同步规则；无角色或角色均未
     * 配置预设时不产生任何变更。
     */
    protected function syncAdminRolePresets(WebosWorkspace $workspace, int $adminUserId): void
    {
        $adminUser = AdminUser::query()->find($adminUserId);
        if ($adminUser === null) {
            return;
        }

        $roleIds = $adminUser->roles()
            ->where('admin_roles.status', Status::ENABLED->value)
            ->orderBy('admin_roles.sort')
            ->orderBy('admin_roles.id')
            ->pluck('admin_roles.id')
            ->all();
        if ($roleIds === []) {
            return;
        }

        // 批量取已有预设行：不做 firstOrCreate，避免为未配置预设的角色创建空行
        $presetRows = WebosWorkspace::query()
            ->where('terminal', self::ADMIN_PRESET_TERMINAL)
            ->whereIn('admin_user_id', $roleIds)
            ->get(['admin_user_id', 'desktop_items'])
            ->keyBy('admin_user_id');
        if ($presetRows->isEmpty()) {
            return;
        }

        $mergedItems = [];
        $seenIds = [];
        foreach ($roleIds as $roleId) {
            $presetRow = $presetRows->get($roleId);
            if ($presetRow === null || ! is_array($presetRow->desktop_items)) {
                continue;
            }
            foreach ($presetRow->desktop_items as $presetItem) {
                if (! is_array($presetItem)) {
                    continue;
                }
                $itemId = $this->presetItemId($presetItem);
                if ($itemId === '' || isset($seenIds[$itemId])) {
                    continue;
                }
                $seenIds[$itemId] = true;
                $mergedItems[] = $presetItem;
            }
        }

        $this->syncPresetItems($workspace, $mergedItems);
    }

    /**
     * 预设桌面项差量同步核心（前台用户预设与后台角色预设共用）：
     * - 以「预设项 id」为推送标识：已推送过（记录在 preset_sync_ids）的预设项不再下发，
     *   用户此后删除或修改该项也不回推——「已经推送过了，就不需要再推送了」。
     * - 未推送过的项，若目标桌面已存在内容等价的项（内容指纹一致），仅登记为已推送、
     *   不重复添加；否则追加到桌面（补齐预设新增且桌面缺失的项）。
     * - 预设文件夹命中目标桌面已有等价文件夹时，其子项挂靠到已有文件夹下（避免产生孤立子项）。
     *
     * @param array<int,array<string,mixed>> $presetItems
     */
    protected function syncPresetItems(WebosWorkspace $workspace, array $presetItems): void
    {
        if ($presetItems === []) {
            return;
        }

        $desktopItems = is_array($workspace->desktop_items) ? $workspace->desktop_items : [];

        $pushedMap = [];
        foreach (is_array($workspace->preset_sync_ids) ? $workspace->preset_sync_ids : [] as $pushedId) {
            if (is_string($pushedId)) {
                $pushedMap[$pushedId] = true;
            }
        }

        // 目标桌面内容指纹 → 项 id：预设项内容与已有项一致时视为「已存在」，不重复添加
        $signatures = [];
        foreach ($desktopItems as $item) {
            if (is_array($item)) {
                $signatures[$this->presetItemSignature($item)] = (string) ($item['id'] ?? '');
            }
        }

        // 第一遍：预设文件夹命中目标桌面已有等价文件夹时记录 id 映射，供其子项挂靠
        // （先建映射再逐项处理，消除预设项数组顺序对挂靠结果的影响）
        $folderMap = [];
        foreach ($presetItems as $presetItem) {
            if (! is_array($presetItem) || ($presetItem['kind'] ?? '') !== 'folder') {
                continue;
            }
            $presetId = $this->presetItemId($presetItem);
            $existingId = $signatures[$this->presetItemSignature($presetItem)] ?? '';
            if (! isset($pushedMap[$presetId]) && $existingId !== '') {
                $folderMap[$presetId] = $existingId;
            }
        }

        $changed = false;
        foreach ($presetItems as $presetItem) {
            if (! is_array($presetItem)) {
                continue;
            }
            $presetId = $this->presetItemId($presetItem);
            if (isset($pushedMap[$presetId])) {
                continue;
            }

            $signature = $this->presetItemSignature($presetItem);
            if (isset($signatures[$signature])) {
                // 目标桌面已存在等价项：仅登记为已推送，不重复添加（此后该项的新增/删除/修改都不再下发）
                $pushedMap[$presetId] = true;
                $changed = true;
                continue;
            }
            if (count($desktopItems) >= self::DESKTOP_ITEM_LIMIT) {
                // 桌面已满：本项留待下次补齐（不登记已推送）
                break;
            }

            $item = $this->sanitizeDesktopItem($presetItem);
            if (isset($folderMap[$item['parent_folder']])) {
                $item['parent_folder'] = $folderMap[$item['parent_folder']];
            }
            $desktopItems[] = $item;
            $signatures[$signature] = $item['id'];
            $pushedMap[$presetId] = true;
            $changed = true;
        }

        if (! $changed) {
            return;
        }

        $workspace->desktop_items = $desktopItems;
        $workspace->preset_sync_ids = array_keys($pushedMap);
        $workspace->save();
    }

    /**
     * 预设项推送标识：优先用项自身 id（管理员改内容不改 id → 不重复下发；删后重建新 id → 视为新项）。
     * 预设项均经 sanitizeDesktopItem 落库，id 恒为非空 [A-Za-z0-9_-] 字符串。
     */
    protected function presetItemId(array $item): string
    {
        $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($item['id'] ?? ''));

        return Str::limit($id, 80, '');
    }

    /** 预设项内容指纹：判定用户桌面是否已存在等价项（文件夹子项按所属文件夹归属） */
    protected function presetItemSignature(array $item): string
    {
        return implode('|', [
            (string) ($item['kind'] ?? ''),
            (string) ($item['app_id'] ?? ''),
            (string) ($item['menu_id'] ?? ''),
            (string) ($item['path'] ?? ''),
            (string) ($item['title'] ?? ''),
            (string) ($item['parent_folder'] ?? ''),
            (string) ($item['widget_key'] ?? ''),
        ]);
    }

    /**
     * 「覆盖传统后台」全局设置（2.1.133）：双开关不再按账号隔离，统一读写全局占位行
     * （terminal=setting + admin_user_id=0），任一超级管理员配置即对所有账号生效。
     *
     * 兼容存量：全局行尚未配置该键时，回退「任一管理员个人偏好开启即生效」口径，
     * 升级后无需用户重新开启；下一次在 OS 设置中切换即写入全局行，个人偏好不再参与判定。
     */
    public static function anyAdminOverridesAdminHome(): bool
    {
        return (bool) (static::resolvedGlobalPreferences()['override_admin_home'] ?? false);
    }

    /** 「用户首页直达 WebOS」全局设置（判定口径与 anyAdminOverridesAdminHome 完全一致） */
    public static function anyAdminOverridesUserHome(): bool
    {
        return (bool) (static::resolvedGlobalPreferences()['override_user_home'] ?? false);
    }

    /**
     * 全局设置读取（terminal=setting + admin_user_id=0 占位行）：
     * 行不存在时返回空数组且不落库，避免只读请求产生写表。
     *
     * @return array<string,mixed>
     */
    public static function globalPreferences(): array
    {
        $row = WebosWorkspace::query()
            ->where('terminal', self::SETTING_TERMINAL)
            ->where('admin_user_id', self::SETTING_USER_ID)
            ->first(['preferences']);

        return is_array($row?->preferences) ? $row->preferences : [];
    }

    /**
     * 解析后的全局开关：全局行已配置的键直接采用；缺失的键（存量环境首次升级）
     * 回退扫描管理员工作区，任一开启即视为开启。
     *
     * @return array<string,bool>
     */
    protected static function resolvedGlobalPreferences(): array
    {
        $global = static::globalPreferences();
        $missing = array_diff(self::GLOBAL_PREFERENCES, array_keys($global));
        if ($missing === []) {
            return array_map(static fn ($value): bool => (bool) $value, $global);
        }

        $legacy = [];
        $rows = WebosWorkspace::query()->where('terminal', 'admin')->get(['preferences']);
        foreach ($rows as $row) {
            foreach ($missing as $key) {
                if (! empty($row->preferences[$key])) {
                    $legacy[$key] = true;
                }
            }
        }
        foreach ($missing as $key) {
            $global[$key] = $legacy[$key] ?? false;
        }

        return array_map(static fn ($value): bool => (bool) $value, $global);
    }

    /** 全局开关写入：占位行不存在则创建；仅接受 GLOBAL_PREFERENCES 声明的键 */
    protected function saveGlobalPreferences(array $values): void
    {
        $workspace = WebosWorkspace::query()->firstOrCreate(
            ['terminal' => self::SETTING_TERMINAL, 'admin_user_id' => self::SETTING_USER_ID],
            ['desktop_items' => [], 'taskbar_items' => [], 'preferences' => [], 'status' => 1]
        );
        $preferences = is_array($workspace->preferences) ? $workspace->preferences : [];
        foreach (self::GLOBAL_PREFERENCES as $key) {
            if (array_key_exists($key, $values)) {
                $preferences[$key] = (bool) $values[$key];
            }
        }
        $workspace->preferences = $preferences;
        $workspace->save();
    }

    /** 响应回填全局开关（仅内存，不落库）：各账号读取到同一全局状态 */
    protected function withGlobalPreferences(WebosWorkspace $workspace): WebosWorkspace
    {
        $preferences = is_array($workspace->preferences) ? $workspace->preferences : [];
        $global = static::resolvedGlobalPreferences();
        foreach (self::GLOBAL_PREFERENCES as $key) {
            $preferences[$key] = (bool) ($global[$key] ?? false);
        }
        $workspace->preferences = $preferences;

        return $workspace;
    }

    /** 桌面项保存上限（含文件夹子项）：与前端添加入口的容量预检查、控制器校验规则共用同一口径 */
    public const DESKTOP_ITEM_LIMIT = 200;

    /**
     * @param array{desktop_items?:array<int,array<string,mixed>>,taskbar_items?:array<int,array<string,mixed>>,preferences?:array<string,mixed>} $payload
     */
    public function saveForAdmin(int $adminUserId, array $payload): WebosWorkspace
    {
        return $this->saveFor('admin', $adminUserId, $payload);
    }

    /** 按终端保存工作区（桌面项/任务栏项/偏好），归属与读取口径见 getFor */
    public function saveFor(string $terminal, int $userId, array $payload): WebosWorkspace
    {
        $workspace = $this->getFor($terminal, $userId);

        if (array_key_exists('desktop_items', $payload)) {
            $workspace->desktop_items = $this->sanitizeDesktopItems($payload['desktop_items'] ?? []);
        }

        if (array_key_exists('taskbar_items', $payload)) {
            $workspace->taskbar_items = $this->sanitizeTaskbarItems($payload['taskbar_items'] ?? []);
        }

        if (array_key_exists('preferences', $payload)) {
            $incoming = is_array($payload['preferences'] ?? null) ? $payload['preferences'] : [];
            // 「覆盖传统后台」双开关为全局设置（2.1.133）：抽取后写入全局占位行，
            // 不落账号工作区——任一超级管理员开启即对所有账号生效（仅超管可改由控制器剔除保证）
            $globalValues = array_intersect_key($incoming, array_flip(self::GLOBAL_PREFERENCES));
            if ($globalValues !== []) {
                $this->saveGlobalPreferences($globalValues);
            }
            // 账号工作区剥离全局键：避免残留历史个人值，后续判定只看全局行
            $current = is_array($workspace->preferences) ? $workspace->preferences : [];
            foreach (self::GLOBAL_PREFERENCES as $key) {
                unset($current[$key]);
            }
            $workspace->preferences = $this->sanitizePreferences(
                $current,
                array_diff_key($incoming, array_flip(self::GLOBAL_PREFERENCES))
            );
        }

        $workspace->save();

        // 响应回填全局状态（仅内存），前端各账号展示同一开关状态
        return $this->withGlobalPreferences($workspace->refresh());
    }

    /** @return array<int,array<string,mixed>> */
    protected function sanitizeDesktopItems(array $items): array
    {
        // 超限拒绝保存而非静默截断：截断会让排在数组尾部的项（如文件夹）在响应回填后凭空消失
        if (count($items) > self::DESKTOP_ITEM_LIMIT) {
            throw new InvalidArgumentException(
                '桌面项数量超出上限（' . self::DESKTOP_ITEM_LIMIT . '），请先整理或移除部分图标'
            );
        }

        return collect($items)
            ->map(fn (array $item): array => $this->sanitizeDesktopItem($item))
            ->values()
            ->all();
    }

    /** 任务栏固定项：结构同桌面项但无坐标，最多 12 个 */
    protected function sanitizeTaskbarItems(array $items): array
    {
        return collect(array_slice($items, 0, 12))
            ->map(fn (array $item): array => Arr::except($this->sanitizeDesktopItem($item), [
                'x', 'y', 'w', 'h', 'params',
                'px_width', 'px_height', 'px_left', 'px_top',
            ]))
            ->values()
            ->all();
    }

    /** @return array<string,mixed> */
    protected function sanitizeDesktopItem(array $item): array
    {
        $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($item['id'] ?? '')) ?: Str::uuid()->toString();
        // 桌面原生项类型：folder=桌面文件夹（前端浮层管理内容）、url=URL 链接（iframe 外链窗口）、
        // widget=桌面小工具（2.1.106，内容取自注册表 widget_key 指向的 /api/ 数据接口），空为普通应用/菜单入口
        $kind = in_array($item['kind'] ?? '', ['folder', 'url', 'widget'], true) ? $item['kind'] : '';
        // 所属桌面文件夹 id（文件夹子项）：仅允许安全字符；文件夹自身不允许再挂入文件夹
        $parentFolder = Str::limit(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($item['parent_folder'] ?? '')), 64, '');
        $widgetKey = '';
        $widgetParams = [];
        $path = trim((string) ($item['path'] ?? ''));
        if ($kind === 'folder') {
            // 文件夹内容由前端浮层按 parent_folder 关系管理，path 不参与打开逻辑，强制置空
            $path = '';
            $parentFolder = '';
        } elseif ($kind === 'url') {
            // URL 链接放行 http(s) 外链（前端以 iframe 窗口打开），其余地址仍拒绝
            if (! preg_match('/^https?:\/\/[^\s]+$/i', $path)) {
                throw new InvalidArgumentException('URL 链接必须是 http(s) 地址');
            }
        } elseif ($kind === 'widget') {
            // 小工具内容由 widget_key 指向的注册表项与 /api/ 数据接口提供，不承载跳转：path 强制置空
            $path = '';
            $parentFolder = '';
            $widgetKey = Str::limit(
                preg_replace('/[^A-Za-z0-9_.:-]/', '', (string) ($item['widget_key'] ?? '')),
                100,
                ''
            );
            if ($widgetKey === '' || ! str_contains($widgetKey, ':')) {
                throw new InvalidArgumentException('桌面小工具缺少有效的 widget_key');
            }
            // 多实例实例参数：仅小工具携带，非小工具项一律不写
            $widgetParams = $this->widgetParamsOf($item['params'] ?? null);
        } elseif ($path === '') {
            // 内置浏览器入口（id=webos-browser）path 为空：内容由前端管理、按 id 特判打开；其余入口仍必须是站内路径
            if ($id !== 'webos-browser') {
                throw new InvalidArgumentException('桌面入口路径必须是站内路径');
            }
        } elseif (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            throw new InvalidArgumentException('桌面入口路径必须是站内路径');
        }

        $icon = preg_replace('/[^A-Za-z0-9 _-]/', '', (string) ($item['icon'] ?? 'fa fa-cube'));
        $appId = preg_replace('/[^A-Za-z0-9_.-]/', '', (string) ($item['app_id'] ?? ''));

        $sanitized = [
            'id' => Str::limit($id, 80, ''),
            'menu_id' => isset($item['menu_id']) ? max(0, (int) $item['menu_id']) : null,
            'app_id' => Str::limit($appId, 100, ''),
            'title' => Str::limit(trim((string) ($item['title'] ?? '未命名应用')), 60, ''),
            'path' => Str::limit($path, 500, ''),
            'icon' => Str::limit($icon ?: 'fa fa-cube', 100, ''),
            'group_title' => Str::limit(trim((string) ($item['group_title'] ?? '应用')), 60, ''),
            'kind' => $kind,
            'parent_folder' => $parentFolder,
            'widget_key' => $widgetKey,
            'x' => min(99, max(0, (int) ($item['x'] ?? 0))),
            'y' => min(99, max(0, (int) ($item['y'] ?? 0))),
        ];

        // 仅小工具卡片带占格数：宽 1..4 格、高 1..3 格，非数字或越界回落 1（其它类型无尺寸概念）
        if ($kind === 'widget') {
            $sanitized['w'] = $this->widgetSpanOf($item['w'] ?? null, self::MAX_WIDGET_SPAN_WIDTH);
            $sanitized['h'] = $this->widgetSpanOf($item['h'] ?? null, self::MAX_WIDGET_SPAN_HEIGHT);
            // 像素级自由尺寸（2.1.115）：px_width/px_height 为卡片像素宽高，px_left/px_top 为
            // 西/北边拖拽调尺寸后的像素位置；非法或越界字段直接不落库，前端按网格字段降级渲染
            $pxWidth = $this->widgetPixelOf($item['px_width'] ?? null, self::MAX_WIDGET_PIXEL_SIZE);
            $pxHeight = $this->widgetPixelOf($item['px_height'] ?? null, self::MAX_WIDGET_PIXEL_SIZE);
            $pxLeft = $this->widgetPixelOf($item['px_left'] ?? null, self::MAX_WIDGET_PIXEL_OFFSET, 0);
            $pxTop = $this->widgetPixelOf($item['px_top'] ?? null, self::MAX_WIDGET_PIXEL_OFFSET, 0);
            if ($pxWidth !== null && $pxHeight !== null) {
                $sanitized['px_width'] = $pxWidth;
                $sanitized['px_height'] = $pxHeight;
            }
            if ($pxLeft !== null) {
                $sanitized['px_left'] = $pxLeft;
            }
            if ($pxTop !== null) {
                $sanitized['px_top'] = $pxTop;
            }
            if ($widgetParams !== []) {
                $sanitized['params'] = $widgetParams;
            }
        }

        return $sanitized;
    }

    /**
     * 小工具实例参数净化：键名小写字母开头、最多 MAX_WIDGET_PARAMS 个、值 ≤64 字符
     *
     * 非法键或超长值直接跳过，不影响其余参数。
     *
     * @return array<string,string>
     */
    protected function widgetParamsOf(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $params = [];

        foreach ($value as $key => $paramValue) {
            if (count($params) >= self::MAX_WIDGET_PARAMS) {
                break;
            }

            if (! is_string($key) || ! preg_match(self::WIDGET_PARAM_KEY_PATTERN, $key)) {
                continue;
            }

            // 只接受字符串/整数，布尔与数组、对象一律视为非法
            if (! is_string($paramValue) && ! is_int($paramValue)) {
                continue;
            }

            $text = trim((string) $paramValue);
            if (mb_strlen($text) > self::MAX_WIDGET_PARAM_VALUE_LENGTH) {
                continue;
            }

            $params[$key] = $text;
        }

        return $params;
    }

    /** 小工具卡片占格数：非数字或越界（超出 1..$max）一律回落单格 */
    protected function widgetSpanOf(mixed $value, int $max): int
    {
        $span = is_numeric($value) ? (int) $value : 1;

        return $span >= 1 && $span <= $max ? $span : 1;
    }

    /** 小工具像素值：非数值或越界返回 null（不落库）；$min 传 0 用于像素位置，宽度高度从 1 起 */
    protected function widgetPixelOf(mixed $value, int $max, int $min = 1): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $pixel = (int) round((float) $value);

        return $pixel >= $min && $pixel <= $max ? $pixel : null;
    }

    /** @return array<string,mixed> */
    protected function sanitizePreferences(array $current, array $incoming): array
    {
        $allowed = Arr::only($incoming, array_keys(self::DEFAULT_PREFERENCES));
        $preferences = array_replace(self::DEFAULT_PREFERENCES, $current, $allowed);

        $preferences['wallpaper'] = in_array($preferences['wallpaper'], ['webos-default', 'deep-blue'], true)
            ? $preferences['wallpaper']
            : self::DEFAULT_PREFERENCES['wallpaper'];
        $preferences['wallpaper_url'] = $this->sanitizeWallpaperUrl((string) ($preferences['wallpaper_url'] ?? ''));
        // 桌面图标三档尺寸（右键「查看」切换）：非法值回退中图标
        $preferences['icon_size'] = in_array($preferences['icon_size'] ?? '', ['small', 'medium', 'large'], true)
            ? $preferences['icon_size']
            : self::DEFAULT_PREFERENCES['icon_size'];
        // 图标文字效果（OS 设置单选）：none=无效果（默认）、shadow=Win11 风格文字阴影、shield=半透明深色底衬
        $preferences['label_effect'] = in_array($preferences['label_effect'] ?? '', ['none', 'shadow', 'shield'], true)
            ? $preferences['label_effect']
            : self::DEFAULT_PREFERENCES['label_effect'];
        // 界面主题（OS 设置「界面主题」Tab，2.1.136）：default=CmsPro 经典、win11=Win11 浅色，非法值回退默认
        $preferences['ui_theme'] = in_array($preferences['ui_theme'] ?? '', ['default', 'win11'], true)
            ? $preferences['ui_theme']
            : self::DEFAULT_PREFERENCES['ui_theme'];
        // 自定义主题库（2.1.144「细节调整」）：逐条净化并限制数量，启用 ID 必须存在于库中
        $preferences['ui_theme_customs'] = $this->sanitizeThemeCustoms($preferences['ui_theme_customs'] ?? []);
        $allowedThemeIds = array_column($preferences['ui_theme_customs'], 'id');
        $preferences['ui_theme_custom_id'] = in_array((string) ($preferences['ui_theme_custom_id'] ?? ''), $allowedThemeIds, true)
            ? $preferences['ui_theme_custom_id']
            : self::DEFAULT_PREFERENCES['ui_theme_custom_id'];
        // 应用窗口多选项卡（OS 设置开关，默认关闭）
        $preferences['window_tabs'] = (bool) ($preferences['window_tabs'] ?? false);
        // 选项卡刷新（OS 设置开关，默认关闭）：多选项卡模式下点击左侧菜单重新加载对应页面内容
        $preferences['tab_reload'] = (bool) ($preferences['tab_reload'] ?? false);
        // 点击菜单进入（OS 设置开关，默认关闭=打开系统菜单面板；开启=直接进入「全部功能」启动台）
        $preferences['menu_open_launcher'] = (bool) ($preferences['menu_open_launcher'] ?? false);
        $preferences['taskbar_alignment'] = in_array($preferences['taskbar_alignment'], ['left', 'center'], true)
            ? $preferences['taskbar_alignment']
            : self::DEFAULT_PREFERENCES['taskbar_alignment'];
        $preferences['taskbar_position'] = in_array(
            $preferences['taskbar_position'],
            ['top', 'bottom', 'left', 'right'],
            true
        ) ? $preferences['taskbar_position'] : self::DEFAULT_PREFERENCES['taskbar_position'];
        $preferences['clock_format'] = $preferences['clock_format'] === '12h' ? '12h' : '24h';
        $preferences['show_seconds'] = (bool) $preferences['show_seconds'];
        $preferences['motion'] = (bool) $preferences['motion'];
        $preferences['window_width'] = $this->sanitizePercent(
            $preferences['window_width'],
            self::DEFAULT_PREFERENCES['window_width']
        );
        $preferences['window_height'] = $this->sanitizePercent(
            $preferences['window_height'],
            self::DEFAULT_PREFERENCES['window_height']
        );
        $preferences['override_admin_home'] = (bool) $preferences['override_admin_home'];
        $preferences['override_user_home'] = (bool) $preferences['override_user_home'];
        // 任务栏行为（Windows 式）：自动隐藏任务栏、锁定任务栏（锁定后固定图标不可拖动）
        $preferences['taskbar_autohide'] = (bool) $preferences['taskbar_autohide'];
        $preferences['taskbar_locked'] = (bool) $preferences['taskbar_locked'];
        // 任务栏图标显示方式（Windows 式）：icon=纯图标（默认）、text=图标+文字
        $preferences['taskbar_button_style'] = in_array($preferences['taskbar_button_style'] ?? '', ['icon', 'text'], true)
            ? $preferences['taskbar_button_style']
            : self::DEFAULT_PREFERENCES['taskbar_button_style'];
        $preferences['usage_stats'] = $this->sanitizeUsageStats($preferences['usage_stats']);
        // 被忽略的可用更新（应用中心「忽略」按钮）：app_id@版本号，仅忽略当前版本，应用发布更新版本后重新提示
        $preferences['ignored_updates'] = $this->sanitizeIgnoredUpdates($preferences['ignored_updates'] ?? []);
        // 站点入口快捷访问菜单（2.1.129）：后台/用户端各一份，OS 设置「快捷访问设置」可增删改查
        $preferences['quick_access'] = $this->sanitizeQuickAccess($preferences['quick_access'] ?? []);

        return $preferences;
    }

    /** 重置后台管理员工作区（按终端口径见 resetFor） */
    public function resetForAdmin(int $adminUserId): WebosWorkspace
    {
        return $this->resetFor('admin', $adminUserId);
    }

    /**
     * 按终端重置工作区：桌面/任务栏恢复初始默认布局，偏好恢复默认
     * （不影响已上传的自定义壁纸文件）。返回重置后的工作区模型。
     * 超级管理员初始固定「应用中心」；非超管桌面/任务栏为空（应用中心仅超管可用，与前端初始化一致）。
     * 用户端无应用中心能力，重置后桌面/任务栏一律为空。
     */
    public function resetFor(string $terminal, int $userId): WebosWorkspace
    {
        $workspace = $this->getFor($terminal, $userId);
        $isSuperAdmin = $terminal === 'admin' && (bool) Auth::guard('admin')->user()?->isSuperAdmin();

        $defaultEntry = [
            'id' => 'webos-app-center',
            'menu_id' => null,
            'app_id' => 'cmspro.webos',
            'title' => '应用中心',
            'path' => '/admin/cmspro/webos?app=market',
            'icon' => 'fa fa-shopping-bag',
            'group_title' => 'WebOS',
            'x' => 0,
            'y' => 0,
        ];
        $workspace->desktop_items = $isSuperAdmin ? [$defaultEntry] : [];
        // 任务栏项结构与桌面项一致但无坐标
        $workspace->taskbar_items = $isSuperAdmin ? [Arr::except($defaultEntry, ['x', 'y'])] : [];
        $workspace->preferences = self::DEFAULT_PREFERENCES;
        $workspace->save();

        return $workspace->refresh();
    }

    /**
     * 自定义壁纸路径只允许本应用壁纸目录下的相对路径，防止注入外部或越权地址。
     */
    protected function sanitizeWallpaperUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        return preg_match('#^apps/cmspro\.webos/wallpapers/[A-Za-z0-9_-]+\.(jpg|jpeg|png|gif|webp)$#', $url)
            ? $url
            : '';
    }

    /**
     * 净化自定义主题库（2.1.144「细节调整」）：丢弃非数组条目与非法 ID，ID 冲突保留先出现者，
     * 超过 MAX_UI_THEME_CUSTOMS 从尾部丢弃；返回以 ID 为键的列表（array_values 后连续索引）。
     *
     * @param mixed $customs 工作区偏好中的 ui_theme_customs 原始值
     * @return array<int,array{id:string,name:string,base:string,overrides:array<string,mixed>}>
     */
    protected function sanitizeThemeCustoms($customs): array
    {
        if (! is_array($customs)) {
            return [];
        }

        $sanitized = [];
        foreach ($customs as $custom) {
            if (count($sanitized) >= self::MAX_UI_THEME_CUSTOMS) {
                break;
            }
            if (! is_array($custom)) {
                continue;
            }
            $id = (string) ($custom['id'] ?? '');
            if (! preg_match(self::THEME_ID_PATTERN, $id) || isset($sanitized[$id])) {
                continue;
            }
            $name = mb_substr(trim((string) ($custom['name'] ?? '')), 0, 30);

            $sanitized[$id] = [
                'id' => $id,
                'name' => $name !== '' ? $name : '未命名主题',
                'base' => in_array($custom['base'] ?? '', ['default', 'win11'], true)
                    ? $custom['base']
                    : 'default',
                'overrides' => $this->sanitizeThemeOverrides($custom['overrides'] ?? []),
            ];
        }

        return array_values($sanitized);
    }

    /**
     * 净化自定义主题的细节覆盖值：仅保留 THEME_OVERRIDE_RULES 声明的键，
     * 色值按正则校验（统一小写），数值越界时 clamp 到区间；非法值丢弃（缺键=跟随基础主题默认）。
     *
     * @param mixed $overrides 主题条目中的 overrides 原始值
     * @return array<string,int|string>
     */
    protected function sanitizeThemeOverrides($overrides): array
    {
        if (! is_array($overrides)) {
            return [];
        }

        $result = [];
        foreach (self::THEME_OVERRIDE_RULES as $key => $rule) {
            $value = $overrides[$key] ?? null;
            if (is_string($rule)) {
                // 十六进制色值（accent）：大小写宽容（先归一化小写再校验），通过才保留
                if (is_string($value) && preg_match($rule, strtolower($value))) {
                    $result[$key] = strtolower($value);
                }
                continue;
            }
            if (! is_numeric($value)) {
                continue;
            }
            $result[$key] = (int) max($rule[0], min($rule[1], round((float) $value)));
        }

        return $result;
    }

    /**
     * 窗口默认尺寸按可用桌面区域的百分比存储，限制在 40% - 100% 之间。
     */
    protected function sanitizePercent(mixed $value, int $fallback): int
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        return (int) min(100, max(40, (int) round((float) $value)));
    }

    /** @return array<string,array{count:int,last_opened_at:string}> */
    protected function sanitizeUsageStats(mixed $statistics): array
    {
        if (! is_array($statistics)) {
            return [];
        }

        $sanitized = [];
        foreach ($statistics as $key => $statistic) {
            if (
                ! is_string($key)
                || ! preg_match('/^[A-Za-z0-9:._-]{1,100}$/', $key)
                || ! is_array($statistic)
            ) {
                continue;
            }

            $lastOpenedAt = (string) ($statistic['last_opened_at'] ?? '');
            if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $lastOpenedAt)) {
                continue;
            }

            $sanitized[$key] = [
                'count' => min(999999, max(1, (int) ($statistic['count'] ?? 0))),
                'last_opened_at' => $lastOpenedAt,
            ];
        }

        // 超限裁剪（2.1.137）：按最近打开时间降序、次数降序保留前 USAGE_STATS_LIMIT 条，
        // 丢弃最久未用的统计，而不是按插入顺序截断（旧实现会随机丢掉常用入口且不报错）
        if (count($sanitized) > self::USAGE_STATS_LIMIT) {
            uasort($sanitized, static function (array $left, array $right): int {
                return [$right['last_opened_at'], $right['count']] <=> [$left['last_opened_at'], $left['count']];
            });
            $sanitized = array_slice($sanitized, 0, self::USAGE_STATS_LIMIT, true);
        }

        return $sanitized;
    }

    /**
     * 净化被忽略的可用更新列表（应用中心「忽略本次版本更新」）：
     * 仅保留 app_id@版本号 形态（与前端 sanitizeIgnoredUpdates 一致），去重后最多 200 条。
     *
     * @return list<string>
     */
    protected function sanitizeIgnoredUpdates(mixed $ignored): array
    {
        if (! is_array($ignored)) {
            return [];
        }

        $sanitized = [];
        foreach ($ignored as $item) {
            if (! is_string($item) || ! preg_match('/^[A-Za-z0-9_.-]{1,100}@[A-Za-z0-9_.-]{1,64}$/', $item)) {
                continue;
            }
            $sanitized[$item] = true;
        }

        return array_slice(array_keys($sanitized), 0, 200);
    }

    /**
     * 站点入口快捷访问菜单（2.1.129）：按端净化为 {label,url,icon} 列表。
     * label 1-20 字符；url 仅允许站内路径（单个 / 开头，拒绝协议相对地址 //）或 http(s) 外链，≤500 字符；
     * icon 为可选的字体图标类名（字母数字与短横线/空格，≤60 字符）；每端最多 8 项，非法项直接丢弃。
     */
    protected function sanitizeQuickAccess(mixed $value): array
    {
        $sanitized = [];
        foreach (['admin', 'user'] as $terminal) {
            $items = is_array($value[$terminal] ?? null) ? $value[$terminal] : [];
            $clean = [];
            foreach (array_slice($items, 0, 8) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $label = trim((string) ($item['label'] ?? ''));
                $url = trim((string) ($item['url'] ?? ''));
                $icon = trim((string) ($item['icon'] ?? ''));
                if ($label === '' || $url === '' || mb_strlen($label) > 20 || mb_strlen($url) > 500) {
                    continue;
                }
                $isSitePath = str_starts_with($url, '/') && ! str_starts_with($url, '//');
                if (! $isSitePath && ! preg_match('#^https?://#i', $url)) {
                    continue;
                }
                if ($icon !== '' && ! preg_match('/^[A-Za-z0-9][A-Za-z0-9 _-]{0,59}$/', $icon)) {
                    $icon = '';
                }
                $clean[] = ['label' => $label, 'url' => $url, 'icon' => $icon];
            }
            $sanitized[$terminal] = $clean;
        }

        return $sanitized;
    }
}
