<?php

namespace App\Apps\CmsproWebos;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Install
{
    public function install(): void
    {
        $this->runMigrations();
    }

    public function uninstall(): void
    {
        $this->rollbackMigrations();
    }

    public function upgrade(string $fromVersion, string $toVersion): void
    {
        $this->runMigrations();
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

    /** @return array<int,string> */
    protected function migrationFiles(): array
    {
        $files = glob(__DIR__ . '/Migrations/*.php') ?: [];
        sort($files);

        return $files;
    }
}
