<?php

namespace App\Apps\CmsproWebos;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class Install
{
    public function install(): void
    {
        $this->runMigrations();
        $this->ensureTerminalColumnLength();
    }

    public function uninstall(): void
    {
        $this->rollbackMigrations();
    }

    public function upgrade(string $fromVersion, string $toVersion): void
    {
        $this->runMigrations();
        $this->ensureTerminalColumnLength();
    }

    protected function runMigrations(): void
    {
        foreach ($this->migrationFiles() as $file) {
            $migrationName = pathinfo($file, PATHINFO_FILENAME);
            if (DB::table('migrations')->where('migration', $migrationName)->exists()) {
                continue;
            }

            try {
                $migration = require $file;
                $migration->up();
                DB::table('migrations')->insert([
                    'migration' => $migrationName,
                    'batch' => ((int) DB::table('migrations')->max('batch')) + 1,
                ]);
            } catch (\Throwable $exception) {
                Log::error('WebOS应用迁移执行失败', [
                    'app' => 'cmspro.webos',
                    'file' => basename($file),
                    'error' => $exception->getMessage(),
                ]);

                throw $exception;
            }
        }
    }

    protected function rollbackMigrations(): void
    {
        foreach (array_reverse($this->migrationFiles()) as $file) {
            $migrationName = pathinfo($file, PATHINFO_FILENAME);
            if (! DB::table('migrations')->where('migration', $migrationName)->exists()) {
                continue;
            }

            try {
                $migration = require $file;
                $migration->down();
                DB::table('migrations')->where('migration', $migrationName)->delete();
            } catch (\Throwable $exception) {
                Log::error('WebOS应用迁移回滚失败', [
                    'app' => 'cmspro.webos',
                    'file' => basename($file),
                    'error' => $exception->getMessage(),
                ]);

                throw $exception;
            }
        }
    }

    /**
     * terminal 列长度自愈（varchar(10) → varchar(20)）：不依赖迁移记录的幂等扩容
     *
     * 背景：早期 CMSPRO 系统侧 AppInstallerService::isMigrationAlreadyApplied()
     * 会把改列迁移（->change()）误判为已应用并登记 migrations（batch=0），
     * 导致系统侧 Artisan 与本类 runMigrations() 均跳过扩容迁移，列停留在
     * varchar(10)，保存 terminal='admin_preset'（12 字符）时报 1406
     * Data too long。本方法绕开迁移记录、直接核对实际列定义并按需扩容，
     * 使应用在未修复系统侧缺陷的 CMSPRO 站点上升级（install/upgrade）后
     * 也能完成自愈；列已达标时零改动，幂等可安全重复执行。
     */
    protected function ensureTerminalColumnLength(): void
    {
        // SQLite 等环境不支持 MODIFY COLUMN，且不存在长度超限问题，直接跳过
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        if (! Schema::hasTable('app_cmspro_webos_workspaces')) {
            return;
        }

        $length = DB::selectOne(
            'SELECT CHARACTER_MAXIMUM_LENGTH AS `length`'
            . ' FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['app_cmspro_webos_workspaces', 'terminal']
        )?->length;

        // 列不存在或长度已达标（20）均无需处理
        if ($length === null || (int) $length >= 20) {
            return;
        }

        // MODIFY 会整体重建列定义，须显式带上 NOT NULL、默认值与注释；
        // 不带 AFTER/FIRST，列保持原位置，(terminal, admin_user_id) 联合唯一索引不受影响
        DB::statement(
            'ALTER TABLE `app_cmspro_webos_workspaces`'
            . " MODIFY COLUMN `terminal` VARCHAR(20) NOT NULL DEFAULT 'admin'"
            . " COMMENT '所属终端：admin后台，user前台用户，preset后台预设，admin_preset后台角色预设'"
        );
    }

    /** @return array<int,string> */
    protected function migrationFiles(): array
    {
        $files = glob(__DIR__ . '/Migrations/*.php') ?: [];
        sort($files);

        return $files;
    }
}
