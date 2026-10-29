<?php

namespace App\Apps\CmsproWebos\Tests\Feature;

use App\Apps\CmsproWebos\Services\WidgetRegistryService;
use App\Apps\CmsproWebos\Tests\WebosTestCase;
use App\Models\AppModel;

/**
 * 桌面小工具注册表（2.1.106）：聚合已启用应用 manifest.widgets 声明，
 * 输出桌面「小工具」面板所需的 widget 清单（含桌面项 id 映射）。
 */
class WidgetRegistryTest extends WebosTestCase
{
    /** 含 widgets 声明的磁盘 manifest 夹具目录（相对项目根） */
    private const FIXTURE_APP_PATH = 'app/Apps/CmsproWebos/Tests/Fixtures/WidgetApp';

    /** 不带 manifest.json 的目录，用于回退读取失败的场景 */
    private const MISSING_APP_PATH = 'app/Apps/CmsproWebos/Tests/Fixtures/MissingApp';

    /**
     * 造一条应用记录：apps.manifest 是安装器写入的全量快照，widgets 段即小工具声明来源。
     *
     * @param mixed $widgets 传入 null 表示 manifest 中不含 widgets 段
     * @param string|null $path 应用目录（相对项目根），null 时按 app/Apps/{appId} 推导
     */
    private function makeApp(string $appId, mixed $widgets = null, int $status = 1, ?string $path = null): AppModel
    {
        $manifest = ['id' => $appId, 'name' => $appId];
        if ($widgets !== null) {
            $manifest['widgets'] = $widgets;
        }

        return AppModel::create([
            'app_id' => $appId,
            'name' => $appId,
            'version' => '1.0.0',
            'path' => $path ?? 'app/Apps/' . $appId,
            'status' => $status,
            'manifest' => $manifest,
        ]);
    }

