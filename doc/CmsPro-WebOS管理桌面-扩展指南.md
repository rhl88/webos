# CmsPro WebOS 管理桌面 · 扩展指南

> 文档版本：1.4.5 | 更新日期：2026-09-29
> 适用应用：CmsproWebos v1.4.5+

## 一、概述

其他应用无需依赖 WebOS 专用接口即可进入桌面：只要在 `manifest.json` 中声明启用且对管理员可见的后台菜单，WebOS 会通过系统菜单服务自动发现该入口，并使用菜单目录中的 `app_id` 将同一应用的菜单聚合到一个独立窗口。WebOS 开始菜单只消费 `admin` 菜单；`user` 与 `home` 菜单可在安装时配置挂载位置，但不会进入 WebOS 桌面目录。

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
- `menus`、`user_menus`、`home_menus` 分别对应后台、用户端、前端；安装弹窗只为非空菜单数组显示对应的顶级挂载选择器。
- 同一应用的菜单必须关联同一个 `app_id`；子菜单可继承父菜单的 `app_id`，这是开始菜单聚合和窗口菜单隔离的依据。
- 应用中心、桌面快捷方式、任务栏和开始菜单统一优先读取应用根目录 `icon.svg`，其次读取 `icon.png`，两者均不可用时使用当前 `manifest.json` 的 `icon`；清单图标可填写站内图片路径或 Font Awesome 4.7、系统 Layui 图标类名，不使用 emoji。
- 推荐 `open_type` 使用 `_iframe`。WebOS 对安全的站内路径统一使用应用独立窗口承载，不依赖后台全局侧栏。
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
| GET | `/admin/cmspro/webos/api/catalog` | 读取有权的后台 `admin` 菜单、已安装应用和最近操作记录 |

接口使用后台 Session 认证和 CSRF 保护，返回统一的 `code`、`message`、`data`、`timestamp` 结构。目录接口中的每个应用包含 `icon_url`、`manifest_icon`、`has_config`、`is_system`、`status` 等字段，供前端渲染图标与应用操作。

WebOS 不新增应用管理接口：应用中心的市场安装、本地安装、启用/禁用、卸载、导出、备份、文档、配置和上传安装都直接调用系统已有的 `/api/admin/apps/*`、`/api/admin/market/*` 接口。页面同时通过 `data-market-base-url` 把系统应用市场地址（`apps.market.api_url`，协议相对形式）传给前端，用于解析远程市场图标。

## 五、桌面入口结构

```json
{
    "id": "menu-88",
    "menu_id": 88,
    "app_id": "cmspro.demo",
    "title": "内容管理",
    "path": "/admin/content",
    "icon": "fa fa-file-text",
    "group_title": "内容中心",
    "x": 0,
    "y": 1
}
```

- `id`：工作区内唯一标识，系统菜单使用 `menu-{菜单ID}`。
- `app_id`：入口所属应用标识，可为空；用于在应用被禁用、后台菜单不再下发时仍能识别所属应用（右键菜单的应用操作、图标解析）。仅允许字母、数字、下划线、点和短横线。
- `path`：必须是站内绝对路径，最长 500 字符。
- `x`、`y`：桌面网格坐标，服务端限制为 0–99。
- 单个工作区最多保存 48 个入口。
- WebOS 在加载工作区时会用当前后台菜单补齐缺失的 `app_id` 并静默保存，旧版入口无需手工处理。

## 六、前端扩展原则

