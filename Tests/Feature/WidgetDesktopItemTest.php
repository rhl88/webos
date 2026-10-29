<?php

namespace App\Apps\CmsproWebos\Tests\Feature;

use App\Apps\CmsproWebos\Tests\WebosTestCase;

/**
 * 桌面小工具落到桌面项（2.1.106）：kind=widget + widget_key 的保存与校验契约。
 * 小工具项不承载跳转，path 必须为空；widget_key 为 {app_id}:{widget_id} 原始标识。
 */
class WidgetDesktopItemTest extends WebosTestCase
{
    /** 合法的桌面小工具项载荷 */
    private function widgetItem(array $overrides = []): array
    {
        return array_merge([
            'id' => 'widget-cmspro_localservice-service-status',
            'title' => '本地服务状态',
            'kind' => 'widget',
            'widget_key' => 'cmspro.localservice:service-status',
            'path' => '',
            'icon' => 'fa fa-server',
            'x' => 1,
            'y' => 2,
        ], $overrides);
    }

    public function test_workspace_api_saves_widget_desktop_item(): void
    {
        $this->actingAdmin();

        $this->putJson('/admin/cmspro/webos/api/workspace', [
            'desktop_items' => [$this->widgetItem()],
            'preferences' => [],
        ])->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.desktop_items.0.kind', 'widget')
            ->assertJsonPath('data.desktop_items.0.widget_key', 'cmspro.localservice:service-status')
            ->assertJsonPath('data.desktop_items.0.path', '');

        // 再次读取验证持久化（widget_key 冒号在净化后仍保留）
        $this->getJson('/admin/cmspro/webos/api/workspace')
            ->assertOk()
            ->assertJsonPath('data.desktop_items.0.kind', 'widget')
            ->assertJsonPath('data.desktop_items.0.widget_key', 'cmspro.localservice:service-status');
    }

    public function test_workspace_api_rejects_widget_without_widget_key(): void
    {
        $this->actingAdmin();

        $item = $this->widgetItem();
        unset($item['widget_key']);

        $response = $this->putJson('/admin/cmspro/webos/api/workspace', [
            'desktop_items' => [$item],
            'preferences' => [],
        ])->assertUnprocessable()
            ->assertJsonPath('code', 40201);

        $this->assertArrayHasKey('desktop_items.0.widget_key', $response->json('data'));
    }

    public function test_workspace_api_rejects_widget_with_path(): void
    {
        $this->actingAdmin();

        $response = $this->putJson('/admin/cmspro/webos/api/workspace', [
            'desktop_items' => [$this->widgetItem(['path' => '/admin/cmspro/localservice'])],
            'preferences' => [],
        ])->assertUnprocessable()
            ->assertJsonPath('code', 40201);

        $this->assertArrayHasKey('desktop_items.0.path', $response->json('data'));
    }

    public function test_workspace_api_rejects_invalid_widget_key(): void
    {
        $this->actingAdmin();

        $invalidKeys = [
            'localservice-service-status',            // 缺少 app_id 分隔冒号
            'cmspro.localservice:Service_Status',     // widget id 含大写与下划线
            'cmspro.localservice:',                   // widget id 为空
        ];

        foreach ($invalidKeys as $invalidKey) {
            $response = $this->putJson('/admin/cmspro/webos/api/workspace', [
                'desktop_items' => [$this->widgetItem(['widget_key' => $invalidKey])],
                'preferences' => [],
            ])->assertUnprocessable()
                ->assertJsonPath('code', 40201);

            $this->assertArrayHasKey('desktop_items.0.widget_key', $response->json('data'), $invalidKey);
        }
    }

    public function test_workspace_api_rejects_unknown_desktop_item_kind(): void
    {
        $this->actingAdmin();

        $item = $this->widgetItem();
        unset($item['widget_key']);
        $item['kind'] = 'sticky';
        $item['path'] = '/admin/cmspro/localservice';

        $response = $this->putJson('/admin/cmspro/webos/api/workspace', [
            'desktop_items' => [$item],
            'preferences' => [],
        ])->assertUnprocessable()
            ->assertJsonPath('code', 40201);

        $this->assertArrayHasKey('desktop_items.0.kind', $response->json('data'));
    }
}