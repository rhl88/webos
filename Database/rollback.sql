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

