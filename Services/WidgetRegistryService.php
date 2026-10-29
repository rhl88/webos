<?php

namespace App\Apps\CmsproWebos\Services;

use App\Models\AppModel;
use Illuminate\Support\Str;

/**
 * 桌面小工具注册表（2.1.106）
 *
 * 聚合已启用应用 manifest.widgets 声明，供桌面右键「小工具」面板展示与添加。
 * manifest 由安装器写入 apps.manifest（全量快照），快照缺少 widgets 时回退读取
 * 应用目录下的 manifest.json（2.1.107）；因此注册表无需新增表结构，
 * 应用启用后其小工具自动出现在面板中，禁用/卸载后自动消失。
 *
 * widgets 段声明格式（数组，单应用最多 5 项）：
 * [
 *   {"id":"service-status","title":"本地服务状态","icon":"fa fa-server",
 *    "entry":"/api/admin/cmspro/localservice/widget/status","refresh":60,
 *    "sizes":["1x1","2x2"],"default_size":"1x1",
 *    "actions":[{"id":"new-note","title":"新增便签","icon":"fa fa-plus",
 *      "method":"POST","entry":"/api/admin/cmspro/notepad/widget/quick-note",
 *      "input":{"label":"便签内容","placeholder":"随手记一条…","maxlength":500}}]}
 * ]
 *
 * sizes 白名单 1x1 / 2x1 / 2x2（桌面格子，缺省 1x1）；actions 最多 2 项，
 * method 固定 POST，entry 必须站内 /api/ 路径，input 可选（有输入框则提交 input.name 字段，缺省 content）。
 *
 * 2.1.109 起：
 * - picker 声明（可选）让小工具支持多实例：{entry,param,label}，entry 返回候选数据源，
 *   用户选中某项后以 param 作为实例参数写入桌面项 params，desktop_id 追加实例后缀。
 * - actions[].input.type 新增 select（下拉选项，options 2~8 项），用于「标记颜色」这类枚举操作。
 */
class WidgetRegistryService
{
    /** 单应用最多声明的小工具数量 */
    public const MAX_PER_APP = 5;

    /** 桌面项 id 上限（与 desktopItemsRules() 的 max:80 一致） */
    public const DESKTOP_ID_LIMIT = 80;

    /** 默认刷新间隔（秒） */
    public const DEFAULT_REFRESH = 60;

    /** 刷新间隔允许区间（秒） */
    public const MIN_REFRESH = 15;

    public const MAX_REFRESH = 3600;

    /** 小工具允许的尺寸档位（宽x高，单位：桌面格子） */
    public const SIZE_WHITELIST = ['1x1', '2x1', '2x2'];

    /** 单个小工具最多声明的操作按钮数量 */
    public const MAX_ACTIONS = 2;

    /** 操作按钮 id 规则（与 widget id 一致） */
    protected const ACTION_ID_PATTERN = '/^[a-z0-9][a-z0-9-]{0,31}$/';

    /** 操作输入框提交字段名规则（应用可自定义，缺省 content） */
    protected const INPUT_NAME_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';

    /** 操作输入框最大长度上限 */
    protected const MAX_INPUT_LENGTH = 2000;

    /** picker 数据源接口最多返回的候选数量 */
    public const MAX_PICKER_ITEMS = 50;

    /** select 输入框选项数量区间 */
    public const MIN_ACTION_OPTIONS = 2;

    public const MAX_ACTION_OPTIONS = 8;

    /** select 选项值最大长度（允许空串，表示「默认/无」） */
    protected const MAX_OPTION_VALUE_LENGTH = 32;

    /**
     * 全部可用小工具清单
     *
     * @return array<int,array{widget_key:string,app_id:string,id:string,title:string,icon:string,entry:string,refresh:int,desktop_id:string}>
     */
    public function list(): array
    {
        $widgets = [];

        foreach (AppModel::query()->enabled()->orderBy('id')->get(['app_id', 'path', 'manifest']) as $app) {
            foreach ($this->widgetsOf($app) as $widget) {
                $widgets[] = $widget;
            }
        }

        return $widgets;
    }

    /**
     * 解析单个应用的 widget 声明：越界声明跳过，不中断整表聚合
     *
     * @return array<int,array<string,mixed>>
     */
    protected function widgetsOf(AppModel $app): array
    {
        $manifest = is_array($app->manifest) ? $app->manifest : [];
        $declarations = $manifest['widgets'] ?? null;

        // apps.manifest 是安装/升级时写入的全量快照，应用后续版本新增的 widgets 段
        // 不会自动回写；快照缺省时回退读取磁盘 manifest.json，避免老应用小工具永久缺失
        if (! is_array($declarations)) {
            $declarations = $this->readManifestFromDisk($app)['widgets'] ?? null;
        }

        if (! is_array($declarations)) {
            return [];
        }

        $appId = (string) $app->app_id;
        $widgets = [];

        foreach ($declarations as $declaration) {
            if (count($widgets) >= self::MAX_PER_APP) {
                break;
            }

            $widget = $this->sanitizeWidget($appId, $declaration);
            if ($widget !== null) {
                $widgets[] = $widget;
            }
        }

        return $widgets;
    }

