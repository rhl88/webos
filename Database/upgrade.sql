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
