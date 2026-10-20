<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('app_cmspro_webos_workspaces')
            || Schema::hasColumn('app_cmspro_webos_workspaces', 'terminal')) {
            return;
        }

        Schema::table('app_cmspro_webos_workspaces', function (Blueprint $table): void {
            // 所属终端：admin=后台管理员桌面，user=前台用户桌面；存量行随默认值自动回填为 admin
            $table->string('terminal', 10)->default('admin')->comment('所属终端：admin后台，user前台用户')->after('admin_user_id');

            // 唯一约束从 admin_user_id 单列升级为 (terminal, admin_user_id) 联合：
            // 同一账号在后台与用户端各自持有独立工作区，互不串数据
            $table->dropUnique(['admin_user_id']);
            $table->unique(['terminal', 'admin_user_id']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('app_cmspro_webos_workspaces')
            || ! Schema::hasColumn('app_cmspro_webos_workspaces', 'terminal')) {
            return;
        }

        Schema::table('app_cmspro_webos_workspaces', function (Blueprint $table): void {
            $table->dropUnique(['terminal', 'admin_user_id']);
        });

        // 回滚即放弃用户端功能：先清理 user 终端工作区，避免两端同 ID 记录与
        // 恢复后的 admin_user_id 单列唯一约束冲突
        DB::table('app_cmspro_webos_workspaces')->where('terminal', 'user')->delete();

        Schema::table('app_cmspro_webos_workspaces', function (Blueprint $table): void {
            $table->unique('admin_user_id');
            $table->dropColumn('terminal');
        });
    }
};