1. 新应用页面保持完整 HTML 结构，确保可在 WebOS iframe 中独立渲染。
2. 页面不得依赖父窗口中的全局变量；权限脚本应在自身页面注入。
3. 与 WebOS 的交互优先依赖菜单和系统 API，不直接访问其内部 DOM。
4. 静态资源必须本地化，不通过 CDN 加载。
5. WebOS 窗口按 `app_id` 复用：同一应用的不同菜单切换 iframe，不重复创建窗口。
6. 若菜单缺少 `app_id`，WebOS 会将第二级系统菜单视为文件夹；顶级菜单下的直属叶子菜单聚合为顶级同名文件夹。新应用应正确关联 `app_id`，不要依赖系统菜单回退行为。
7. 同一 `app_id` 只有一个可访问菜单时，WebOS 默认隐藏窗口左侧菜单；声明多个后台菜单时默认展开，用户可在标题栏手动收起。
8. 普通应用窗口标题按 `icon.svg`、`icon.png`、`manifest.json.icon` 顺序显示应用图标；应用记录的 `is_system` 为真时保留 CMSPRO Logo。
9. 桌面快捷方式、任务栏图标与开始菜单卡片同样按第 8 条的顺序解析应用图标；不带 `app_id` 的系统菜单保留菜单自带图标。
10. 应用如需在 WebOS 中提供“设置”入口，应在 `manifest.json` 声明 `config_groups`；配置项类型继续使用系统的 `text`、`textarea`、`number`、`select`、`switch`、`image`，WebOS 设置弹窗会按同样类型渲染。

## 七、数据与卸载注意事项

- 工作区表仅存菜单路径、图标类名、界面偏好和入口使用统计，不复制业务数据。
- 目标应用卸载后，历史桌面入口可能暂时保留；用户可在入口管理中移除。后续版本可通过系统生命周期钩子增加自动清理。
- 卸载 WebOS 会删除 `app_cmspro_webos_workspaces` 表，因此应在卸载前备份需要保留的布局。

## 八、版本与更新日志

| 版本 | 日期 | 更新人 | 说明 |
|---|---|---|---|
| 1.4.5 | 2026-09-29 | CmsPro | 任务栏时间按方位分行显示；新增点击时间弹出的日历面板（本地渲染，不新增接口） |
| 1.4.4 | 2026-09-29 | CmsPro | 应用窗口左侧菜单栏固定宽度调整为 146px，移除窄屏断点的加宽规则 |
| 1.4.3 | 2026-09-29 | CmsPro | 桌面入口新增 `app_id` 字段并在加载时自动补齐；桌面图标右键菜单提供打开应用、删除图标与卸载应用 |
| 1.4.2 | 2026-09-29 | CmsPro | 开始菜单、通知中心和账号菜单支持点击面板外部收起，面板内交互不受影响 |
| 1.4.1 | 2026-09-29 | CmsPro | 已安装列表精简为应用信息、状态、操作三列；操作列直接提供打开、手动升级、导出，管理入口移入更多菜单；状态筛选仅保留已安装界面；操作弹窗限高滚动；开始菜单固定到桌面使用应用名称 |
| 1.4.0 | 2026-09-29 | CmsPro | 应用图标规则扩展到桌面、任务栏与开始菜单；应用市场接入远程图标与安装升级状态；已安装列表增加状态筛选与应用操作（备份、文档、手动升级、导出、设置、卸载、上传安装） |
| 1.3.3 | 2026-09-29 | CmsPro | 应用窗口标题接入应用图标并保留系统应用 Logo 规则 |
| 1.3.2 | 2026-09-29 | CmsPro | 增加应用窗口左侧菜单收起与单菜单自动隐藏规则 |
| 1.3.1 | 2026-09-29 | CmsPro | 应用中心图标增加 SVG、PNG、清单图标三级回退 |
| 1.3.0 | 2026-09-29 | CmsPro | 增加系统菜单文件夹、常用入口统计和 OS 设置偏好 |
| 1.2.0 | 2026-09-29 | CmsPro | 开始菜单限定后台终端；补充多终端菜单自动识别与挂载规则 |
| 1.1.0 | 2026-09-29 | CmsPro | 增加应用级窗口聚合、菜单隔离、开始菜单分组及任务栏位置偏好说明 |
| 1.0.0 | 2026-09-28 | CmsPro | 初始版本，提供菜单发现、工作区服务和桌面管理接口 |
