<?php

use App\Apps\CmsproWebos\Controllers\User\WebosController;
use Illuminate\Support\Facades\Route;

// 用户端 API：均在 ServiceProvider 的 auth:web + front_user_status 组内；
// 通知中心/应用中心/官网动态等管理能力已裁剪，不提供对应路由
Route::prefix('api')->name('api.')->group(function () {
    Route::get('/workspace', [WebosController::class, 'workspace'])->name('workspace.show');
    Route::put('/workspace', [WebosController::class, 'saveWorkspace'])->name('workspace.update');
    Route::post('/workspace/reset', [WebosController::class, 'resetWorkspace'])->name('workspace.reset');
    Route::post('/wallpaper', [WebosController::class, 'uploadWallpaper'])->name('wallpaper.store');
    Route::get('/wallpapers', [WebosController::class, 'wallpapers'])->name('wallpaper.index');
    Route::delete('/wallpaper', [WebosController::class, 'destroyWallpaper'])->name('wallpaper.destroy');
    Route::get('/calendar', [WebosController::class, 'calendar'])->name('calendar');
    // 当前用户标识（昵称/账号/头像）：个人设置窗口关闭后刷新状态栏展示，兼作会话心跳探测
    Route::get('/admin-identity', [WebosController::class, 'adminIdentity'])->name('admin-identity');
    // 用户端开始菜单数据源（terminal_type=user 菜单树，与 admin 端 userMenus 输出同构）
    Route::get('/menus', [WebosController::class, 'menus'])->name('menus');
    // 窗口「前台菜单」数据源（terminal_type=home 菜单树，与框架 /api/admin/menus/tree?terminal_type=home 同构）；
    // 用户端不得调用 admin 受保护接口，否则未登录后台时会触发会话恢复层并误报「会话过期」
    Route::get('/menus/tree', [WebosController::class, 'homeMenuTree'])->name('menus.tree');
    // 前台菜单 URL 兼容转换（域名绑定应用原始路径换算为访问地址），复用父类实现
    Route::get('/home-menu-urls', [WebosController::class, 'homeMenuUrls'])->name('home-menu-urls');
    // 用户端可见应用清单（菜单涉及应用，提供名称/图标，供开始菜单与桌面入口树等渲染）
    Route::get('/applications', [WebosController::class, 'applications'])->name('applications');
    // 会话恢复：419 后静默换取新 CSRF Token（与框架 admin 端 csrf-token 接口行为一致）
    Route::get('/csrf-token', [WebosController::class, 'csrfToken'])->name('csrf-token');
    // 内置浏览器页面元信息（title/favicon 代抓，跨域 iframe 读不到；不依赖身份，按端共用实现）
    Route::get('/page-meta', [WebosController::class, 'pageMeta'])->name('page-meta');
    // 会话心跳/恢复层轮询登录态（轻量接口，仅判断登录是否有效）
    Route::get('/identity', [WebosController::class, 'identity'])->name('identity');
});
