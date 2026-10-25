-- cmspro.webos 1.0.0 回滚 SQL
-- 警告：执行后将删除所有管理员的 WebOS 桌面布局与偏好，请先备份。

DROP TABLE IF EXISTS `app_cmspro_webos_workspaces`;

-- ============================================================================
-- 2.1.80 回滚：工作区双端隔离（terminal 字段 + 联合唯一索引）
-- 回滚即放弃用户端功能数据：先清理 user 终端工作区，再恢复旧结构。
-- 执行前请备份；确认 terminal 字段存在后逐步取消注释执行。
-- ============================================================================

-- 1) 删除 user 终端工作区（回滚即放弃用户端数据）
-- DELETE FROM `app_cmspro_webos_workspaces` WHERE `terminal` = 'user';

-- 2) 切换唯一索引：联合 → admin_user_id 单列
-- ALTER TABLE `app_cmspro_webos_workspaces`
--     DROP INDEX `app_cmspro_webos_workspaces_terminal_admin_user_id_unique`,
--     ADD UNIQUE KEY `app_cmspro_webos_workspaces_admin_user_id_unique` (`admin_user_id`);

-- 3) 删除 terminal 字段
-- ALTER TABLE `app_cmspro_webos_workspaces` DROP COLUMN `terminal`;

-- ============================================================================
-- 2.1.89 回滚：用户桌面预设差异化同步（preset_sync_ids 字段）
-- 回滚即放弃差异化同步台账：删列后无法判定「已推送」，
-- 前台将按预设重新补齐（老用户桌面可能出现重复图标，可人工清理）。
-- 执行前请备份；确认 preset_sync_ids 字段存在后取消注释执行。
-- ============================================================================

-- ALTER TABLE `app_cmspro_webos_workspaces` DROP COLUMN `preset_sync_ids`;

-- ============================================================================
-- 2.1.98 回滚：管理桌面角色预设（terminal 列扩容 VARCHAR(10) → VARCHAR(20)）
-- 回滚即放弃管理桌面角色预设数据：先删除 admin_preset 工作区行，
-- 再把 terminal 列收缩回 VARCHAR(10)（2.1.80 原始口径）。
-- 不收缩 admin_preset 行直接缩列会因存量值 12 字符超长而失败。
-- 执行前请备份；确认 terminal 列存在后逐步取消注释执行。
-- ============================================================================

-- 1) 删除管理桌面角色预设工作区（terminal='admin_preset' 行，收缩前提）
-- DELETE FROM `app_cmspro_webos_workspaces` WHERE `terminal` = 'admin_preset';

-- 2) terminal 列收缩回 VARCHAR(10)
-- ALTER TABLE `app_cmspro_webos_workspaces`
--     MODIFY COLUMN `terminal` VARCHAR(10) NOT NULL DEFAULT 'admin' COMMENT '所属终端：admin后台，user前台用户';

