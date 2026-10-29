<?php

namespace App\Apps\CmsproWebos\Services;

use App\Models\AppModel;
use Illuminate\Support\Str;

/**
 * 桌面小工具注册表（2.1.106）
 *
 * 聚合已启用应用 manifest.widgets 声明，供桌面右键「小工具」面板展示与添加。
 * manifest 由安装器写入 apps.manifest（全量快照），因此注册表无需新增表结构，
 * 应用启用后其小工具自动出现在面板中，禁用/卸载后自动消失。
 *
 * widgets 段声明格式（数组，单应用最多 5 项）：
 * [
 *   {"id":"service-status","title":"本地服务状态","icon":"fa fa-server",
 *    "entry":"/api/admin/cmspro/localservice/widget/status","refresh":60}
 * ]
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

    /**
     * 全部可用小工具清单
     *
     * @return array<int,array{widget_key:string,app_id:string,id:string,title:string,icon:string,entry:string,refresh:int,desktop_id:string}>
     */
    public function list(): array
    {
        $widgets = [];

        foreach (AppModel::query()->enabled()->orderBy('id')->get(['app_id', 'manifest']) as $app) {
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

        return [
            'widget_key' => $appId . ':' . $id,
            'app_id' => $appId,
            'id' => $id,
            'title' => $title,
            'icon' => Str::limit($icon ?: 'fa fa-cube', 100, ''),
            'entry' => $entry,
            'refresh' => $this->refreshOf($declaration['refresh'] ?? null),
            'desktop_id' => $desktopId,
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
}