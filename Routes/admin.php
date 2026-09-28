<?php

use App\Apps\CmsproWebos\Controllers\Admin\WebosController;
use Illuminate\Support\Facades\Route;

Route::get('/', [WebosController::class, 'index'])->name('index');

Route::prefix('api')->name('api.')->group(function () {
    Route::get('/workspace', [WebosController::class, 'workspace'])->name('workspace.show');
    Route::put('/workspace', [WebosController::class, 'saveWorkspace'])->name('workspace.update');
    Route::get('/catalog', [WebosController::class, 'catalog'])->name('catalog');
});
