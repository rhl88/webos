<?php

use App\Apps\CmsproWebos\Controllers\Admin\WebosController;
use App\Apps\CmsproWebos\Http\Middleware\EnsureSuperAdmin;
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
    // 「用户桌面/管理桌面」预设路由组：覆盖传统后台相关能力为全局设置，仅超级管理员可用（2.1.104）；
    // 普通管理员统一 403（code 40101），前端 OS 设置对其隐藏「覆盖传统后台」卡片与对应两个 Tab
    Route::middleware(EnsureSuperAdmin::class)->group(function () {
        // 「用户桌面」预设（OS 设置 → 用户桌面 Tab）：新用户首次进入用户端 WebOS 的初始桌面图标
        Route::get('/user-desktop-preset', [WebosController::class, 'userDesktopPreset'])->name('user-desktop-preset.show');
        Route::put('/user-desktop-preset', [WebosController::class, 'saveUserDesktopPreset'])->name('user-desktop-preset.update');
        // 「用户桌面」查看/代改指定前台用户桌面（OS 设置 → 用户桌面 Tab 搜索用户，2.1.99）
        Route::get('/user-desktop/users', [WebosController::class, 'searchUsers'])->name('user-desktop-users.index');
        Route::get('/user-desktop', [WebosController::class, 'userDesktop'])->name('user-desktop.show');
        Route::put('/user-desktop', [WebosController::class, 'saveUserDesktop'])->name('user-desktop.update');
        // 「管理桌面」按角色预设（OS 设置 → 管理桌面 Tab）：按后台角色预设管理员初始桌面图标
        Route::get('/admin-desktop-preset', [WebosController::class, 'adminDesktopPreset'])->name('admin-desktop-preset.show');
        Route::put('/admin-desktop-preset', [WebosController::class, 'saveAdminDesktopPreset'])->name('admin-desktop-preset.update');
        // 「管理桌面」查看/代改指定管理员桌面（OS 设置 → 管理桌面 Tab 预设角色行右侧搜索管理员，2.1.100）
        Route::get('/admin-desktop/admins', [WebosController::class, 'searchAdmins'])->name('admin-desktop-admins.index');
        Route::get('/admin-desktop', [WebosController::class, 'adminWorkspaceDesktop'])->name('admin-desktop.show');
        Route::put('/admin-desktop', [WebosController::class, 'saveAdminWorkspaceDesktop'])->name('admin-desktop.update');
    });
});
