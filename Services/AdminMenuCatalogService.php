<?php

namespace App\Apps\CmsproWebos\Services;

class AdminMenuCatalogService
{
    /**
     * WebOS 是后台管理桌面，开始菜单仅展示后台终端菜单。
     *
     * terminal_type 为空的数据属于旧版后台菜单，需要继续兼容；
     * 未携带 terminal_type 的钩子菜单同样按后台菜单处理。
     */
    public function filterAdminMenus(array $menus): array
    {
        $filtered = [];

        foreach ($menus as $menu) {
            $terminal = $menu['terminal_type'] ?? 'admin';
            if (! in_array($terminal, ['admin', '', null], true)) {
                continue;
            }

            if (isset($menu['children']) && is_array($menu['children'])) {
                $menu['children'] = $this->filterAdminMenus($menu['children']);
            }

            $filtered[] = $menu;
        }

        return $filtered;
    }
}
