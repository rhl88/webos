<?php

use App\Apps\CmsproWebos\Controllers\Admin\WebosController;
use Illuminate\Support\Facades\Route;

Route::get('/', [WebosController::class, 'index'])->name('index');

Route::prefix('api')->name('api.')->group(function () {
    Route::get('/workspace', [WebosController::class, 'workspace'])->name('workspace.show');
    Route::put('/workspace', [WebosController::class, 'saveWorkspace'])->name('workspace.update');
    Route::post('/wallpaper', [WebosController::class, 'uploadWallpaper'])->name('wallpaper.store');
    Route::get('/wallpapers', [WebosController::class, 'wallpapers'])->name('wallpaper.index');
    Route::delete('/wallpaper', [WebosController::class, 'destroyWallpaper'])->name('wallpaper.destroy');
    Route::get('/catalog', [WebosController::class, 'catalog'])->name('catalog');
    Route::get('/calendar', [WebosController::class, 'calendar'])->name('calendar');
});
