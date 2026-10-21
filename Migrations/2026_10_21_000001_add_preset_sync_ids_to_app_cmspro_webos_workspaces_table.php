<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('app_cmspro_webos_workspaces')
            || Schema::hasColumn('app_cmspro_webos_workspaces', 'preset_sync_ids')) {
            return;
        }

        Schema::table('app_cmspro_webos_workspaces', function (Blueprint $table): void {
            // 已推送的后台预设桌面项 ID 列表（per 用户）：用于差异化增量同步，
            // 已推送过的预设项不再重复下发（用户删除或修改后也不回推）
            $table->json('preset_sync_ids')->nullable()->comment('已推送的后台预设桌面项ID列表（已推送不再重复下发）')->after('taskbar_items');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('app_cmspro_webos_workspaces')
            || ! Schema::hasColumn('app_cmspro_webos_workspaces', 'preset_sync_ids')) {
            return;
        }

        Schema::table('app_cmspro_webos_workspaces', function (Blueprint $table): void {
            $table->dropColumn('preset_sync_ids');
        });
    }
};