    /**
     * 读取应用目录下的 manifest.json（快照缺省 widgets 时的回退数据源）
     *
     * 解析结果必须真实存在于 app/Apps/ 之内，防止 apps.path 异常值造成路径穿越；
     * 文件缺失、读取失败或 JSON 非法时一律返回空数组，不影响其它应用聚合。
     *
     * @return array<string,mixed>
     */
    protected function readManifestFromDisk(AppModel $app): array
    {
        $baseDir = realpath(base_path('app/Apps'));
        if ($baseDir === false) {
            return [];
        }

        $file = realpath($app->resolvePath() . DIRECTORY_SEPARATOR . 'manifest.json');
        if ($file === false || ! is_file($file)) {
            return [];
        }

        // Windows 路径大小写不敏感，前缀比较统一按不区分大小写处理
        if (strncasecmp($file, $baseDir . DIRECTORY_SEPARATOR, strlen($baseDir) + 1) !== 0) {
            return [];
        }

        $contents = file_get_contents($file);
        if ($contents === false || $contents === '') {
            return [];
        }

        $manifest = json_decode($contents, true);

        return is_array($manifest) ? $manifest : [];
    }

    /**
     * 单条声明净化：任一必填项不合法即整条跳过（不报错、不影响其它声明）
     *
     * @return array<string,mixed>|null
     */
    protected function sanitizeWidget(string $appId, mixed $declaration): ?array
    {
        if (! is_array($declaration)) {
            return null;
        }

        $id = (string) ($declaration['id'] ?? '');
        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $id)) {
            return null;
        }

        $title = Str::limit(trim((string) ($declaration['title'] ?? '')), 60, '');
        if ($title === '') {
            return null;
        }

        // 小工具数据接口：必须站内 /api/ 路径，外链与后台页面路径一律拒绝
        $entry = trim((string) ($declaration['entry'] ?? ''));
        if (mb_strlen($entry) > 500 || ! preg_match('#^/api/[^\s]*$#', $entry)) {
            return null;
        }

        // 桌面项 id 只允许 [A-Za-z0-9_-]：app_id 中的点号统一转为下划线
        $desktopId = 'widget-' . str_replace('.', '_', $appId) . '-' . $id;
        if (mb_strlen($desktopId) > self::DESKTOP_ID_LIMIT) {
            return null;
        }

        $icon = preg_replace('/[^A-Za-z0-9 _-]/', '', (string) ($declaration['icon'] ?? ''));
        $sizes = $this->sizesOf($declaration['sizes'] ?? null);

        return [
            'widget_key' => $appId . ':' . $id,
            'app_id' => $appId,
            'id' => $id,
            'title' => $title,
            'icon' => Str::limit($icon ?: 'fa fa-cube', 100, ''),
            'entry' => $entry,
            'refresh' => $this->refreshOf($declaration['refresh'] ?? null),
            'sizes' => $sizes,
            'default_size' => $this->defaultSizeOf($declaration['default_size'] ?? null, $sizes),
            'actions' => $this->actionsOf($declaration['actions'] ?? null),
            'picker' => $this->pickerOf($declaration['picker'] ?? null),
            'desktop_id' => $desktopId,
        ];
    }

    /**
     * 多实例数据源声明：{entry,param,label}
     *
     * 任一字段非法即整段丢弃（小工具退化为单实例，不影响主体功能）。
     *
     * @return array{entry:string,param:string,label:string}|null
     */
    protected function pickerOf(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $entry = trim((string) ($value['entry'] ?? ''));
        if (mb_strlen($entry) > 500 || ! preg_match('#^/api/[^\s]*$#', $entry)) {
            return null;
        }

        $param = (string) ($value['param'] ?? '');
        if (! preg_match(self::INPUT_NAME_PATTERN, $param)) {
            return null;
        }

        $label = Str::limit(trim((string) ($value['label'] ?? '')), 20, '');

        return [
            'entry' => $entry,
            'param' => $param,
            'label' => $label === '' ? '选择数据' : $label,
        ];
    }

    /** 刷新间隔：非数字或越界一律回落默认值 */
    protected function refreshOf(mixed $value): int
    {
        $refresh = is_numeric($value) ? (int) $value : self::DEFAULT_REFRESH;

        if ($refresh < self::MIN_REFRESH || $refresh > self::MAX_REFRESH) {
            return self::DEFAULT_REFRESH;
        }

        return $refresh;
    }

    /**
     * 尺寸档位：仅保留白名单内档位并去重，非法或空声明回落 1x1
     *
     * @return array<int,string>
     */
    protected function sizesOf(mixed $value): array
    {
        $sizes = [];

        if (is_array($value)) {
            foreach ($value as $size) {
                if (is_string($size) && in_array($size, self::SIZE_WHITELIST, true) && ! in_array($size, $sizes, true)) {
                    $sizes[] = $size;
                }
            }
        }

        return $sizes === [] ? [self::SIZE_WHITELIST[0]] : $sizes;
    }

    /** 默认尺寸：必须落在允许档位内，否则取首个允许档位 */
    protected function defaultSizeOf(mixed $value, array $sizes): string
    {
        return is_string($value) && in_array($value, $sizes, true) ? $value : $sizes[0];
    }

    /**
     * 卡片内操作按钮（最多 2 项）：写操作统一 POST 到站内 /api/ 接口
     *
     * @return array<int,array<string,mixed>>
     */
    protected function actionsOf(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $actions = [];
        $usedIds = [];

        foreach ($value as $declaration) {
            if (count($actions) >= self::MAX_ACTIONS) {
                break;
            }

            if (! is_array($declaration)) {
                continue;
            }

            $id = (string) ($declaration['id'] ?? '');
            if (! preg_match(self::ACTION_ID_PATTERN, $id) || isset($usedIds[$id])) {
                continue;
            }

            $title = Str::limit(trim((string) ($declaration['title'] ?? '')), 30, '');
            if ($title === '') {
                continue;
            }

            $entry = trim((string) ($declaration['entry'] ?? ''));
            if (mb_strlen($entry) > 500 || ! preg_match('#^/api/[^\s]*$#', $entry)) {
                continue;
            }

            $icon = preg_replace('/[^A-Za-z0-9 _-]/', '', (string) ($declaration['icon'] ?? ''));

            // select 输入声明非法（选项不足 2 项等）时整条操作丢弃，避免渲染空下拉
            $input = $this->actionInputOf($declaration['input'] ?? null);
            if ($input === false) {
                continue;
            }

            $usedIds[$id] = true;
            $actions[] = [
                'id' => $id,
                'title' => $title,
                'icon' => Str::limit($icon, 100, ''),
                'method' => 'POST',
                'entry' => $entry,
                'input' => $input,
            ];
        }

        return $actions;
    }

    /**
     * 操作按钮输入框声明
     *
     * 返回 null 表示无输入框（点击直接提交）；返回 false 表示声明非法，整条操作丢弃。
     *
     * @return array<string,mixed>|null|false
     */
    protected function actionInputOf(mixed $value): array|null|false
    {
        if (! is_array($value)) {
            return null;
        }

        $label = Str::limit(trim((string) ($value['label'] ?? '')), 30, '');
        if ($label === '') {
            return null;
        }

        // 应用可自定义提交字段名，非法或未声明统一回落 content
        $name = (string) ($value['name'] ?? '');
        if (! preg_match(self::INPUT_NAME_PATTERN, $name)) {
            $name = 'content';
        }

        if ((string) ($value['type'] ?? 'text') === 'select') {
            $options = $this->actionOptionsOf($value['options'] ?? null);

            return $options === [] ? false : [
                'type' => 'select',
                'label' => $label,
                'name' => $name,
                'options' => $options,
            ];
        }

        $maxlength = is_numeric($value['maxlength'] ?? null) ? (int) $value['maxlength'] : 0;
        if ($maxlength < 1 || $maxlength > self::MAX_INPUT_LENGTH) {
            $maxlength = 200;
        }

        return [
            'type' => 'text',
            'label' => $label,
            'placeholder' => Str::limit(trim((string) ($value['placeholder'] ?? '')), 60, ''),
            'maxlength' => $maxlength,
            'name' => $name,
        ];
    }

    /**
     * select 选项解析：值允许空串（表示默认/无），label 去重
     *
     * 有效选项不足 MIN_ACTION_OPTIONS 时返回空数组，由调用方丢弃整条操作。
     *
     * @return array<int,array{value:string,label:string}>
     */
    protected function actionOptionsOf(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $options = [];
        $usedValues = [];
        $usedLabels = [];

        foreach ($value as $option) {
            if (count($options) >= self::MAX_ACTION_OPTIONS) {
                break;
            }

            if (! is_array($option)) {
                continue;
            }

            $optionValue = $option['value'] ?? null;
            if (! is_string($optionValue) || mb_strlen($optionValue) > self::MAX_OPTION_VALUE_LENGTH) {
                continue;
            }

            $optionLabel = Str::limit(trim((string) ($option['label'] ?? '')), 20, '');
            if ($optionLabel === '' || isset($usedLabels[$optionLabel]) || isset($usedValues[$optionValue])) {
                continue;
            }

            $usedValues[$optionValue] = true;
            $usedLabels[$optionLabel] = true;
            $options[] = ['value' => $optionValue, 'label' => $optionLabel];
        }

        return count($options) >= self::MIN_ACTION_OPTIONS ? $options : [];
    }
}