<?php

use App\Apps\CmsproWebos\Controllers\Admin\WebosController;
use Illuminate\Support\Facades\Route;

// 桌面主页面 index 路由已移至 ServiceProvider（未登录可达，控制器内分流登录窗口/桌面），
// 本文件所有路由均在 ServiceProvider 的 auth:admin 组内

Route::prefix('api')->name('api.')->group(function () {
    Route::get('/workspace', [WebosController::class, 'workspace'])->name('workspace.show');
    Route::put('/workspace', [WebosController::class, 'saveWorkspace'])->name('workspace.update');
    Route::post('/workspace/reset', [WebosController::class, 'resetWorkspace'])->name('workspace.reset');
    Route::post('/wallpaper', [WebosController::class, 'uploadWallpaper'])->name('wallpaper.store');
    Route::get('/wallpapers', [WebosController::class, 'wallpapers'])->name('wallpaper.index');
    Route::delete('/wallpaper', [WebosController::class, 'destroyWallpaper'])->name('wallpaper.destroy');
    Route::get('/calendar', [WebosController::class, 'calendar'])->name('calendar');
    Route::get('/all-todos', [WebosController::class, 'allTodos'])->name('all-todos');
    // 停用应用菜单列表（开始菜单灰化展示停用应用用；应用停用时框架联动禁用其菜单，userMenus 不再返回）
    Route::get('/start-disabled-menus', [WebosController::class, 'startDisabledMenus'])->name('start-disabled-menus');
    // 前台菜单链接兼容转换（域名绑定/子域名部署 → 可直达 URL）
    Route::get('/home-menu-urls', [WebosController::class, 'homeMenuUrls'])->name('home-menu-urls');
    // 内置浏览器页面元信息（title/favicon 代抓，跨域 iframe 读不到）
    Route::get('/page-meta', [WebosController::class, 'pageMeta'])->name('page-meta');
    // 当前管理员标识（姓名/账号/头像）：个人设置窗口关闭后刷新状态栏展示
    Route::get('/admin-identity', [WebosController::class, 'adminIdentity'])->name('admin-identity');
});
