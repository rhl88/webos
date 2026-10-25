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
            || ! Schema::hasColumn('app_cmspro_webos_workspaces', 'terminal')) {
            return;
        }

        Schema::table('app_cmspro_webos_workspaces', function (Blueprint $table): void {
            // 扩容：varchar(10) → varchar(20)。2.1.98 管理桌面角色预设引入
            // terminal='admin_preset'（12 字符），超出 2.1.80 的 varchar(10) 定义，
            // MySQL 严格模式下保存预设报 1406 Data too long。
            // 取值域：admin/user/preset/admin_preset，20 留有余量。
            // change() 会整体重建列定义，须显式带上默认值与注释。
            $table->string('terminal', 20)->default('admin')
                ->comment('所属终端：admin后台，user前台用户，preset后台预设，admin_preset后台角色预设')
                ->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('app_cmspro_webos_workspaces')
            || ! Schema::hasColumn('app_cmspro_webos_workspaces', 'terminal')) {
            return;
        }

        // 回滚即放弃管理桌面角色预设功能：先删除超长取值行，
        // 否则收缩回 varchar(10) 会因存量 admin_preset（12 字符）超长而失败
        DB::table('app_cmspro_webos_workspaces')->where('terminal', 'admin_preset')->delete();

        Schema::table('app_cmspro_webos_workspaces', function (Blueprint $table): void {
            $table->string('terminal', 10)->default('admin')
                ->comment('所属终端：admin后台，user前台用户')
                ->change();
        });
    }
};
