<?php

namespace App\Apps\CmsproWebos;

use App\Apps\CmsproWebos\Services\WorkspaceService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Illuminate\Support\Facades\View;
use Throwable;

class ServiceProvider extends BaseServiceProvider
{
    public function boot(): void
    {
        Route::prefix('admin/cmspro/webos')
            ->middleware(['web', 'auth:admin'])
            ->name('cmspro.webos.admin.')
            ->group(__DIR__ . '/Routes/admin.php');

        $this->loadViewsFrom(__DIR__ . '/Views', 'cmspro.webos');

        // 通知中心待办数据源：其他应用通过系统 hook「admin.notifications.todos」聚合接入
        // （WebOS 自身不注册演示数据），待办点击后记录已见数量（AdminTodoRead），
        // 通知中心窗口的完整列表（含已读）由 WebosController::allTodos 提供。

        // 传统后台注入「进入 WebOS」入口：利用系统布局预留的
        // @stack('page_styles')（head 内）与 @stack('page_scripts')（body 末尾）挂载点，
        // 通过 View composer 的 startPush 注入，不修改系统布局文件。
        // 应用禁用/卸载后本 ServiceProvider 不加载，入口与覆盖跳转自动消失。
        View::composer('layouts.admin', function ($view): void {
            $factory = $view->getFactory();
            $entryVersion = $this->assetVersion();
            $webosUrl = route('cmspro.webos.admin.index');

            // head 内样式：右下角翻书页入口
            $factory->startPush('page_styles', '<link rel="stylesheet" href="'
                . asset('apps/cmspro.webos/css/entry.css') . '?v=' . $entryVersion . '">');

            // 「覆盖传统后台」：管理员在 OS 设置开启后，访问后台首页（admin.index）
            // 直接进入 WebOS 桌面。放 head 内尽早执行减少传统首页闪现；
            // 地址带 skip_webos=1 可临时绕过；仅在首页路由判断，其余页面零开销。
            if (request()->routeIs('admin.index') && ! request()->boolean('skip_webos') && auth('admin')->check()) {
                try {
                    $preferences = app(WorkspaceService::class)->getForAdmin((int) auth('admin')->id())->preferences;
                    if (! empty($preferences['override_admin_home'])) {
                        $factory->startPush('page_styles', '<script>location.replace("' . $webosUrl . '");</script>');
                    }
                } catch (Throwable) {
                }
            }

            // body 末尾脚本：入口 DOM 载体（带跳转地址）+ 构建脚本（含升级按钮避让）
            $factory->startPush('page_scripts', '<div id="webos-float-book-carrier" hidden data-url="' . $webosUrl . '"></div>'
                . '<script src="' . asset('apps/cmspro.webos/js/entry.js') . '?v=' . $entryVersion . '"></script>');
        });

        // 登录页 WebOS 化（2.1.74）：「覆盖传统后台」开启后，后台登录页（auth.login → layouts.auth）
        // 注入重塑样式，呈现为 Windows 风格的 WebOS 登录窗口（全屏壁纸 + 毛玻璃居中卡片）。
        // 纯 CSS 重塑：表单结构、CSRF、验证码刷新、回车提交、错误回显全部复用传统登录页，零后端改动。
        // 未登录状态无 per-admin 偏好，override_admin_home 以「任一管理员开启即生效」口径读取。
        View::composer('layouts.auth', function ($view): void {
            if (! request()->routeIs('admin.login')) {
                return;
            }

            try {
                if (! WorkspaceService::anyAdminOverridesAdminHome()) {
                    return;
                }
            } catch (Throwable) {
                return;
            }

            $factory = $view->getFactory();
            $loginVersion = $this->assetVersion();
            // 资源地址经 asset() 生成后以 CSS 变量下发（壁纸/头像），样式文件内不硬编码路径，
            // 兼容子目录部署
            $factory->startPush('styles', '<style>:root{--webos-login-wallpaper:url("'
                . asset('apps/cmspro.webos/images/webos-wallpaper.png') . '");--webos-login-avatar:url("'
                . asset('Admin/images/avatar.png') . '");}</style>'
                . '<link rel="stylesheet" href="' . asset('apps/cmspro.webos/css/login.css') . '?v=' . $loginVersion . '">');
        });
    }

    /**
     * 入口静态资源版本号：取 entry.js/css、login.css 的最新修改时间，避免浏览器缓存旧版资源。
     */
    protected function assetVersion(): int
    {
        $version = 1;

        foreach (['css/entry.css', 'js/entry.js', 'css/login.css'] as $asset) {
            $path = __DIR__ . '/Assets/' . $asset;
            if (is_file($path)) {
                $version = max($version, (int) filemtime($path));
            }
        }

        return $version;
    }
}
