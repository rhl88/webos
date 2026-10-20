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
    // 当前用户标识（昵称/账号/头像）：个人设置窗口关闭后刷新状态栏展示
    Route::get('/admin-identity', [WebosController::class, 'adminIdentity'])->name('admin-identity');
});
