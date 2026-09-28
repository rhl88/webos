<?php

namespace App\Apps\CmsproWebos;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function boot(): void
    {
        Route::prefix('admin/cmspro/webos')
            ->middleware(['web', 'auth:admin'])
            ->name('cmspro.webos.admin.')
            ->group(__DIR__ . '/Routes/admin.php');

        $this->loadViewsFrom(__DIR__ . '/Views', 'cmspro.webos');
    }
}
