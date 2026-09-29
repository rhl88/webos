<?php

namespace App\Apps\CmsproWebos\Tests\Feature;

use App\Apps\CmsproWebos\Tests\WebosTestCase;

class WebosWorkspaceApiTest extends WebosTestCase
{
    public function test_guest_is_redirected_to_admin_login(): void
    {
        $this->get('/admin/cmspro/webos')
            ->assertRedirect('/admin/login');
    }

    public function test_admin_can_open_webos_desktop(): void
    {
        $this->actingAdmin();

        $this->get('/admin/cmspro/webos')
            ->assertOk()
            ->assertSee('CMSPRO WebOS')
            ->assertSee('webos-desktop')
            ->assertSee('任务栏位置')
            ->assertSee('data-taskbar-position="bottom"', false);
    }

    public function test_admin_can_read_and_save_workspace(): void
    {
        $this->actingAdmin();

        $this->getJson('/admin/cmspro/webos/api/workspace')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.preferences.wallpaper', 'webos-default');

        $payload = [
            'desktop_items' => [[
                'id' => 'menu-10',
                'menu_id' => 10,
                'title' => '用户管理',
                'path' => '/admin/user',
                'icon' => 'fa fa-users',
                'group_title' => '系统管理',
                'x' => 1,
                'y' => 2,
            ]],
            'preferences' => [
                'wallpaper' => 'webos-default',
                'taskbar_alignment' => 'left',
                'taskbar_position' => 'left',
                'clock_format' => '24h',
                'show_seconds' => false,
                'motion' => true,
            ],
        ];

        $this->putJson('/admin/cmspro/webos/api/workspace', $payload)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.desktop_items.0.title', '用户管理')
            ->assertJsonPath('data.preferences.taskbar_position', 'left');
    }

    public function test_workspace_api_rejects_an_unknown_taskbar_position(): void
    {
        $this->actingAdmin();

        $this->putJson('/admin/cmspro/webos/api/workspace', [
            'preferences' => ['taskbar_position' => 'diagonal'],
        ])->assertUnprocessable()
            ->assertJsonPath('code', 40201);
    }

    public function test_start_menu_application_cards_support_keyboard_activation(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');

        $this->assertIsString($script);
        $this->assertStringContainsString("event.key === 'Enter' || event.key === ' '", $script);
        $this->assertStringContainsString('startItem.dataset.menuId', $script);
    }

    public function test_install_dialog_uses_terminal_aware_menu_mounting(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/Assets/js/webos.js');
        $view = file_get_contents(dirname(__DIR__, 2) . '/Views/Admin/desktop/index.blade.php');

        $this->assertIsString($script);
        $this->assertIsString($view);
        $this->assertStringContainsString('/menu-terminals', $script);
        $this->assertStringContainsString('/prepare', $script);
        $this->assertStringContainsString('terminal_type=', $script);
        $this->assertStringContainsString('parent_menu_ids', $script);
        $this->assertStringContainsString('id="install-menu-parents"', $view);
    }

    public function test_workspace_api_rejects_external_paths(): void
    {
        $this->actingAdmin();

        $response = $this->putJson('/admin/cmspro/webos/api/workspace', [
            'desktop_items' => [[
                'id' => 'unsafe',
                'title' => '外部站点',
                'path' => 'https://example.com',
                'icon' => 'fa fa-link',
                'x' => 0,
                'y' => 0,
            ]],
            'preferences' => [],
        ])->assertUnprocessable()
            ->assertJsonPath('code', 40201);

        $this->assertArrayHasKey('desktop_items.0.path', $response->json('data'));
    }
}
