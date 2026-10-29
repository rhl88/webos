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

    public function test_workspace_api_persists_widget_size(): void
    {
        $this->actingAdmin();

        $this->putJson('/admin/cmspro/webos/api/workspace', [
            'desktop_items' => [$this->widgetItem(['w' => 2, 'h' => 2])],
            'preferences' => [],
        ])->assertOk()
            ->assertJsonPath('data.desktop_items.0.w', 2)
            ->assertJsonPath('data.desktop_items.0.h', 2);

        $this->getJson('/admin/cmspro/webos/api/workspace')
            ->assertOk()
            ->assertJsonPath('data.desktop_items.0.w', 2)
            ->assertJsonPath('data.desktop_items.0.h', 2);
    }

    public function test_workspace_api_defaults_widget_size_to_single_cell(): void
    {
        $this->actingAdmin();

        $this->putJson('/admin/cmspro/webos/api/workspace', [
            'desktop_items' => [$this->widgetItem()],
            'preferences' => [],
        ])->assertOk()
            ->assertJsonPath('data.desktop_items.0.w', 1)
            ->assertJsonPath('data.desktop_items.0.h', 1);
    }

    public function test_workspace_api_rejects_widget_size_out_of_range(): void
    {
        $this->actingAdmin();

        $response = $this->putJson('/admin/cmspro/webos/api/workspace', [
            'desktop_items' => [$this->widgetItem(['w' => 3, 'h' => 0])],
            'preferences' => [],
        ])->assertUnprocessable()
            ->assertJsonPath('code', 40201);

        $errors = $response->json('data');
        $this->assertArrayHasKey('desktop_items.0.w', $errors);
        $this->assertArrayHasKey('desktop_items.0.h', $errors);
    }

    public function test_workspace_api_omits_size_for_non_widget_item(): void
    {
        $this->actingAdmin();

        $this->putJson('/admin/cmspro/webos/api/workspace', [
            'desktop_items' => [[
                'id' => 'menu-100',
                'menu_id' => 100,
                'title' => '便签列表',
                'path' => '/admin/cmspro/notepad/notes',
                'icon' => 'fa fa-list',
                'x' => 0,
                'y' => 0,
                'w' => 2,
                'h' => 2,
            ]],
            'preferences' => [],
        ])->assertOk();

        $item = $this->getJson('/admin/cmspro/webos/api/workspace')
            ->assertOk()
            ->json('data.desktop_items.0');

        // 尺寸仅属于小工具卡片：普通入口不落存 w/h
        $this->assertArrayNotHasKey('w', $item);
        $this->assertArrayNotHasKey('h', $item);
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