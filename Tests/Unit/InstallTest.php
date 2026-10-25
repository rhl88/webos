<?php

namespace App\Apps\CmsproWebos\Tests\Unit;

use App\Apps\CmsproWebos\Install;
use App\Apps\CmsproWebos\Tests\WebosTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Mockery\MockInterface;
use ReflectionMethod;

/**
 * Install::ensureTerminalColumnLength() 单元测试
 *
 * 背景：早期 CMSPRO 系统侧 AppInstallerService::isMigrationAlreadyApplied()
 * 会把改列迁移（->change()）误判为已应用并登记 migrations（batch=0），
 * 系统侧 Artisan 与应用侧 runMigrations() 均因此跳过扩容迁移，terminal 列
 * 停留在 varchar(10)，保存 admin_preset（12 字符）报 1406（线上实际故障）。
 * ensureTerminalColumnLength() 绕开迁移记录、直接核对实际列定义自愈扩容，
 * 使应用在未修复系统侧缺陷的 CMSPRO 站点上升级后也能恢复。
 *
 * MySQL 分支无法在 SQLite 测试库真实执行（无 information_schema、不支持
 * MODIFY COLUMN），故通过容器 swap 注入 mock 覆盖；DB facade 的
 * selectOne/statement 实际落在 DatabaseManager（'db'）上调用，期望定义在
 * db mock；仅 DB::connection()->getDriverName() 触及 connection mock。
 */
class InstallTest extends WebosTestCase
{
    public function test_is_safe_on_sqlite_connection(): void
    {
        // 真实 SQLite 测试连接：驱动守卫直接返回，不得触碰 information_schema
        $this->expectNotToPerformAssertions();

        $this->invokeEnsure();
    }

    public function test_skips_when_table_missing(): void
    {
        $db = $this->swapMysqlConnection();
        $db->shouldReceive('selectOne')->never();
        $db->shouldReceive('statement')->never();

        $this->swapSchemaBuilder()->shouldReceive('hasTable')
            ->once()->with('app_cmspro_webos_workspaces')->andReturn(false);

        $this->invokeEnsure();
    }

    public function test_extends_column_when_length_below_20(): void
    {
        $db = $this->swapMysqlConnection();
        $db->shouldReceive('selectOne')->once()->andReturn((object) ['length' => 10]);
        $db->shouldReceive('statement')->once()->with(
            Mockery::pattern('/MODIFY COLUMN `terminal` VARCHAR\(20\) NOT NULL DEFAULT \'admin\'/')
        );

        $this->swapSchemaBuilder()->shouldReceive('hasTable')->once()->andReturn(true);

        $this->invokeEnsure();
    }

    public function test_skips_when_length_reaches_20(): void
    {
        $db = $this->swapMysqlConnection();
        $db->shouldReceive('selectOne')->once()->andReturn((object) ['length' => 20]);
        $db->shouldReceive('statement')->never();

        $this->swapSchemaBuilder()->shouldReceive('hasTable')->once()->andReturn(true);

        $this->invokeEnsure();
    }

    public function test_skips_when_column_length_not_found(): void
    {
        $db = $this->swapMysqlConnection();
        $db->shouldReceive('selectOne')->once()->andReturn(null);
        $db->shouldReceive('statement')->never();

        $this->swapSchemaBuilder()->shouldReceive('hasTable')->once()->andReturn(true);

        $this->invokeEnsure();
    }

    /**
     * swap 为 MySQL 环境：'db' 以 instance 覆盖并清除 Facade 静态缓存
     * （resolvedInstance 有缓存，不清除则 DB::connection() 仍走真实连接）
     *
     * @return MockInterface db mock，DB 的 selectOne/statement 期望定义于此
     */
    protected function swapMysqlConnection(): MockInterface
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('getDriverName')->andReturn('mysql');

        $db = Mockery::mock();
        $db->shouldReceive('connection')->andReturn($connection);
        $this->app->instance('db', $db);
        DB::clearResolvedInstance('db');

        return $db;
    }

    /** @return MockInterface schema builder mock（hasTable 期望定义于此） */
    protected function swapSchemaBuilder(): MockInterface
    {
        $schema = Mockery::mock();
        $this->app->instance('db.schema', $schema);
        Schema::clearResolvedInstance('db.schema');

        return $schema;
    }

    /** 反射调用 protected 自愈方法 */
    protected function invokeEnsure(): void
    {
        $method = new ReflectionMethod(Install::class, 'ensureTerminalColumnLength');
        $method->setAccessible(true);
        $method->invoke(new Install());
    }
}
