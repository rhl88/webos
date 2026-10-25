<?php

namespace App\Apps\CmsproWebos\Tests\Feature;

use App\Apps\CmsproWebos\Tests\WebosTestCase;

/**
 * URL/表单直达启动（2.1.102）：GET/POST 携带 app=应用标识[&to=菜单路径] 访问桌面入口，
 * 注入 webosRuntime.launch，桌面就绪后自动打开对应应用窗口；未登录时登录表单
 * redirect 回跳值保留直达参数，登录成功后 302 直达。
 * 匹配仅限当前账号已授权菜单（前端 flatMenus），无权限或停用的应用自然匹配不到。
 */
class WebosLaunchParamTest extends WebosTestCase
{
    public function test_get_request_with_app_param_injects_launch_into_runtime(): void
    {
        $this->actingAdmin();

        $response = $this->get('/admin/cmspro/webos?app=Cmsprohome');
        $response->assertOk();
        // 视图数据层精确断言（不受 Js::from 渲染转义影响）
        $this->assertSame(['app' => 'Cmsprohome', 'to' => ''], $response->viewData('webosRuntime')['launch']);
        // 渲染层：Js::from 输出中引号转义为 \u0022，确认 launch 已注入页面脚本
        $response->assertSee('\u0022launch\u0022', false);
    }

    public function test_post_request_with_app_param_injects_launch_into_runtime(): void
    {
        $this->actingAdmin();

        $response = $this->post('/admin/cmspro/webos', ['app' => 'Cmsprohome']);
        $response->assertOk();
        $this->assertSame(['app' => 'Cmsprohome', 'to' => ''], $response->viewData('webosRuntime')['launch']);
    }

    public function test_request_without_app_param_has_no_launch(): void
    {
        $this->actingAdmin();

        $response = $this->get('/admin/cmspro/webos');
        $response->assertOk();
        $runtime = $response->viewData('webosRuntime');
        $this->assertArrayNotHasKey('launch', $runtime);
        $response->assertDontSee('\u0022launch', false);
    }

    public function test_to_param_with_full_path_is_carried(): void
    {
        $this->actingAdmin();

        $response = $this->get('/admin/cmspro/webos?app=Cmsprohome&to=/admin/cmsprohome/product');
        $response->assertOk();
        $this->assertSame(
            ['app' => 'Cmsprohome', 'to' => '/admin/cmsprohome/product'],
            $response->viewData('webosRuntime')['launch']
        );
    }

    public function test_to_param_with_tail_segment_is_carried_verbatim(): void
    {
        $this->actingAdmin();

        $response = $this->get('/admin/cmspro/webos?app=Cmsprohome&to=product');
        $response->assertOk();
        $this->assertSame(
            ['app' => 'Cmsprohome', 'to' => 'product'],
            $response->viewData('webosRuntime')['launch']
        );
    }

    public function test_oversized_launch_params_are_truncated(): void
    {
        $this->actingAdmin();

        $response = $this->get('/admin/cmspro/webos?app=' . str_repeat('a', 100) . '&to=' . str_repeat('b', 300));
        $response->assertOk();
        // app 截断 64 字符、to 截断 255 字符，防垃圾参数撑爆页面
        $this->assertSame(
            ['app' => str_repeat('a', 64), 'to' => str_repeat('b', 255)],
            $response->viewData('webosRuntime')['launch']
        );
    }

    public function test_guest_login_view_keeps_launch_query_in_redirect(): void
    {
        // 未登录访问直达链接：登录表单 redirect 回跳值保留 app/to 参数（Blade 转义 & 为 &amp;）
        $this->get('/admin/cmspro/webos?app=Cmsprohome&to=product')
            ->assertOk()
            ->assertSee('name="redirect" value="/admin/cmspro/webos?app=Cmsprohome&amp;to=product"', false);
    }

    public function test_guest_login_view_without_params_keeps_plain_redirect(): void
    {
        $this->get('/admin/cmspro/webos')
            ->assertOk()
            ->assertSee('name="redirect" value="/admin/cmspro/webos"', false);
    }

    public function test_user_terminal_injects_launch_for_both_channels(): void
    {
        $this->actingUser();

        $response = $this->get('/user/cmspro/webos?app=Cmsprohome');
        $response->assertOk();
        $this->assertSame(['app' => 'Cmsprohome', 'to' => ''], $response->viewData('webosRuntime')['launch']);

        $response = $this->post('/user/cmspro/webos', ['app' => 'Cmsprohome']);
        $response->assertOk();
        $this->assertSame(['app' => 'Cmsprohome', 'to' => ''], $response->viewData('webosRuntime')['launch']);
    }

    public function test_user_terminal_guest_login_view_keeps_launch_query(): void
    {
        $this->get('/user/cmspro/webos?app=Cmsprohome')
            ->assertOk()
            ->assertSee('name="redirect" value="/user/cmspro/webos?app=Cmsprohome"', false);
    }
}
