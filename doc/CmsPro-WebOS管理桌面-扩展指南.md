# CmsPro WebOS 管理桌面 · 扩展指南

> 文档版本：1.0.0 | 更新日期：2026-09-28
> 适用应用：CmsproWebos v1.0.0+

## 一、概述

其他应用无需依赖 WebOS 专用接口即可进入桌面：只要在 `manifest.json` 中声明启用且对管理员可见的后台菜单，WebOS 会通过系统菜单服务自动发现该入口。

## 二、菜单接入

推荐清单：

```json
{
    "menus": [
        {
            "title": "示例应用",
            "icon": "fa fa-cube",
            "code": "admin_example",
            "children": [
                {
                    "title": "示例首页",
                    "icon": "fa fa-dashboard",
                    "path": "/admin/example",
                    "code": "admin_example_index",
                    "open_type": "_iframe",
                    "order": 1
                }
            ]
        }
    ]
}
```

接入要求：

- 叶子菜单必须提供以 `/` 开头的站内路径。
- 图标使用 Font Awesome 4.7 或系统 Layui 图标类名，不使用 emoji。
- 推荐 `open_type` 使用 `_iframe`；`_blank` 会在独立浏览器窗口打开。
- 当前管理员必须拥有对应菜单权限，WebOS 才会展示入口。

## 三、Service 层

### `WorkspaceService`

命名空间：`App\Apps\CmsproWebos\Services\WorkspaceService`

| 方法 | 说明 |
|---|---|
| `getForAdmin(int $adminUserId): WebosWorkspace` | 读取或创建管理员工作区，并补齐默认偏好 |
| `saveForAdmin(int $adminUserId, array $payload): WebosWorkspace` | 校验、规范化并保存桌面入口和偏好 |

调用示例：

```php
use App\Apps\CmsproWebos\Services\WorkspaceService;

$workspace = app(WorkspaceService::class)->getForAdmin($adminUserId);
```

扩展应用不应直接修改其他管理员的工作区。需要固定入口时，先确认管理员授权和菜单存在，再调用服务保存完整的 `desktop_items` 数组。

## 四、工作区接口

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/admin/cmspro/webos/api/workspace` | 读取当前管理员工作区 |
| PUT | `/admin/cmspro/webos/api/workspace` | 保存当前管理员工作区 |
| GET | `/admin/cmspro/webos/api/catalog` | 读取有权菜单、已安装应用和最近操作记录 |

接口使用后台 Session 认证和 CSRF 保护，返回统一的 `code`、`message`、`data`、`timestamp` 结构。

## 五、桌面入口结构

```json
{
    "id": "menu-88",
    "menu_id": 88,
    "title": "内容管理",
    "path": "/admin/content",
    "icon": "fa fa-file-text",
    "group_title": "内容中心",
    "x": 0,
    "y": 1
}
```

- `id`：工作区内唯一标识，系统菜单使用 `menu-{菜单ID}`。
- `path`：必须是站内绝对路径，最长 500 字符。
- `x`、`y`：桌面网格坐标，服务端限制为 0–99。
- 单个工作区最多保存 48 个入口。

## 六、前端扩展原则

1. 新应用页面保持完整 HTML 结构，确保可在 WebOS iframe 中独立渲染。
2. 页面不得依赖父窗口中的全局变量；权限脚本应在自身页面注入。
3. 与 WebOS 的交互优先依赖菜单和系统 API，不直接访问其内部 DOM。
4. 静态资源必须本地化，不通过 CDN 加载。
5. 应用如需独立浏览器窗口，使用 `_blank`；需要 WebOS 多窗口体验时使用 `_iframe`。

## 七、数据与卸载注意事项

- 工作区表仅存菜单路径、图标类名和界面偏好，不复制业务数据。
- 目标应用卸载后，历史桌面入口可能暂时保留；用户可在入口管理中移除。后续版本可通过系统生命周期钩子增加自动清理。
- 卸载 WebOS 会删除 `app_cmspro_webos_workspaces` 表，因此应在卸载前备份需要保留的布局。

## 八、版本与更新日志

| 版本 | 日期 | 更新人 | 说明 |
|---|---|---|---|
| 1.0.0 | 2026-09-28 | CmsPro | 初始版本，提供菜单发现、工作区服务和桌面管理接口 |
