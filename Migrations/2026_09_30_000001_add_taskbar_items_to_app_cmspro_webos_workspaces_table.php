<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('app_cmspro_webos_workspaces')) {
            return;
        }

        if (! Schema::hasColumn('app_cmspro_webos_workspaces', 'taskbar_items')) {
            Schema::table('app_cmspro_webos_workspaces', function (Blueprint $table): void {
                $table->json('taskbar_items')->nullable()->comment('任务栏固定项（与桌面快捷方式相互独立）')->after('desktop_items');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('app_cmspro_webos_workspaces')
            && Schema::hasColumn('app_cmspro_webos_workspaces', 'taskbar_items')) {
            Schema::table('app_cmspro_webos_workspaces', function (Blueprint $table): void {
                $table->dropColumn('taskbar_items');
            });
        }
    }
};
