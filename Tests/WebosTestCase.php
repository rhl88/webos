<?php

namespace App\Apps\CmsproWebos\Tests;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

abstract class WebosTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createWebosTables();
        $this->registerWebosProvider();
    }

    protected function createWebosTables(): void
    {
        if (Schema::hasTable('app_cmspro_webos_workspaces')) {
            return;
        }

        Schema::create('app_cmspro_webos_workspaces', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('admin_user_id')->unique();
            $table->text('desktop_items')->nullable();
            $table->text('preferences')->nullable();
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamp('create_time')->nullable();
            $table->timestamp('update_time')->nullable();
        });
    }

    protected function registerWebosProvider(): void
    {
        $provider = $this->app->register(\App\Apps\CmsproWebos\ServiceProvider::class);

        if ($provider && method_exists($provider, 'boot')) {
            $this->app->call([$provider, 'boot']);
        }
    }

    protected function actingAdmin(): AdminUser
    {
        $admin = AdminUser::create([
            'username' => 'webos_' . uniqid(),
            'password' => bcrypt('123456'),
            'name' => 'WebOS 测试管理员',
            'status' => 1,
        ]);

        $this->actingAs($admin, 'admin');

        return $admin;
    }
}
