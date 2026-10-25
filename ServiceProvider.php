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
        // 桌面主页面（index）不挂 auth:admin：未登录访问时由控制器渲染 WebOS 登录窗口（2.1.77），
        // 已登录渲染桌面；2.1.102 起 GET/POST 双通道（URL/表单携带 app=应用标识[&to=菜单路径]
        // 直达启动应用窗口）；其余 API 路由全部保持 auth:admin
        Route::prefix('admin/cmspro/webos')
            ->middleware(['web'])
            ->name('cmspro.webos.admin.')
            ->group(function (): void {
                Route::match(['get', 'post'], '/', [\App\Apps\CmsproWebos\Controllers\Admin\WebosController::class, 'index'])->name('index');

                Route::middleware('auth:admin')->group(__DIR__ . '/Routes/admin.php');
            });

        // 用户端 WebOS 桌面（2.1.80）：入口页面不挂 auth:web（未登录由控制器分流框架
        // 用户登录页），API 路由挂 auth:web + front_user_status，与框架用户端路由口径一致；
        // 工作区按 terminal + admin_user_id 与后台隔离，UI 复用 admin 端同一桌面视图
        Route::prefix('user/cmspro/webos')
            ->middleware(['web'])
            ->name('cmspro.webos.user.')
            ->group(function (): void {
                Route::match(['get', 'post'], '/', [\App\Apps\CmsproWebos\Controllers\User\WebosController::class, 'index'])->name('index');

                Route::middleware(['auth:web', 'front_user_status'])->group(__DIR__ . '/Routes/user.php');
            });

        // 「覆盖传统后台」登录页接管（2.1.79）：web 组前置中间件——任一管理员开启覆盖后，
        // 未登录 GET /admin/login 临时重定向到 WebOS 登录窗口（POST 登录提交不拦，判定异常放行）
        $this->app['router']->prependMiddlewareToGroup('web', Http\Middleware\RedirectAdminLoginToWebos::class);

        // 「用户首页直达 WebOS」（2.1.82，2.1.84 修正注册位置）：web 组后置中间件——
        // 任一管理员在 OS 设置开启后，已登录前台用户 GET /user 首页临时重定向到 WebOS 用户端桌面
        // （skip_webos=1 可绕过）。必须后置：判定「已登录」依赖 Session，须晚于 web 组内
        // StartSession 启动（Laravel 按中间件优先级排序，prepend 会被排到 StartSession 之前，
        // 使 auth('web')->check() 恒为 false 而漏判放行）。
        $this->app['router']->pushMiddlewareToGroup('web', Http\Middleware\RedirectUserHomeToWebos::class);

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
