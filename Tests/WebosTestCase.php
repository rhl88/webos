<?php

namespace App\Apps\CmsproWebos\Tests;

use App\Models\AdminUser;
use App\Models\User;
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
            // terminal + admin_user_id 联合唯一：admin/user 双端各自隔离（2.1.80，与生产迁移一致）
            $table->string('terminal', 10)->default('admin');
            $table->unsignedBigInteger('admin_user_id');
            $table->unique(['terminal', 'admin_user_id']);
            $table->text('desktop_items')->nullable();
            $table->text('taskbar_items')->nullable();
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

    /** 前台用户登录态（用户端 WebOS 桌面 2.1.80） */
    protected function actingUser(): User
    {
        $user = User::create([
            'username' => 'webos_u_' . uniqid(),
            'password' => bcrypt('123456'),
            'nickname' => 'WebOS 测试用户',
            'status' => 1,
        ]);

        $this->actingAs($user, 'web');

        return $user;
    }
}
