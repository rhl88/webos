<?php

namespace App\Apps\CmsproWebos\Tests;

use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            // varchar(20)：2.1.98 起新增 admin_preset（12 字符）取值，2026_10_24 迁移已同步扩容生产列
            $table->string('terminal', 20)->default('admin');
            $table->unsignedBigInteger('admin_user_id');
            $table->unique(['terminal', 'admin_user_id']);
            $table->text('desktop_items')->nullable();
            $table->text('taskbar_items')->nullable();
            // 已推送的后台预设桌面项 ID 列表（差异化同步台账，2.1.89，与生产迁移一致）
            $table->text('preset_sync_ids')->nullable();
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

    /**
     * 超级管理员登录态（2.1.104）：「覆盖传统后台」双开关与「管理桌面/用户桌面」
     * 预设为全局设置、仅超管可用，相关接口测试统一以超管身份操作。
     * 角色幂等复用（firstOrCreate，code 唯一索引），sort 取大值排在业务角色之后，
     * 不干扰「管理桌面」角色列表的顺序与默认角色断言。
     */
    protected function actingAsSuperAdmin(): AdminUser
    {
        $admin = $this->actingAdmin();

        $superRole = AdminRole::firstOrCreate(
            ['code' => 'super_admin'],
            ['name' => '超级管理员', 'status' => 1, 'sort' => 999]
        );
        DB::table('admin_user_roles')->insert(['user_id' => $admin->id, 'role_id' => $superRole->id]);

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
