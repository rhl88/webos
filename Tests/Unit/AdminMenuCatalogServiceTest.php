<?php

namespace App\Apps\CmsproWebos\Tests\Unit;

use App\Apps\CmsproWebos\Services\AdminMenuCatalogService;
use PHPUnit\Framework\TestCase;

class AdminMenuCatalogServiceTest extends TestCase
{
    public function test_it_keeps_only_admin_and_legacy_admin_menus(): void
    {
        $menus = [
            [
                'id' => 1,
                'name' => '后台管理',
                'terminal_type' => 'admin',
                'children' => [
                    ['id' => 11, 'name' => '后台子菜单', 'terminal_type' => 'admin'],
                    ['id' => 12, 'name' => '用户端子菜单', 'terminal_type' => 'user'],
                ],
            ],
            ['id' => 2, 'name' => '用户中心', 'terminal_type' => 'user'],
            ['id' => 3, 'name' => '前端导航', 'terminal_type' => 'home'],
            ['id' => 4, 'name' => '旧版后台菜单', 'terminal_type' => null],
            ['id' => 5, 'name' => '钩子注入的后台菜单'],
        ];

        $filtered = (new AdminMenuCatalogService())->filterAdminMenus($menus);

        $this->assertSame([1, 4, 5], array_column($filtered, 'id'));
        $this->assertSame([11], array_column($filtered[0]['children'], 'id'));
    }
}
