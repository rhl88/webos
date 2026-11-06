# WebOS 管理桌面 应用文档

> 应用ID：`cmspro.webos` | 文档版本：1.1.0 | 应用版本：2.1.109 | 依赖：PHP ≥ 8.3, CmsPro ≥ 5.1.8

- 官方地址：https://www.cmspro.cn/apps/cmspro.webos
- 本应用是 CmsPro v5 的桌面式后台入口应用，基于 CMSPRO 运行，请先安装 [CMSPRO](https://www.cmspro.cn/help/2)

## 1. 应用概述

`cmspro.webos` 不替换系统后台框架，而是复用系统现有菜单、应用市场、通知与账号能力，把后台功能组织为桌面快捷方式、开始菜单、多窗口与任务栏，同时提供前台用户端的同款桌面：

- **桌面与快捷方式**：菜单入口可添加到桌面、拖动排序并按账号持久化；支持新建文件夹（图标可拖入收纳、浮层展开）、桌面入口与 URL 链接，右键提供打开、重命名、删除图标、卸载应用、图标设置，图标支持大/中/小三档
- **开始菜单与启动台**：仅展示当前管理员有权访问的 `admin` 菜单，按 `app_id` 聚合应用、系统菜单按文件夹聚合；「常用」按打开次数与最近时间排序（最多 6 个），并提供「全部功能」macOS Launchpad 风格全屏启动台（搜索 + 层级导航 + 面包屑）
- **多窗口与任务栏**：每个应用独立窗口，标题栏显示应用真实图标，左侧按声明层级树形展示菜单并支持收起、分组手风琴；支持四种菜单打开方式（嵌套网页 / 路由模式 / 新建窗口 / 弹窗网页），可选应用窗口多选项卡（同路径复用、溢出导航、右键关闭）；任务栏可停靠上/下/左/右，固定与运行图标支持右键菜单
- **应用中心**：市场首页聚合「不可错过的应用 / 官方精选 / 推荐位」与分类浏览，读取远程市场图标并按本机版本显示安装/已安装/更新；支持付费应用购买（二维码 + 轮询）、应用详情、已购应用、更新检查与忽略、安装记录、已安装/未安装管理与上传安装，安装时自动识别 `admin`/`user`/`home` 菜单并选择各终端挂载位置
- **账号与通知**：开始菜单底部集成锁定、退出登录与 OS 设置，账号菜单提供个人设置、修改密码、锁定与退出；通知中心聚合系统通知与待办，支持全部已读与全部待办列表
- **用户端桌面**：manifest 声明 `user_menus` 在 `/user` 挂「WebOS 桌面」，与后台共用桌面视图、按终端分流数据接口；工作区按 `terminal + 账号` 联合唯一隔离，用户端裁剪应用中心与超管能力
- **桌面预设**：OS 设置提供「用户桌面」与「管理桌面」Tab，可按用户、按后台角色预设初始桌面图标，支持搜索用户/管理员进入其桌面查看与代改；新用户与管理员进入时按差异化增量同步（缺失补齐、等价不重复、已推送不再下发）
- **内置工具**：内置浏览器（多标签、地址栏导航、跨域降级）、日历（农历与节气、回到今天）、官网动态窗口、传统后台右下角「进入 WebOS」悬浮入口
- **桌面小工具**：桌面可摆放各应用声明的 1x1/2x1/2x2 数据卡片，支持多实例参数、卡片尺寸操作与文本配色渲染
- **直达启动**：`/admin/cmspro/webos?app=应用标识&to=菜单路径`（用户端同口径）可在桌面就绪后自动打开指定应用窗口与菜单，登录后回跳仍保留参数
- **权限与配置**：`cmspro.webos.access`、`cmspro.webos.workspace.manage` 两项权限；OS 设置支持任务栏方位、时钟格式、界面动效、窗口默认宽高、壁纸（含自定义壁纸库）与「覆盖传统后台」等选项

## 2. 应用截图

> 截图来源：[官方应用页 · cmspro.webos](https://www.cmspro.cn/apps/cmspro.webos)（官网截图版本 v1.9.39，应用页当前版本 v2.1.104）。每次发布与更新必须核对本节截图与官网「应用截图」是否一致，不一致时以官网为准同步更新。

![应用截图 1](https://www.cmspro.cn/uploads/appstore/2026/09/30/XicQ4MitU3F0HUDEZmFQk9oNrKYS4nDzefp4ZJGJ.png)

![应用截图 2](https://www.cmspro.cn/uploads/appstore/2026/09/30/XD3h1UObSObW0ehpPKHEA6E5WjoiGnzpAFNHxZkW.png)

![应用截图 3](https://www.cmspro.cn/uploads/appstore/2026/09/30/RrGJ7naKnPYaF40RvCcUGhizrzlIWoDldQ2IxYWr.png)

![应用截图 4](https://www.cmspro.cn/uploads/appstore/2026/09/30/WZxs6tSiTJeDkSrrrm2dQWHuuXZNnhTwABbMkUUW.png)

![应用截图 5](https://www.cmspro.cn/uploads/2026/10/01/an2cOEFh9bu1AoTDFcjn6xwg8YbrHNsISJ2dzqaU.png)

![应用截图 6](https://www.cmspro.cn/uploads/appstore/2026/09/30/FLxXrETVPlJOZu9nay1uk4HHT3vbE3oK3vrHJ9sE.png)

![应用截图 7](https://www.cmspro.cn/uploads/appstore/2026/09/30/zYCmYyMYubKSoXXjktrlqwknYpUEOlE4sXWlQDfk.png)

![应用截图 8](https://www.cmspro.cn/uploads/appstore/2026/09/30/fCx7atnj63qEDiFAUNWymDVy48dDgReQLJVTx729.png)

![应用截图 9](https://www.cmspro.cn/uploads/appstore/2026/09/30/OXhW1zEpwCW3RIWzfMiyQNmq3vrogmzAU7TEZenp.png)

![应用截图 10](https://www.cmspro.cn/uploads/appstore/2026/09/30/UacNLqFMOlBnDYC8BtzWgjGfBlB1cpPDxUfcZqUh.png)

![应用截图 11](https://www.cmspro.cn/uploads/appstore/2026/09/30/eHw9T0Q3Xl7AUVXh7d9hFe5N9vY61M12DPkIIxiT.png)

## 3. 目录结构

```
CmsproWebos/
├── Assets/
│   ├── css/
│   │   ├── webos.css               # 桌面主样式
│   │   ├── entry.css               # 传统后台悬浮入口样式
│   │   └── login.css               # WebOS 登录窗口样式
│   ├── js/
│   │   ├── webos.js                # 桌面主脚本
│   │   ├── entry.js                # 传统后台悬浮入口脚本
│   │   └── webos-icon-catalog.js   # 图标目录（图标设置弹窗数据）
│   └── images/
│       └── webos-wallpaper.png     # 默认壁纸
├── Controllers/Admin/
│   └── WebosController.php         # 桌面页面、登录窗口与工作区/壁纸/日历/待办/应用市场接口
├── Database/
│   ├── upgrade.sql                 # 升级 SQL
│   └── rollback.sql                # 回滚 SQL
├── Http/Middleware/
│   ├── EnsureSuperAdmin.php        # 超级管理员专属能力校验
│   ├── RedirectAdminLoginToWebos.php  # 覆盖传统后台后登录页跳转 WebOS
│   └── RedirectUserHomeToWebos.php    # 用户首页直达 WebOS
├── Migrations/                     # 应用表迁移（app_cmspro_webos_ 前缀）
├── Models/
│   └── WebosWorkspace.php          # 账号工作区模型（布局/任务栏/偏好持久化）
├── Routes/
│   ├── admin.php                   # 后台页面与 API 路由
│   └── user.php                    # 用户端页面与 API 路由
├── Services/
│   ├── WorkspaceService.php        # 工作区业务逻辑
│   ├── WallpaperService.php        # 壁纸上传与管理
│   └── CalendarService.php         # 日历面板数据（农历/节气）
├── Tests/                          # 应用功能测试（Unit / Feature / User）
├── Views/Admin/desktop/
│   ├── index.blade.php             # 桌面视图（后台与用户端共用）
│   └── login.blade.php             # WebOS 登录窗口
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
| [CmsPro-WebOS管理桌面-特性清单.md](doc/CmsPro-WebOS管理桌面-特性清单.md) | 功能特性、交互规则与对应实现位置 |
| [CmsPro-WebOS管理桌面-使用指南.md](doc/CmsPro-WebOS管理桌面-使用指南.md) | 安装、配置与功能验证指南 |
| [CmsPro-WebOS管理桌面-扩展指南.md](doc/CmsPro-WebOS管理桌面-扩展指南.md) | 服务调用与扩展接口说明 |

## 5. 安装与验证

在后台应用管理中找到「WebOS 管理桌面」，执行本地安装并启用。应用菜单默认以新窗口方式打开 `/admin/cmspro/webos`，用户端入口为 `/user/cmspro/webos`。

```bash
# 在 code 目录执行（应用级测试单独运行，不纳入框架回归）
php artisan test app/Apps/CmsproWebos/Tests

# 结果：253 passed (2498 assertions)
```