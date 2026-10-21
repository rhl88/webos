-- cmspro.webos 1.0.0 升级 SQL
-- 执行前请备份数据库；Laravel 安装流程会自动执行对应迁移，本脚本用于人工核验或应急升级。

CREATE TABLE IF NOT EXISTS `app_cmspro_webos_workspaces` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
    `admin_user_id` BIGINT UNSIGNED NOT NULL COMMENT '后台管理员ID',
    `desktop_items` JSON NULL COMMENT '桌面快捷方式与位置',
    `preferences` JSON NULL COMMENT 'WebOS个人偏好',
    `status` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态：0禁用，1启用',
    `create_time` TIMESTAMP NULL DEFAULT NULL COMMENT '创建时间',
    `update_time` TIMESTAMP NULL DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `app_cmspro_webos_workspaces_admin_user_id_unique` (`admin_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='WebOS管理员工作区配置表';

-- ============================================================================
-- 2.1.80 工作区双端隔离：新增 terminal 字段，唯一约束升级为 (terminal, admin_user_id)
-- 影响表: app_cmspro_webos_workspaces
-- 变更类型: DDL (新增字段 + 索引调整)
-- 创建日期: 2026-10-20
-- 风险等级: 低
-- 说明: Laravel 应用安装/升级流程会自动执行对应迁移（Install::upgrade），
--       本段脚本用于人工核验或应急升级，可重复执行（幂等）。
-- ============================================================================

-- ---------- 前置检查 + 正向变更：新增 terminal 字段（已存在则跳过） ----------
SET @add_terminal_sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE `app_cmspro_webos_workspaces` ADD COLUMN `terminal` VARCHAR(10) NOT NULL DEFAULT ''admin'' COMMENT ''所属终端：admin后台，user前台用户'' AFTER `admin_user_id`',
        'SELECT ''terminal 字段已存在，跳过'' AS notice'
    )
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'app_cmspro_webos_workspaces'
      AND column_name = 'terminal'
);
PREPARE stmt FROM @add_terminal_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------- 存量回填：历史工作区归属后台终端 ----------
UPDATE `app_cmspro_webos_workspaces` SET `terminal` = 'admin' WHERE `terminal` IS NULL OR `terminal` = '';

-- ---------- 唯一约束切换：admin_user_id 单列 → (terminal, admin_user_id) 联合 ----------
-- 旧单列唯一索引存在且新联合索引不存在时才执行（幂等）
SET @old_index_exists := (
    SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'app_cmspro_webos_workspaces'
      AND index_name = 'app_cmspro_webos_workspaces_admin_user_id_unique'
);
SET @new_index_exists := (
    SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'app_cmspro_webos_workspaces'
      AND index_name = 'app_cmspro_webos_workspaces_terminal_admin_user_id_unique'
);
SET @switch_index_sql := IF(
    @new_index_exists > 0,
    'SELECT ''联合唯一索引已存在，跳过'' AS notice',
    CONCAT(
        IF(
            @old_index_exists > 0,
            'ALTER TABLE `app_cmspro_webos_workspaces` DROP INDEX `app_cmspro_webos_workspaces_admin_user_id_unique`, ',
            'ALTER TABLE `app_cmspro_webos_workspaces` '
        ),
        'ADD UNIQUE KEY `app_cmspro_webos_workspaces_terminal_admin_user_id_unique` (`terminal`, `admin_user_id`)'
    )
);
PREPARE stmt FROM @switch_index_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------- 数据验证 ----------
SELECT column_name, column_type, is_nullable, column_default
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'app_cmspro_webos_workspaces' AND column_name = 'terminal';

SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns
FROM information_schema.statistics
WHERE table_schema = DATABASE() AND table_name = 'app_cmspro_webos_workspaces'
  AND index_name LIKE '%unique%'
GROUP BY index_name;

-- ---------- 回滚方案 ----------
-- 见同目录 rollback.sql「2.1.80 回滚」段

-- ============================================================================
-- 2.1.89 用户桌面预设差异化同步：新增 preset_sync_ids 字段
-- 影响表: app_cmspro_webos_workspaces
-- 变更类型: DDL (新增字段)
-- 创建日期: 2026-10-21
-- 风险等级: 低
-- 说明: 记录每个用户已推送过的后台预设桌面项 ID（同一行内 per 用户），
--       用于差异化增量同步——已推送过的预设项不再重复下发（用户删除或修改后也不回推）。
--       Laravel 应用升级流程会自动执行对应迁移（Install::upgrade），
--       本段脚本用于人工核验或应急升级，可重复执行（幂等）。
-- ============================================================================

-- ---------- 前置检查 + 正向变更：新增 preset_sync_ids 字段（已存在则跳过） ----------
SET @add_preset_sync_ids_sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE `app_cmspro_webos_workspaces` ADD COLUMN `preset_sync_ids` JSON NULL COMMENT ''已推送的后台预设桌面项ID列表（已推送不再重复下发）''',
        'SELECT ''preset_sync_ids 字段已存在，跳过'' AS notice'
    )
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'app_cmspro_webos_workspaces'
      AND column_name = 'preset_sync_ids'
);
PREPARE stmt FROM @add_preset_sync_ids_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------- 数据验证 ----------
SELECT column_name, column_type, is_nullable, column_default
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'app_cmspro_webos_workspaces' AND column_name = 'preset_sync_ids';

-- ---------- 回滚方案 ----------
-- 见同目录 rollback.sql「2.1.89 回滚」段

