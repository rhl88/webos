-- cmspro.webos 1.0.0 回滚 SQL
-- 警告：执行后将删除所有管理员的 WebOS 桌面布局与偏好，请先备份。

DROP TABLE IF EXISTS `app_cmspro_webos_workspaces`;