    /** 合法的 widget 声明，可按需覆盖字段 */
    private function widget(string $id, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'title' => '小工具-' . $id,
            'icon' => 'fa fa-server',
            'entry' => '/api/admin/cmspro/localservice/widget/status',
            'refresh' => 30,
        ], $overrides);
    }

    private function registry(): WidgetRegistryService
    {
        return app(WidgetRegistryService::class);
    }

    public function test_registry_lists_widgets_of_enabled_apps(): void
    {
        $this->makeApp('cmspro.localservice', [
            $this->widget('service-status', ['title' => '本地服务状态']),
            $this->widget('task-count'),
        ]);

        $items = $this->registry()->list();

        $this->assertCount(2, $items);
        $this->assertEquals([
            'widget_key' => 'cmspro.localservice:service-status',
            'app_id' => 'cmspro.localservice',
            'id' => 'service-status',
            'title' => '本地服务状态',
            'icon' => 'fa fa-server',
            'entry' => '/api/admin/cmspro/localservice/widget/status',
            'refresh' => 30,
            'desktop_id' => 'widget-cmspro_localservice-service-status',
        ], $items[0]);
        // 桌面项 id 只允许 [A-Za-z0-9_-]，app_id 中的点号统一转为下划线
        $this->assertSame('widget-cmspro_localservice-task-count', $items[1]['desktop_id']);
    }

    public function test_registry_skips_disabled_or_uninstalled_apps(): void
    {
        $this->makeApp('cmspro.notepad', [$this->widget('sticky')], 2);
        $this->makeApp('cmspro.kylinidproxy', [$this->widget('token-usage')], 0);

        $this->assertSame([], $this->registry()->list());
    }

    public function test_registry_skips_apps_without_widget_definitions(): void
    {
        $this->makeApp('cmspro.demo');
        $this->makeApp('cmspro.broken', 'not-an-array');

        $this->assertSame([], $this->registry()->list());
    }

    public function test_registry_skips_invalid_widget_ids(): void
    {
        $this->makeApp('cmspro.localservice', [
            $this->widget('Service-Status'),
            $this->widget('service status'),
            $this->widget(''),
            ['title' => '缺少 id', 'entry' => '/api/x'],
            $this->widget('service-status'),
        ]);

        $items = $this->registry()->list();

        $this->assertCount(1, $items);
        $this->assertSame('service-status', $items[0]['id']);
    }

    public function test_registry_skips_widgets_with_non_api_entry(): void
    {
        $this->makeApp('cmspro.localservice', [
            $this->widget('a', ['entry' => '/admin/cmspro/localservice/service/status']),
            $this->widget('b', ['entry' => 'https://example.com/api/status']),
            $this->widget('c', ['entry' => '']),
            $this->widget('d'),
        ]);

        $items = $this->registry()->list();

        $this->assertCount(1, $items);
        $this->assertSame('d', $items[0]['id']);
    }

    public function test_registry_limits_widgets_per_app(): void
    {
        $this->makeApp('cmspro.localservice', [
            $this->widget('w1'),
            $this->widget('w2'),
            $this->widget('w3'),
            $this->widget('w4'),
            $this->widget('w5'),
            $this->widget('w6'),
        ]);

        $items = $this->registry()->list();

        $this->assertCount(5, $items);
        $this->assertSame(['w1', 'w2', 'w3', 'w4', 'w5'], array_column($items, 'id'));
    }

    public function test_registry_skips_widget_whose_desktop_id_exceeds_limit(): void
    {
        // apps.app_id 上限 50 字符；widget- 前缀 + 50 + 连字符 + 32 位 id = 90 > 80（桌面项 id 上限）
        $longAppId = 'cmspro.' . str_repeat('a', 43);
        $this->makeApp($longAppId, [$this->widget(str_repeat('b', 32))]);

        $this->assertSame([], $this->registry()->list());
    }

    public function test_registry_defaults_icon_and_refresh(): void
    {
        $this->makeApp('cmspro.localservice', [
            [
                'id' => 'service-status',
                'title' => '本地服务状态',
                'entry' => '/api/admin/cmspro/localservice/widget/status',
            ],
            $this->widget('task-count', ['refresh' => 5]),
        ]);

        $items = $this->registry()->list();

        $this->assertSame('fa fa-cube', $items[0]['icon']);
        $this->assertSame(60, $items[0]['refresh']);
        // 刷新间隔低于 15 秒视为非法，回落默认 60 秒
        $this->assertSame(60, $items[1]['refresh']);
    }

    public function test_registry_falls_back_to_disk_manifest_when_snapshot_has_no_widgets(): void
    {
        // 老应用快照不含 widgets 段：回退读取应用目录下的 manifest.json
        $this->makeApp('cmspro.widgetfixture', null, 1, self::FIXTURE_APP_PATH);

        $items = $this->registry()->list();

        $this->assertCount(1, $items);
        $this->assertSame('disk-widget', $items[0]['id']);
        $this->assertSame('磁盘声明小工具', $items[0]['title']);
        $this->assertSame('fa fa-hdd', $items[0]['icon']);
        $this->assertSame('widget-cmspro_widgetfixture-disk-widget', $items[0]['desktop_id']);
    }

    public function test_registry_returns_empty_when_app_manifest_file_missing(): void
    {
        // 目录不存在时回退读取失败：返回空清单，不抛异常、不影响其它应用
        $this->makeApp('cmspro.missingapp', null, 1, self::MISSING_APP_PATH);

        $this->assertSame([], $this->registry()->list());
    }

    public function test_registry_prefers_snapshot_widgets_over_disk_manifest(): void
    {
        // 快照中已有 widgets 段：以快照为准，不再读磁盘文件
        $this->makeApp('cmspro.widgetfixture', [
            $this->widget('snapshot-widget', ['title' => '快照声明小工具']),
        ], 1, self::FIXTURE_APP_PATH);

        $items = $this->registry()->list();

        $this->assertCount(1, $items);
        $this->assertSame('snapshot-widget', $items[0]['id']);
        $this->assertSame('快照声明小工具', $items[0]['title']);
    }

    public function test_widgets_api_returns_registry_for_admin(): void
    {
        $this->makeApp('cmspro.localservice', [$this->widget('service-status')]);

        $this->getJson('/admin/cmspro/webos/api/widgets')->assertUnauthorized();

        $this->actingAdmin();

        $this->getJson('/admin/cmspro/webos/api/widgets')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.0.widget_key', 'cmspro.localservice:service-status')
            ->assertJsonPath('data.0.desktop_id', 'widget-cmspro_localservice-service-status');
    }
}