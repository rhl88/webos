<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('app_cmspro_webos_workspaces')) {
            return;
        }

        Schema::create('app_cmspro_webos_workspaces', function (Blueprint $table): void {
            $table->id()->comment('主键');
            $table->unsignedBigInteger('admin_user_id')->unique()->comment('后台管理员ID');
            $table->json('desktop_items')->nullable()->comment('桌面快捷方式与位置');
            $table->json('preferences')->nullable()->comment('WebOS个人偏好');
            $table->unsignedTinyInteger('status')->default(1)->comment('状态：0禁用，1启用');
            $table->timestamp('create_time')->nullable()->comment('创建时间');
            $table->timestamp('update_time')->nullable()->comment('更新时间');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `app_cmspro_webos_workspaces` COMMENT = 'WebOS管理员工作区配置表'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_cmspro_webos_workspaces');
    }
};
