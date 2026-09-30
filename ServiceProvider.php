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
    }

    /**
     * 入口静态资源版本号：取 entry.js/css 的最新修改时间，避免浏览器缓存旧版资源。
     */
    protected function assetVersion(): int
    {
        $version = 1;

        foreach (['css/entry.css', 'js/entry.js'] as $asset) {
            $path = __DIR__ . '/Assets/' . $asset;
            if (is_file($path)) {
                $version = max($version, (int) filemtime($path));
            }
        }

        return $version;
    }
}
