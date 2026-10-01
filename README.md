# WebOS 管理桌面 应用文档

> 应用ID：`cmspro.webos` | 文档版本：1.0.0 | 应用版本：1.9.39 | 依赖：PHP ≥ 8.3, CmsPro ≥ 5.0.0

- 官方地址：https://www.cmspro.cn/apps/cmspro.webos
- 本应用是 CmsPro v5 的桌面式后台入口应用，基于 CMSPRO 运行，请先安装 [CMSPRO](https://www.cmspro.cn/help/2)

## 1. 应用概述

`cmspro.webos` 复用系统现有菜单、应用市场、通知与账号接口，将后台功能组织为桌面快捷方式、开始菜单、多窗口和任务栏：

- 仅从当前管理员有权访问的 `admin` 后台菜单生成开始菜单；应用按 `app_id` 聚合，系统内置菜单按文件夹聚合，不混入 `user` 和 `home` 菜单。
- “常用”根据当前管理员的打开次数和最近打开时间显示最多 6 个入口。
- 将菜单入口添加到桌面、拖动排序并按管理员持久化；支持工作区重置。
- 每个应用使用独立窗口，窗口标题显示当前应用图标，系统应用保留 CMSPRO Logo；左侧只展示该应用的菜单，菜单可收起，只有一个菜单时默认隐藏。
- 桌面快捷方式、任务栏和开始菜单统一使用应用根目录 `icon.svg`、`icon.png` 和清单 `icon` 的真实图标。
- 任务栏采用紧凑尺寸；OS 设置可按管理员调整上、下、左、右停靠位置、壁纸、时钟、界面动效和窗口默认宽高（按可用桌面区域百分比）。
- 任务栏时间随方位自适应分行，点击时间弹出可翻月与选择日期的日历面板。
- 应用市场读取远程市场图标，并与本机版本比对显示安装、已安装或更新状态。
- 已安装应用以列表展示应用信息、状态和操作，支持状态筛选、启用/禁用、打开、手动升级、导出和上传安装；更多菜单提供管理入口、备份、文档、设置和卸载。
- 在开始菜单把应用固定到桌面时，桌面入口使用应用名称。
- 桌面图标支持右键操作：打开应用、删除图标、卸载应用（应用需先禁用，系统菜单入口不提供卸载）。
- 安装应用时自动识别清单中的 `admin`、`user`、`home` 菜单，只显示实际存在的终端并分别选择顶级菜单挂载位置。
- 开始菜单底部集成锁定、退出登录和 OS 设置，并保留后台通知与账号入口；账号菜单提供个人设置（后台个人中心页）、修改密码弹窗、锁定桌面与退出登录，任务栏位置统一在 OS 设置中调整。
- 传统后台右下角注入「进入 WebOS」悬浮入口（`entry.js`/`entry.css`），支持在 OS 设置开启「覆盖传统后台首页」后直达桌面，带 `skip_webos=1` 可临时绕过。
- 通知中心待办通过系统 hook `admin.notifications.todos` 聚合接入，完整列表（含已读）由 `all-todos` 接口提供。

## 2. 界面预览

| 桌面与日历 | 应用市场 |
| --- | --- |
| ![桌面与日历](screenshots/10.png) | ![应用市场](screenshots/1.png) |

| 已安装应用 | 未安装应用 |
| --- | --- |
| ![已安装应用](screenshots/5.png) | ![未安装应用](screenshots/6.png) |

| OS 设置 | 安装记录 |
| --- | --- |
| ![OS 设置](screenshots/2.png) | ![安装记录](screenshots/7.png) |

| 入口管理 | 多窗口应用 |
| --- | --- |
| ![入口管理](screenshots/8.png) | ![多窗口应用](screenshots/4.png) |

| 桌面图标右键菜单 | 账号菜单 |
| --- | --- |
| ![桌面图标右键菜单](screenshots/3.png) | ![账号菜单](screenshots/9.png) |

## 3. 目录结构

```
CmsproWebos/
├── Assets/
│   ├── css/
│   │   ├── webos.css               # 桌面主样式
│   │   └── entry.css               # 传统后台悬浮入口样式
│   ├── js/
│   │   ├── webos.js                # 桌面主脚本
│   │   └── entry.js                # 传统后台悬浮入口脚本
│   └── images/
│       └── webos-wallpaper.png     # 默认壁纸
├── Controllers/Admin/
│   └── WebosController.php         # 桌面页面与工作区/壁纸/日历/待办接口
├── Database/
│   ├── upgrade.sql                 # 升级 SQL
│   └── rollback.sql                # 回滚 SQL
├── Migrations/                     # 应用表迁移（app_cmspro_webos_ 前缀）
├── Models/
│   └── WebosWorkspace.php          # 管理员工作区模型（布局/任务栏/偏好持久化）
├── Routes/
│   └── admin.php                   # 后台页面与 API 路由
├── Services/
│   ├── WorkspaceService.php        # 工作区业务逻辑
│   ├── WallpaperService.php        # 壁纸上传与管理
│   └── CalendarService.php         # 日历面板数据
├── Tests/                          # 应用功能测试（Unit / Feature）
├── Views/Admin/desktop/
│   └── index.blade.php             # 桌面视图
├── screenshots/                    # 界面截图
├── Install.php                     # 安装/卸载/升级逻辑
├── ServiceProvider.php             # 服务提供者（路由/视图注册与后台悬浮入口注入）
├── manifest.json                   # 应用元数据唯一声明源
├── icon.png                        # 应用图标
└── doc/                            # 应用文档
```

## 4. 应用文档

| 文档 | 说明 |
|------|------|
| [CmsPro-WebOS管理桌面-特性清单.md](doc/CmsPro-WebOS管理桌面-特性清单.md) | 功能特性与对应实现位置 |
| [CmsPro-WebOS管理桌面-使用指南.md](doc/CmsPro-WebOS管理桌面-使用指南.md) | 安装、配置与功能验证指南 |
| [CmsPro-WebOS管理桌面-扩展指南.md](doc/CmsPro-WebOS管理桌面-扩展指南.md) | 服务调用与扩展接口说明 |

## 5. 安装与验证

在后台应用管理中找到“WebOS 管理桌面”，执行本地安装并启用。应用菜单默认以新窗口方式打开 `/admin/cmspro/webos`。

```bash
php artisan test app/Apps/CmsproWebos/Tests
```
