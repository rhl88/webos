# CmsPro WebOS 管理桌面 · 扩展指南

> 文档版本：1.9.79 | 更新日期：2026-10-01
> 适用应用：CmsproWebos v1.9.79+

## 一、概述

其他应用无需依赖 WebOS 专用接口即可进入桌面：只要在 `manifest.json` 中声明启用且对管理员可见的后台菜单，WebOS 会通过系统菜单服务自动发现该入口，并使用菜单目录中的 `app_id` 将同一应用的菜单聚合到一个独立窗口。WebOS 开始菜单只消费 `admin` 菜单；`user` 与 `home` 菜单可在安装时配置挂载位置，但不会进入 WebOS 桌面目录（`home` 菜单用于窗口标题栏的「前台菜单」下拉，见第三章）。

### 应用出现在 WebOS 的完整链路

应用不需要调用任何 WebOS 接口，以下链路全部由框架与应用清单自动完成：

1. **清单声明**：应用在 `manifest.json` 的 `menus` 数组声明后台菜单（含 `title`、`icon`、`path`、`code`、`open_type`、`order`、`children`），并通过 `permissions` 声明权限码。
2. **安装写库**：安装应用时，框架安装器按安装弹窗选择的挂载位置把菜单写入系统 `admin_menus` 表（`terminal_type=admin`）；`user_menus`、`home_menus` 分别以对应终端写库。
3. **菜单下发**：系统菜单接口 `GET /api/admin/menus/user` 只返回当前管理员已被授权的菜单；框架 `MenuService` 在「子菜单被授权、父级未授权」时自动补全祖先菜单链，保证树形结构完整（与后台 menus.json 逻辑一致）。
4. **WebOS 消费**：WebOS 桌面加载时请求 `GET /api/admin/apps` 与 `GET /api/admin/menus/user`，前端按 `terminal_type` 过滤仅保留 `admin` 菜单，再按菜单节点携带的 `app_id` 把同一应用的菜单聚合为一个应用窗口、一个开始菜单卡片与一套桌面/任务栏图标。

应用启用后自动出现，禁用或卸载后桌面/任务栏图标按「当前管理员菜单目录是否仍包含该入口」过滤（`entryAssigned`，见第二章「权限对齐注意事项」），无需应用侧维护。

### 对接点总览

| 对接点 | 机制 | 是否需要应用侧代码 |
|---|---|---|
| 出现在开始菜单/桌面/任务栏 | manifest `menus` + 角色分配菜单 | 否（安装即生效） |
| 应用窗口与左侧菜单树 | 菜单层级 + `app_id` 聚合 + `open_type` | 否 |
| 应用图标 | 应用根目录 `icon.svg` / `icon.png` / manifest `icon` | 放置图标文件即可 |
| 窗口标题栏「前台菜单」下拉 | manifest `home_menus`（前台终端菜单） | 声明清单即可 |
| 通知中心推送通知 | 系统服务 `NotificationService::push()` | 调用框架服务 |
| 通知中心待办聚合 | HookManager filter `admin.notifications.todos` | 注册 filter 回调 |
| 应用设置弹窗 | manifest `config_groups` | 声明配置组 |
| WebOS 个人工作区 API | 工作区/壁纸/日历/全量待办接口（见第五章） | 可选，属用户个人数据，不建议应用直写 |

WebOS 没有面向第三方应用的桌面右键菜单、任务栏或开始菜单扩展点；这些区域全部由系统菜单目录与工作区数据驱动。

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

- 叶子菜单必须提供以 `/` 开头的站内路径；`open_type` 为 `_blank` 时额外允许 `http(s)` 外链。
- `menus`、`user_menus`、`home_menus` 分别对应后台、用户端、前端；安装弹窗只为非空菜单数组显示对应的顶级挂载选择器。
- 同一应用的菜单必须关联同一个 `app_id`；子菜单可继承父菜单的 `app_id`，这是开始菜单聚合和窗口菜单隔离的依据。
- 应用中心、桌面快捷方式、任务栏和开始菜单统一优先读取应用根目录 `icon.svg`，其次读取 `icon.png`，两者均不可用时使用当前 `manifest.json` 的 `icon`；清单图标可填写站内图片路径或 Font Awesome 4.7、系统 Layui 图标类名，不使用 emoji。
- `open_type` 决定 WebOS 窗口左侧菜单点击后的承载方式，四种取值均已支持（与后台菜单一致），非法值与历史空值兜底为 `_iframe`：

| `open_type` | 界面名称 | WebOS 中的行为 | 使用建议 |
|---|---|---|---|
| `_iframe` | 嵌套网页 | 在窗口内容区的 `[data-window-page-host]` 容器内渲染 `<iframe>` 加载完整页面 | **默认推荐**。页面保持完整 HTML 结构，与 WebOS 完全隔离，互不污染 |
| `_component` | 路由模式 | AJAX GET 该路径，把返回的 HTML 片段直接注入窗口内容区（非 iframe），注入后按文档顺序串行执行片段脚本，脚本跑完再调用 `layui.element.init()` / `layui.form.render()` | 仅用于与主框架深度融合的轻量片段。片段运行在 WebOS 主文档中，会共享全局作用域，需自行避免变量与样式冲突 |
| `_blank` | 新建窗口 | `window.open(path, '_blank', 'noopener')` 打开浏览器新标签页，窗口内容与侧栏选中态保持不变 | 独立工具页、跳转第三方系统；唯一允许 `http(s)` 外链的取值 |
| `_layer` | 弹窗网页 | `layer.open({ type: 2, title, content: path, area: ['80%', '80%'], maxmin: true })`，layui 未就绪时降级为新标签页 | 临时操作页、预览页；依赖桌面页已加载的 layui |

- 打开应用或系统菜单文件夹时，WebOS 会选择第一项 `_iframe` / `_component` 菜单作为默认页面，`_blank` 与 `_layer` 菜单会被跳过；若整个应用只有这两类菜单，则回退到第一项（此时窗口内容区为空）。
- 桌面页的资源环境与传统后台 `layouts/admin.blade.php` 对齐，因此 `_component` 片段可直接复用后台的 CSS 与 JS：`pear.css`、`font-awesome 4.7`、`admin.css`、`variables.css`、`reset.css`、全量版 `layui.js` 与 `pear.js` 均已加载，`window.CMSPRO_PERMISSIONS` / `window.hasPermission` 亦已注入。片段可直接 `layui.use(['toast', 'button', 'popup', 'dtree', 'echarts', 'tinymce', …])` 使用任意 pear 扩展模块。
- 桌面页在启动时预热 `element`、`form`、`jquery`、`layer`、`toast`、`button`、`popup` 七个模块；`admin`、`menu`、`tabPage`、`page`、`menuSearch`、`messageCenter` 等框架级模块不预热，片段如需使用可自行 `layui.use`，但应注意它们会操作后台侧栏与选项卡 DOM。
- 桌面页还加载了 `marked.min.js` 与 `highlight.js`（`github-dark` 主题，语言包 bash / css / javascript / json / php / sql / xml），用于应用文档预览的 Markdown 渲染与代码高亮，`_component` 片段可直接使用 `window.marked` 与 `window.hljs`，无需重复引入。
- 与后台一致，桌面页不向全局暴露 `$` / `window.jQuery`（jQuery 由 layui 内部持有），片段须经 `layui.use(['jquery'], function ($) { … })` 获取。
- 片段自带的 `<link>`、`<style>` 在注入时即生效；自带的 `<script>` 由 `runFragmentScripts` 按文档顺序串行执行，外链脚本会等 `load` / `error` 后再执行下一个，与后台 jQuery `.html()` 的语义一致，因此「先外链插件、后内联调用」的写法可以正常工作。片段样式无隔离，请使用应用前缀类名避免污染桌面。
- `admin.dark.css` 未加载：其全部选择器以 `.pear-admin-dark` 前缀限定，WebOS 根元素为 `.webos-desktop`，永不匹配。
- 当前管理员必须拥有对应菜单权限，WebOS 才会展示入口。

### 菜单可见性与接口权限（两层各自独立）

| 层 | 控制内容 | 机制 |
|---|---|---|
| 菜单可见性 | 入口是否出现在 WebOS 开始菜单/桌面/任务栏/应用窗口侧栏 | 系统菜单接口 `GET /api/admin/menus/user` 只返回分配给当前管理员角色的菜单；普通管理员仅授权子菜单而未授权父级分组时，框架 `MenuService` 自动补全祖先菜单链（管理 → 应用分组 → 子菜单），WebOS 与框架后台菜单口径完全一致。角色收回授权后，桌面/任务栏对应图标不再显示 |
| 接口权限 | 页面与接口本身能否被调用 | 应用控制器使用系统 `CheckPermission` 中间件校验权限码（manifest `permissions` 声明）；菜单可见不代表接口放行，两层各自独立 |

菜单可见性过滤不需要应用参与；应用只需保证控制器上的权限码校验与菜单 `code` 对应。

### 权限对齐注意事项

- 桌面与任务栏图标按「已分配」口径过滤：应用中心的可见性仅限超级管理员（super_admin），非超管工作区重置后桌面为空。
- 停用（禁用）应用的桌面图标不占桌面格位：其它图标可移入其格子；应用重新启用后若原坐标被占用，自动落到第一个空白格显示。
- WebOS 自身（`cmspro.webos`）的菜单不出现在系统菜单目录中，应用中心入口由 WebOS 内置提供。

## 三、窗口系统对接

### 普通应用窗口与特殊窗口

| 窗口类型 | 判定 | 结构差异 |
|---|---|---|
| 普通应用窗口 | 入口携带 `app_id`（应用窗口）或无 `app_id`（系统菜单按文件夹聚合，`folder_id` 归属） | 标题栏品牌区 + 控制区（收起左侧菜单、前台菜单、选项卡条、最小化/最大化/关闭）+ 可收起的左侧菜单树 + 内容区 `[data-window-page-host]` |
| WebOS 内置特殊窗口 | 入口 `id` 或 `special` 标记：应用中心 `webos-app-center`（`special: market`）、OS 设置 `webos-settings`（`special: settings`）、通知中心 `webos-notification-page`（`special: notifications`）、官网动态 `webos-official-news`（`special: official-news`） | 内容区为固定 shell 容器（如 `data-app-center`），**没有** `[data-window-page-host]`；OS 设置与官网动态窗口连左侧菜单也没有 |

窗口复用键（`windowKey`）：特殊窗口用自身 `id`；普通应用窗口用 `app-{app_id}`；无 `app_id` 的系统菜单用 `folder-{folder_id}`。同一键的入口只开一个窗口，打开其它菜单在窗口内切换。

特殊窗口是 WebOS 私有实现，**没有开放第三方注册机制**；其它应用不要占用 `webos-` 前缀的入口 `id`。

### 应用窗口多选项卡对页面形态的影响

- 开关：OS 设置 → 系统设置 →「应用窗口多选项卡」（偏好键 `window_tabs`，默认关闭）。仅对**开启后新打开的窗口**生效，已打开窗口保持原模式。
- 启用判定：`window_tabs` 开启 **且** 窗口内容区存在 `[data-window-page-host]`——因此应用中心/OS 设置/通知中心/官网动态等特殊窗口天然排除；普通应用窗口自动获得选项卡能力，应用侧无需任何改造。
- 行为：点击左侧菜单以选项卡打开（选项卡条在标题栏左侧、窗口按钮贴最右）；同一路径复用既有选项卡；切换仅切换 `.window-page` 容器显隐，**页面状态保留**（iframe 不重载、表单输入与滚动位置不变）；选项卡溢出时出现 `<` / `>` 导航箭头；选项卡右键提供「关闭当前 / 关闭其它 / 关闭全部」；标题栏品牌区应用名固定，`small` 副标题实时跟随当前选项卡的菜单名。
- 对 `_component` 片段的额外要求：选项卡模式下同一窗口的多个片段共存于桌面主文档，片段必须用应用前缀类名与命名空间，不得互相覆盖全局变量（单页模式下只有一个片段，风险更低）。
- `_blank`（新建窗口）与 `_layer`（弹窗网页）菜单不进入选项卡，行为不变。

### 「前台菜单」对接机制（应用如何让窗口内出现前台菜单下拉）

数据流：应用 manifest 声明 `home_menus` → 安装时以 `terminal_type=home` 写入系统 `admin_menus` 表（安装弹窗显示「前端菜单挂载位置」）→ WebOS 打开应用窗口时请求系统菜单树接口 `GET /api/admin/menus/tree?terminal_type=home` → 前端按窗口应用的 `app_id` 递归收集叶子节点（叶子必须具备 `name` 与 `path`）→ 在窗口标题栏「收起左侧菜单」按钮左侧注入「前台」下拉按钮。

行为细节：

- 下拉按钮为固定宽度 58px 的文字按钮「前台 + 下拉箭头」，下拉项只显示菜单名称（不带图标），点击以浏览器新标签页打开前台地址。
- **链接域名绑定兼容（1.9.80）**：菜单树接口返回的是原始存储路径，域名绑定应用（如论坛绑定 forum.example.com）的路径在主站不可达。前端收集叶子后会请求应用侧接口 `GET /admin/cmspro/webos/api/home-menu-urls?app_ids[]=`（内部复用框架 `menu_path()`）把原始路径换算为可直达 URL——域名绑定应用返回绑定域名根、普通应用返回主站地址（兼容子域名部署）、外链原样返回；转换失败回退原始路径，不阻断菜单显示。应用侧无需任何声明，安装即自动兼容。
- 应用没有前台菜单时不注入任何元素；系统应用（`is_system`）不注入；接口读取失败静默降级，不影响窗口本身。
- 每应用的前台菜单叶子按 `app_id` 缓存（`homeMenusCache`，含转换后的 url），重复打开窗口不重复请求。
- 注意：前台页面自身的访问控制由前台路由与应用自行决定，WebOS 只负责展示与跳转，不做额外校验。

## 四、Service 层

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

### `WallpaperService`

命名空间：`App\Apps\CmsproWebos\Services\WallpaperService`

| 方法 | 说明 |
|---|---|
| `listFor(int $adminId): array` | 列出该管理员上传的壁纸，返回 `url`（相对路径）、`size`（字节）、`uploaded_at`（`Y-m-d H:i:s`），按上传时间倒序 |
| `store(UploadedFile $file, int $adminId): string` | 保存壁纸并返回相对路径，文件名重写为 `admin_{adminId}_{YmdHis}_{随机6位}.{ext}` |
| `delete(string $url, int $adminId): bool` | 删除壁纸，仅允许本人上传且位于壁纸目录内的文件；不合法或文件不存在返回 `false` |

壁纸目录常量为 `WallpaperService::DIRECTORY`（`apps/cmspro.webos/wallpapers`，相对 `public`）。构造参数 `?string $directoryOverride` 仅供测试把目录指向临时路径，业务代码使用容器解析的默认实例即可：

```php
use App\Apps\CmsproWebos\Services\WallpaperService;

$wallpapers = app(WallpaperService::class)->listFor($adminUserId);
```

扩展应用不得跨管理员读写壁纸；`url` 一律以相对路径在数据库与偏好中流转，渲染时由前端补前导斜杠。

## 五、工作区与 HTTP API 清单

### WebOS 应用自有接口

路由定义于 `Routes/admin.php`，前缀 `/admin/cmspro/webos`，全部走 `web` + `auth:admin` 中间件（Session 认证 + CSRF），响应为统一 `code`（0 成功）、`message`、`data`、`timestamp` 结构：

| 方法 | 路径 | 参数 | 说明 |
|---|---|---|---|
| GET | `/admin/cmspro/webos/api/workspace` | — | 读取当前管理员工作区（桌面项、任务栏项、偏好，含默认偏好补齐） |
| PUT | `/admin/cmspro/webos/api/workspace` | JSON：`desktop_items`（≤48）、`taskbar_items`（≤12）、`preferences`（键白名单见第八章） | 保存当前管理员工作区；验证失败返回 422 与逐字段 `errors` |
| POST | `/admin/cmspro/webos/api/workspace/reset` | — | 重置工作区：桌面/任务栏恢复初始默认布局（超管固定「应用中心」，非超管为空），偏好恢复默认；自定义壁纸文件保留 |
| POST | `/admin/cmspro/webos/api/wallpaper` | `multipart/form-data`，字段 `wallpaper`（jpg/jpeg/png/gif/webp，≤5120KB） | 上传自定义壁纸，返回相对路径 `url` |
| GET | `/admin/cmspro/webos/api/wallpapers` | — | 读取当前管理员的自定义壁纸列表（`url`、`size`、`uploaded_at`，按上传时间倒序） |
| DELETE | `/admin/cmspro/webos/api/wallpaper` | JSON 字段 `url`（≤200） | 删除自定义壁纸；非本人或非法路径返回业务码 `40302` |
| GET | `/admin/cmspro/webos/api/calendar` | 查询参数 `month`（`YYYY-MM` 格式，正则 `^\d{4}-(0[1-9]|1[0-2])$`） | 返回当月日历数据（含农历与二十四节气换算） |
| GET | `/admin/cmspro/webos/api/all-todos` | — | 全量待办（含已读）：聚合系统待办 hook（含权限过滤/去重/归零过滤），附加 `seen` 与 `is_read`（`seen_count ≥ count` 视为已读）；数据源见第七章 |

工作区数据是**当前管理员的个人数据**，接口强制绑定当前管理员 ID；其它应用没有跨管理员读写他人工作区的合法途径，也不应依赖工作区接口传递业务数据。

### WebOS 复用的系统接口

WebOS 不新增应用管理接口，应用中心的数据与操作全部复用系统已有接口，与传统后台应用管理页面（`resources/views/admin/app/index.blade.php`）保持同一数据源，便于统一维护：

| 数据 | 系统接口 |
|---|---|
| 已安装应用列表 | `GET /api/admin/apps`（含 `icon_url`、`is_system`、`status`、`manifest` 等完整字段） |
| 后台菜单目录 | `GET /api/admin/menus/user`，前端按 `terminal_type` 过滤仅保留 `admin` 后台菜单 |
| 菜单树（任意终端） | `GET /api/admin/menus/tree?terminal_type={admin\|user\|home}`（安装弹窗挂载选择与前台菜单下拉共用） |
| 操作记录 | `GET /api/admin/app-logs?per_page=30`（分页接口，切换到「安装记录」时按需读取） |
| 应用操作 | `/api/admin/apps/*`、`/api/admin/market/*`：市场安装、本地安装、启用/禁用、卸载、导出、备份、文档、配置和上传安装 |
| 市场首页 | `GET /api/admin/market/home`：应用市场「首页」子 Tab 数据源，框架代理远程市场 `GET /api/market/home`（返回 random 随机推荐 / featured 官方精选按下载量补全 / positions 推荐位应用），服务端缓存 60 秒 |
| 应用图标 | `GET /api/app/{app_id}/icon`：服务端按应用根目录 `icon.svg` → `icon.png` 顺序返回图片（见第九章） |
| 通知与待办 | `GET /api/admin/notifications/panel`（待办 + 最近 10 条通知 + 未读数）、`GET /api/admin/notifications/badge`（未读 + 待办增量）、`POST /api/admin/notifications/todo-read`（`key` + `count`，记录已见数量）、`POST /api/admin/notifications/read-all`、`POST /api/admin/notifications/{id}/read`、`GET /api/admin/notifications?per_page=15&page=N`（通知分页） |
| 修改密码 | `PUT /api/admin/auth/password` |

页面同时通过 `data-market-base-url` 把系统应用市场地址（`apps.market.api_url`，协议相对形式）传给前端，用于解析远程市场图标。

导出、手动升级、备份、文档四项的前端交互照抄传统后台 `resources/views/admin/app/index.blade.php`，接口调用方式与参数完全一致：

| 操作 | 接口与调用要点 |
|---|---|
| 导出 | `GET /api/admin/apps/{appId}/export`，XHR `responseType: 'blob'`，文件名从 `Content-Disposition` 解析；响应若为 JSON 说明后端报错，须读文本解析 `message` 后提示，不能直接落盘 |
| 手动升级 | `POST /api/admin/apps/upload`（`multipart/form-data`：`package` 必填 ≤50MB、`app_id` 手动升级时携带），提示语直接使用后端 `message` |
| 备份 | 三步分卷：`POST /{appId}/backups/prepare`（`volume_size`）→ 循环 `POST /{appId}/backups/table`（`session_id`、`table`、`volume_size`、`last_id`，按返回的 `has_more` / `last_id` 续传）→ `POST /{appId}/backups/finish`（`session_id`）；列表 `GET /{appId}/backups`、下载 `GET /api/admin/apps/backups/{backupId}/download`、删除 `DELETE /api/admin/apps/backups/{backupId}`；导入恢复：上传 `POST /{appId}/backups/upload-file`（`multipart/form-data`：`package`）、服务器文件列表 `GET /{appId}/backups/local-files` |
| 恢复 | 三步：`POST /{appId}/backups/restore-prepare`（`backup_id`）→ 循环 `POST /{appId}/backups/restore-table`（`session_id`、`table`、`offset`，按 `has_more` / `offset` 续传，单表最多重试 3 次）→ `POST /{appId}/backups/restore-finish`（`session_id`） |
| 文档 | 树 `GET /{appId}/docs`（返回 `tree[]` / `has_doc`，目录节点无 `path`，文件节点仅 `.md`）、正文 `GET /{appId}/docs/content`（`path` 作为查询参数，用 jQuery `data` 传递而非手工拼接）、下载 `GET /{appId}/docs/download?path=` |

后台靠 `$.ajaxSetup` 全局注入 CSRF，WebOS 无全局 jQuery，因此封装 `legacyAjax(settings)` 在每次请求头写入 `runtime.csrfToken`。后台在备份/恢复各步骤使用 `layer.closeAll()`，WebOS 的应用窗口同样由 `layer.open` 承载，全关会误杀应用窗口，故改用 `backupLayerStack` 数组配合 `pushBackupLayer()` / `closeBackupLayers()` 只关闭自己打开的层级。备份弹窗的工具栏（左「共 N 条备份记录 + 导入恢复」/ 右「立即备份」）与后台一致放在顶部；后台的“导入恢复”（`openImportDialog` / `doLocalRestore`）已照抄移植：上传 Tab 拖拽/点选 `.zip` → `upload-file` 上传成功后走分步恢复，服务器 Tab 懒加载 `local-files` 列表并逐文件确认恢复；隐藏 fileInput 随弹层 `remove` 清理。

## 六、桌面入口结构

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

字段与校验规则（`WorkspaceService::sanitizeDesktopItem` + `WebosController::saveWorkspace` 验证规则双层约束，任务栏项结构相同但**无** `x`/`y`）：

| 字段 | 校验 | 说明 |
|---|---|---|
| `id` | 必填，仅 `A-Za-z0-9_-`，截断 80 字符，空值兜底 UUID | 工作区内唯一标识，系统菜单使用 `menu-{菜单ID}`，应用入口可自定义 |
| `menu_id` | 可空，整数 ≥ 0 | 来源菜单 ID，用于菜单目录比对 |
| `app_id` | 可空，仅 `A-Za-z0-9_.-`，≤100 字符 | 入口所属应用标识；用于应用禁用/菜单不下发时仍能识别所属应用（右键应用操作、图标解析），也是开始菜单聚合与窗口菜单隔离的依据 |
| `title` | 必填，截断 60 字符，空值兜底「未命名应用」 | 桌面显示名称 |
| `path` | 必填，必须以 `/` 开头且不得以 `//` 开头，截断 500 字符 | 站内绝对路径（URL 相对路径，不含域名）；协议地址与协议相对地址一律拒绝；数据库只存这种相对路径 |
| `icon` | 必填，仅 `A-Za-z0-9 _-`（允许字体图标类名中的空格），≤100 字符，空值兜底 `fa fa-cube` | 字体图标类名；应用图片图标由前端按应用根目录解析，不存于此字段 |
| `group_title` | 可空，截断 60 字符，空值兜底「应用」 | 分组标题 |
| `x` / `y` | 整数 0–99 | 桌面网格坐标（仅桌面项） |

数量与统计约束：

- 单个工作区最多保存 48 个桌面入口、12 个任务栏固定项。
- WebOS 在加载工作区时会用当前后台菜单补齐缺失的 `app_id` 并静默保存，旧版入口无需手工处理。
- 桌面图标显示按「已分配」过滤（见第二章），停用应用的图标不占格位。

## 七、通知与待办接入

WebOS 任务栏铃铛与通知中心窗口的数据来自系统 `NotificationService`（`app/Services/NotificationService.php`），分两类数据源，其它应用按需接入：

### 推送型通知（有历史、按管理员独立已读）

应用在业务事件发生时调用框架服务写一条通知：

```php
use App\Services\NotificationService;

app(NotificationService::class)->push([
    'title'           => '导入任务完成',            // 必填，≤100 字
    'content'         => '共导入 128 条记录',        // 可选，≤500 字
    'link'            => '/admin/example/records',  // 可选，站内路径（见下方约定）
    'app_id'          => 'cmspro.example',          // 可选，来源应用标识
    'permission_code' => 'cmspro.example.record',   // 可选，非空则仅拥有该权限的管理员（或超管）可见
    'level'           => 'success',                 // info | success | warning | error，默认 info
]);
```

通知写入 `admin_notifications` 表后自动出现在任务栏通知面板、徽标与通知中心窗口。

**`link` 路径约定**：WebOS 点击通知时按 `link` 与系统菜单目录中的入口路径精确匹配，命中则在 WebOS 内打开对应应用窗口，未命中回退浏览器新标签页。因此 `link` **必须使用 `admin_menus` 表中真实存在的菜单路径**，凭空推测的路径会导致点击退化为新标签页打开。

### 聚合型待办（实时计算、数量归零自动消失、无历史）

应用把「待处理事项数量」通过 HookManager filter 实时提供给通知中心：

```php
use App\Services\HookManager;
use App\Services\NotificationService;

// 注册时机：应用 ServiceProvider 的 boot() 中
app(HookManager::class)->registerFilter(
    NotificationService::TODO_HOOK,   // 'admin.notifications.todos'
    function (array $entries): array {
        // 必须合并传入的 $entries 后返回完整数组
        return array_merge($entries, [
            [
                'key'             => 'cmspro.example.pending',   // 全应用唯一，重复注册的条目按 key 去重
                'title'           => '待审核内容',
                'count'           => 12,                          // int ≥ 0；0 的条目会被统一过滤（归零自动消失）
                'link'            => '/admin/example/review',     // 必填，站内菜单路径（同上约定）
                // 'permission_code' => 'cmspro.example.review',   // 可选，权限过滤同推送型
            ],
        ]);
    },
    10,                             // priority，默认 10
    'cmspro.example'                // appId，应用卸载时框架据此自动移除其 hook
);
```

聚合契约（`NotificationService::aggregateTodoEntries()` 统一兑现，应用无需重复实现）：

| 项 | 规则 |
|---|---|
| 回调签名 | `callable(array $entries): array`——收到此前累积的条目数组，**必须 `array_merge` 后返回完整数组**（返回值直接替换累积值） |
| 条目字段 | `key`（string 非空）、`title`（string 非空）、`count`（int ≥ 0）、`link`（string 非空）、`permission_code`（可选 string）；字段缺失或类型不符的条目被静默丢弃 |
| 权限过滤 | `permission_code` 非空的条目仅对拥有该权限码的管理员（或超管）可见 |
| 去重与容错 | `key` 重复的条目只保留第一个；单个回调抛异常仅记录日志，不影响其它应用聚合 |
| 已读机制 | `admin_todo_reads` 表按「管理员 + todo_key」记录已见数量 `seen_count`：点击待办条目时 WebOS 调 `POST /api/admin/notifications/todo-read`（`key` + `count`）写入；`seen_count ≥ count` 的条目在面板/徽标中消失；业务量回落时已见量自动收敛到当前量，之后新增的数量恢复显示 |
| 归零契约 | `count = 0` 的条目后端直接过滤，任何视图都不会展示「标题 0」的空待办 |

### WebOS 全量待办接口

通知中心窗口的「待办」Tab 使用应用侧接口 `GET /admin/cmspro/webos/api/all-todos`（含已读，条目附加 `seen` 与 `is_read` 字段），与任务栏面板（只显示未读）分工：面板 = 未读提醒，窗口 = 全量管理。

## 八、偏好（preferences）键全集与使用统计

### 偏好键全集

偏好按管理员隔离存储于工作区 `preferences` JSON 字段，服务端 `WorkspaceService::DEFAULT_PREFERENCES` 定义默认值与键白名单：

| 键 | 类型 | 默认值 | 取值约束 |
|---|---|---|---|
| `wallpaper` | string | `webos-default` | 枚举 `webos-default` / `deep-blue` |
| `wallpaper_url` | string | `''` | 仅允许 `apps/cmspro.webos/wallpapers/{文件名}.{jpg\|jpeg\|png\|gif\|webp}` 相对路径，非法值清空 |
| `icon_size` | string | `medium` | 枚举 `small` / `medium` / `large`（桌面右键「查看」三档图标） |
| `window_tabs` | bool | `false` | 应用窗口多选项卡开关 |
| `taskbar_alignment` | string | `left` | 枚举 `left` / `center` |
| `taskbar_position` | string | `bottom` | 枚举 `top` / `bottom` / `left` / `right` |
| `clock_format` | string | `24h` | 枚举 `12h` / `24h` |
| `show_seconds` | bool | `false` | 时钟是否显示秒 |
| `motion` | bool | `true` | 界面动效（响应系统「减少动态效果」） |
| `window_width` | int | `78` | 40–100（新建窗口宽度占可用桌面区域百分比） |
| `window_height` | int | `80` | 40–100 |
| `override_admin_home` | bool | `false` | 覆盖传统后台首页（`/admin` 直达 WebOS） |
| `usage_stats` | object | `[]` | 入口使用统计，见下节 |

### 新增偏好键的「多层同步」教训（1.9.67/1.9.68 踩坑）

新增一个偏好键必须同步**四处**，缺一处即静默失效：

1. **`WorkspaceService::DEFAULT_PREFERENCES`**：登记键与默认值（同时是保存时的键白名单，`Arr::only` 按它过滤）；
2. **`WorkspaceService::sanitizePreferences()`**：登记类型/枚举校验与非法值回退；
3. **`WebosController::saveWorkspace()` 验证规则**：补充 `preferences.{key}` 规则——Laravel `$validated` 只保留有规则声明的键，**漏写会导致该键在控制器层被丢弃、无法持久化**（1.9.67 的 `icon_size` 即此因）；
4. **前端 `webos.js` 的 `normalizePreferences()`**：补充默认值，保证旧工作区数据合并后前端可读。

典型验证规则写法：`'preferences.icon_size' => ['sometimes', 'in:small,medium,large']`、`'preferences.window_tabs' => ['sometimes', 'boolean']`；持久化验证用 HTTP 测试兜底（PUT 写入后再 GET 读出断言）。

### 使用统计（`recordEntryUsage`）

- `usage_stats` 的键为入口标识（正则 `[A-Za-z0-9:._-]{1,100}`），值为 `{ count, last_opened_at }`（`count` 1–999999，`last_opened_at` 格式 `Y-m-d H:i:s`）；最多保留 80 个键。
- 前端 `recordEntryUsage(entry)` 在每次 `openEntry` 打开入口时累加（含 `_blank`/`_layer` 菜单的打开分支）并静默 `saveWorkspace` 持久化；开始菜单「常用」分组按 `count` 降序、`last_opened_at` 降序显示最多 6 个入口。
- 排除项：内置且自动打开的窗口不计入——`webos-settings`（OS 设置）与 `webos-official-news`（官网动态，仅超管进桌面时自动打开）。「常用」统计按管理员隔离，数据只用于本管理员的排序展示。
- 其它应用没有直接写入使用统计的场景；统计键由 WebOS 前端自维护，服务端仅做白名单校验。

## 九、前端扩展原则

1. 新应用页面保持完整 HTML 结构，确保可在 WebOS iframe 中独立渲染；菜单声明为 `_component`（路由模式）时，同一地址应能返回可直接注入的 HTML 片段。
2. 页面不得依赖父窗口中的全局变量；权限脚本应在自身页面注入。`_component` 片段是例外，它运行在 WebOS 主文档中并共享全局作用域，可依赖桌面页已加载的 layui / pear 环境，但需自行避免变量与样式冲突。
3. 与 WebOS 的交互优先依赖菜单和系统 API，不直接访问其内部 DOM。
4. 静态资源必须本地化，不通过 CDN 加载。
5. WebOS 窗口按 `app_id` 复用：同一应用的不同菜单只在窗口内容区内切换（iframe 重载或片段重新注入），不重复创建窗口，窗口位置与尺寸保持不变。
6. 若菜单缺少 `app_id`，WebOS 会将第二级系统菜单视为文件夹；顶级菜单下的直属叶子菜单聚合为顶级同名文件夹。新应用应正确关联 `app_id`，不要依赖系统菜单回退行为。
7. 同一 `app_id` 只有一个可访问叶子菜单时，WebOS 默认隐藏窗口左侧菜单；声明多个后台菜单时默认展开，用户可在标题栏手动收起。
8. 在 `manifest.json` 中用 `children` 声明多级菜单时，WebOS 窗口左侧会按同样的层级渲染为树：含下级的节点显示为分组，点击分组标题展开或收起，同级分组互斥（手风琴），打开窗口时自动展开当前页面所在的分组链路。中间分组不再被丢弃，但分组自身若声明了 `path` 仍不会作为可点击项渲染，需要访问的页面请放在叶子菜单上。
9. 普通应用窗口标题按 `icon.svg`、`icon.png`、`manifest.json.icon` 顺序显示应用图标；应用记录的 `is_system` 为真时保留 CMSPRO Logo。
10. 桌面快捷方式、任务栏图标与开始菜单卡片同样按第 9 条的顺序解析应用图标；不带 `app_id` 的系统菜单保留菜单自带图标。应用图片图标也可直接走系统图标接口 `GET /api/app/{app_id}/icon`（服务端按 `icon.svg` → `icon.png` 顺序返回，安装记录等界面即以该接口为主图来源）。
11. 应用如需在 WebOS 中提供“设置”入口，应在 `manifest.json` 声明 `config_groups`；配置项类型继续使用系统的 `text`、`textarea`、`number`、`select`、`switch`、`image`，WebOS 设置弹窗会按同样类型渲染。
12. 声明为 `_blank` 或 `_layer` 的菜单不占用窗口内容区：点击后只打开新标签页或 layui 弹层，窗口内已显示的页面与侧栏选中态保持不变，也不会被记为“当前页面”。这类菜单在开始菜单聚合与应用市场“打开”按钮的默认入口选择中会被跳过。
13. `http(s)` 外链菜单只有声明为 `_blank` 时才会出现在 WebOS 窗口左侧菜单中；外链入口不能被添加为桌面快捷方式，也不显示在应用中心的“入口管理”树中（桌面路径由后端 `WorkspaceService` 校验，仅接受站内路径）。
14. `_component` 片段不应重复引入 `layui.js`、`pear.js`、`pear.css`、jQuery 等主框架资源（桌面页已加载，重复引入会覆盖 `layui.config` 并破坏模块解析）。片段只输出业务结构、业务样式与业务脚本；组件渲染需在脚本中自行调用 `element.init()` / `form.render()`，WebOS 也会在全部脚本执行完毕后再补调一次。

## 十、数据与卸载注意事项

- 工作区表仅存菜单路径、图标类名、界面偏好和入口使用统计，不复制业务数据。
- 目标应用卸载后，历史桌面入口可能暂时保留；用户可在入口管理中移除。后续版本可通过系统生命周期钩子增加自动清理。
- 应用通过 HookManager 注册的待办 filter 带 `appId` 参数时，应用卸载/禁用由框架 `removeHooksByApp()` 自动移除，遗留的已读记录不影响其它应用。
- 卸载 WebOS 会删除 `app_cmspro_webos_workspaces` 表，因此应在卸载前备份需要保留的布局。

### 约束与红线（对接应用必须遵守）

1. **不修改框架与 WebOS**：对接只通过菜单清单、框架服务（`NotificationService`、`HookManager`）与系统 HTTP 接口，不得改动 `app/Services/`、`app/Http/Middleware/` 等框架代码，也不得依赖 WebOS 前端内部函数或 DOM 结构。
2. **路径一律相对**：数据库中的菜单/入口路径只存 `/` 开头的站内相对路径（不含域名与协议）；桌面入口路径由 `WorkspaceService` 强校验，`http(s)` 外链仅限 `_blank` 菜单。
3. **入口标识规范**：入口 `id` 不使用 `webos-` 前缀（WebOS 内置保留）；`app_id` 仅允许字母、数字、下划线、点和短横线。
4. **不直写他人工作区**：工作区/壁纸接口按当前管理员隔离，应用不应把业务状态存入用户工作区（窗口内存态如选项卡列表本就不持久化）。
5. **偏好新增走完整链路**：任何新增偏好键按第八章四处同步，缺一处即静默失效。
6. **通知 link 必须真实**：推送通知与待办的 `link` 必须是 `admin_menus` 表中已存在的路径，避免点击退化为新标签页。
7. **静态资源本地化**：应用页面资源不通过 CDN 加载；`_component` 片段不重复引入主框架资源。

## 十一、版本与更新日志

| 版本 | 日期 | 更新人 | 说明 |
|---|---|---|---|
| 2.0.1 | 2026-10-05 | CmsPro | **账号菜单新增「清除缓存」（用户需求：修改密码下面增加清除缓存，点击后触发 /api/admin/cache/clear）**：① index.blade.php 账号菜单「修改密码」（L129）下新增 `<button data-action="clear-cache"><i class="fa fa-eraser"></i>清除缓存</button>`（锁定桌面之前）；② webos.js 面板点击分发（L7982-7988）新增 clear-cache 分支——`closePanels()` 后 `api('/api/admin/cache/clear')`（GET，路由取证：routes/web.php L226 `Route::get('cache/clear', [ConfigController::class, 'clearCache'])`，位于 api/admin 认证组），成功 `toast('缓存已清除', 'success')`，失败 toast 错误信息。**复用优先：未新建任何接口，直接复用系统后台既有缓存清理端点（与 webos 一贯原则一致）**。测试：账号菜单测试补 3 条断言（菜单项 + 分发分支 + 接口调用）；115 测试 1118 断言通过。文档同步：版本记录更新 |
| 2.0.0 | 2026-10-05 | CmsPro | **① 账号菜单新增「清除缓存」**：见前述记录（复用 GET /api/admin/cache/clear）。**② 系统菜单拖拽应用换顶级分类（用户需求：拖拽应用到顶级分类更新分类；超管专属；用户二次纠正：移动的是整个应用含应用文件夹，而非只移应用菜单）**：数据基础——flattenMenus 为叶子输出所属**应用文件夹节点 id**（`app_node_id`：walk 传递 `inheritedAppNodeId`，自带 app_id 的节点即应用文件夹节点、其 id 由后代叶子继承）；startItemsForGroup 卡片收集 `move_ids`——优先收应用文件夹节点 id（`moveIds.indexOf(entry.app_node_id)` 去重后 `push(Number(...))`，服务端只改父节点、子树随行整体移动），无节点的散叶子补自身 menu_id，目录卡片收 `moveIds.push(Number(representative.folder_id))`；渲染——renderStartMenu 按条件输出 `draggable="true" data-move-ids`（`isSuperAdmin && !item.special && move_ids.length`，搜索结果不启用）；交互——bindEvents 新增 document 级 dragstart/dragover/drop/dragend 委托（闭包变量 draggingMoveIds/dragOverCategory，dragover 高亮 `.is-drop-target`，「常用」groupId==='common' 排除）；提交——drop 后 `api('/api/admin/menus/move', { method:'PUT', body:{ ids, parent_id } })`，成功 toast + `refreshCatalog()`。**复用优先：移动接口直接复用系统 MenuController::batchMove（routes/web.php L276 `Route::put('move','batchMove')`），服务端已防环形引用（isDescendantOf）并按顶层节点移动防子树拍平，未新建任何接口**。**教训：① 编辑 bindEvents 大段代码时 old_string/new_string 截断过宽导致误删 action 处理块头部，node --check 通过但逻辑缺失——大块编辑必须逐段小编辑；② walk 签名加参时 old_string 末行参数数与实际不符导致编辑失败——多参数函数修改前后必须核对调用点参数个数；③ 断言字符串随实现细节联动（数组字面量改 push 调用后旧断言 MISS）——改实现后用临时 php -r/脚本批量 strpos 校验所有新断言再跑测试**。测试：11 条断言（app_node_id 输出/传递 + 节点优先收集 + draggable 条件 + move 接口 + refreshCatalog + CSS 高亮）；115 测试 1129 断言通过。**③ 修复线上 all-todos 接口 500**：见前述记录（子类 + method_exists 降级兜底）。**④ 移动后数据实时性（用户反馈：移动完菜单后需要刷新使数据最新）**：前端 refreshCatalog 已重拉 menus/user + apps 并重绘开始菜单/桌面/任务栏，服务端 batchMove 事务直改库无缓存——剩余风险是浏览器对同 URL GET 的 HTTP 缓存复用；loadCatalog 的两个 GET 统一附加 `?_t=Date.now()` 时间戳破坏缓存（顺带覆盖应用安装后的 refreshCatalog）。**教训：断言字符串与实现细节强联动（api('url') 改拼接后 2 条旧断言 MISS）——改实现后先批量 strpos 校验相关断言再跑全量测试**。测试：补 cacheBust 断言、更新 2 条 GET 断言；115 测试 1130 断言通过。**⑤ 标题栏刷新按钮支持特殊窗口（用户反馈：非应用窗口点击刷新无响应）**：根因——refresh 分支只处理有 tabs 的窗口（`refreshTarget.tabs && refreshTarget.tabs.length`），特殊窗口（应用中心/OS 设置/通知中心/官网动态，windowState.tabs = null）不命中任何分支静默返回。修复：补 else-if 分支——清 `host.dataset.pageToken` 后 `renderWindowPage(refreshTarget, refreshTarget.entry)` 强制重建整页（页面令牌相同会被 renderWindowPage 的 token 判定直接 return 跳过，必须先清），并重新触发各 special 窗口的数据渲染（renderAppCenter+loadUpdateCount / renderWebosSettings / renderNotificationCenter+loadNotifications / renderOfficialNews，保留当前 tab 状态）。**教训：新增「窗口级动作」时要枚举窗口形态（多选项卡/单页/无页面 special）逐一覆盖，条件分支的 else 路径不能静默——静默 return 是「按钮无响应」类反馈的最常见根因**。测试：补 3 条断言（else-if 分支 + pageToken 清空 + settings 重渲染）；115 测试 1133 断言通过。文档同步：版本记录更新 |
| 1.9.105 | 2026-10-05 | CmsPro | **修复线上 all-todos 接口 500（Call to undefined method App\Services\NotificationService::allTodos() at WebosController.php:73）**：根因——接口依赖全局框架服务 `app/Services/NotificationService.php` 上新增的 `allTodos()` 公开包装（内部仅转发 protected `aggregateTodoEntries()`），该框架文件未随应用部署到线上 → 线上调用未定义方法。修复（架构规范化）：① 新增应用内子类 `app/Apps/CmsproWebos/Services/WebosNotificationService.php`（extends NotificationService，`allTodos()` 公开 protected 聚合能力，容器自动解析 HookManager 依赖）；② WebosController::allTodos 改用 `app(WebosNotificationService::class)`，移除全局 `use App\Services\NotificationService`（已无代码引用）；③ 本地全局文件的 allTodos 保留（属未提交的框架改动，由用户决定提交/部署时机），但应用不再依赖——**部署应用目录即可修复线上**。**教训：应用依赖的框架级新增方法必须放在应用内（子类/装饰器），直接改全局服务会造成「本地能跑、线上必炸」的部署陷阱；通知/待办等跨切面能力的公开入口要区分「框架原有」（todos/panel）与「应用私需」（allTodos），后者下沉到应用**。测试：补 4 条断言（子类文件内容 + 控制器引用子类 + 不再引用全局 allTodos）；115 测试 1113 断言通过。文档同步：版本记录更新 |
| 1.9.104 | 2026-10-05 | CmsPro | **修复刷新按钮位置（用户反馈：无选项卡条窗口的刷新按钮跑到「收起左侧菜单」旁，未在 window-brand 右侧）**：根因——1.9.101 把按钮挂在 `.window-controls` 内，无选项卡条时控制区 `margin-left: auto` 右推，按钮随之贴右；1.9.103 的 order 方案只在有选项卡条时生效（order 是 controls 内部排序，管不到 controls 在标题栏的位置）。修复：按钮**移出控制区**，作为标题栏直属子元素插到 `.window-brand` 之后（新类 `.window-refresh`，CSS 仅 margin-left:4px）——标题栏 flex 顺序 brand → 刷新 → controls，无论有无选项卡条按钮恒居品牌区右侧；有选项卡时选项卡条（controls 内 afterbegin 插入）从按钮右侧展开。**安全性确认：bindWindowGestures 的拖动/双击最大化排除逻辑是 `event.target.closest('button')`（所有按钮，不限控制区），刷新按钮移出 controls 后不会误触拖动与双击最大化**。**教训：浮动元素的「恒定位置」不能依赖容器内排序（order/DOM 首位）——容器自身会因状态（margin-left:auto 右推、afterbegin 插序）位移，要提到容器外用外层 flex 顺序锚定**。测试：更新 2 条断言（新类名正则 + .window-refresh CSS 规则）；115 测试 1109 断言通过。文档同步：版本记录更新 |
| 1.9.103 | 2026-10-05 | CmsPro | **修复标题栏刷新按钮位置（用户反馈：按钮在多选项卡右侧，应在左侧）**：根因——syncWindowTabsBar 动态将选项卡条/溢出导航 `insertAdjacentElement('afterbegin')` 插到 `.window-controls` 最前，把 1.9.101 放在 markup 首位的刷新按钮挤到选项卡右侧（DOM 首位 ≠ 视觉首位）。修复：CSS order 方案——webos.css 在 `.window-controls` 规则后补 `.window-controls [data-window-action="refresh"] { order: -2; }`（flex 容器内 order 提到所有 tabs/nav 之前），刷新按钮恒居选项卡条左侧，JS 零改动。**教训：动态 afterbegin 插入会让「markup 首位」失去位置意义，标题栏这类动态插序的 flex 容器用 order 控制视觉次序**。测试：补 1 条 CSS order 正则断言；115 测试 1109 断言通过。文档同步：版本记录更新 |
| 1.9.102 | 2026-10-05 | CmsPro | **修复右键菜单点击 iframe 内容不关闭（用户反馈：选项卡右键菜单打开后点击 iframe 内任何位置菜单残留）**：根因——iframe 是独立文档，其内部点击**不会冒泡到父文档**，父文档 document click 委托末尾的 `closeDesktopContextMenu()` 等关闭调用收不到事件。修复：bindEvents 中 document click 委托注册后补 `window.addEventListener('blur', function () { closeDesktopContextMenu(); closeTaskbarContextMenu(); closeAppRowMenus(); })`——点击 iframe 内容会使父窗口失焦（blur），借此关闭所有父文档浮动菜单；桌面/任务栏/应用行菜单同语义顺带覆盖。**教训：父文档的统一 click 关闭监听覆盖不了 iframe 内部点击（独立文档树），凡是「点击其它区域关闭」的浮层都要考虑 blur 兜底或透明遮罩方案**。测试：补 1 条 blur 监听正则断言；115 测试 1108 断言通过。文档同步：版本记录更新 |
| 1.9.101 | 2026-10-05 | CmsPro | **应用窗口标题栏新增刷新按钮（用户需求：window-brand 右侧增加刷新按钮，点击刷新当前显示内容，同选项卡右键「刷新页面」）**：① buildWindowMarkup 的 window-controls 最前新增 `data-window-action="refresh"` 按钮（fa-refresh，aria-label/title「刷新当前页面」），`windowTabsEnabled() ? ... : ''` 条件渲染——仅选项卡模式显示，非选项卡单页模式不渲染无功能按钮；② 标题栏点击分发（L8073-8081）新增 refresh 分支——`state.windows.get(key)` 取 windowState，激活 tab 反查（activeTabId find 兜底 tabs[0]）后复用 reloadWindowTab（清 pageToken 强制 renderWindowPage 重建，iframe 重设 src/组件重新拉取）。测试：补 3 条断言（markup 条件渲染正则 + 分发分支正则 + data 属性字面量）；115 测试 1107 断言通过。文档同步：版本记录更新 |
| 1.9.100 | 2026-10-05 | CmsPro | **开始菜单应用卡图钉与名称优化（用户需求：添加到桌面/从桌面移除默认不显示、悬停显示；应用名显示更长）**：webos.css 两处——① `.pin-button` 取消 `.is-pinned` 常显选择器（原 L1458-1460 hover 组含 `.pin-button.is-pinned`），「添加到桌面/从桌面移除」统一 hover/focus-within 浮动显示；is-pinned 补主题色 `color: var(--webos-primary)` 作状态配色；② `.start-app-item` `padding: 10px 34px 10px 10px` → `10px 10px`（图钉绝对定位浮动不占文档流），`.start-app-item-text strong/small` 保持 ellipsis，名字可显示宽度 +24px。**CSS Edit 教训：替换选择器行时严禁把新选择器组误写进原属性块——本次曾把 `.pin-button {` 基选择器误替换为 hover 组导致「永远显示」，立即发现并恢复（基规则默认隐藏 + hover 规则浮动显示，两块必须分开）**。测试：纯 CSS 改动，115 测试 1104 断言通过。文档同步：版本记录更新 |
| 1.9.99 | 2026-10-05 | CmsPro | **OS 设置→系统设置新增「点击菜单进入」（用户需求：默认当前系统菜单，可选进入全部功能）**：① 新布尔偏好 `menu_open_launcher`（默认 false）三层同步——WorkspaceService DEFAULT_PREFERENCES + sanitizePreferences（bool 强转）、WebosController 验证规则 `preferences.menu_open_launcher` sometimes|boolean、前端 normalizePreferences；② systemSectionMarkup 新增开关卡「点击菜单进入 / 直接进入全部功能」（data-toggle-webos-setting 复用既有切换管线，零新增管线代码）；③ bindEvents 的 startButton 点击分支：`menu_open_launcher === true` 时 closePanels() + openLauncherDialog() 直接进启动台，否则 togglePanel('start') 打开系统菜单面板。测试：静态断言 7 条（三层 + 开关卡 + 点击分支正则）+ HTTP 持久化测试 1 条；115 测试 1104 断言通过。**Edit 教训：向既有测试方法后插入新方法时，old_string 锚点若截断原方法尾部（方法内还有后续断言段）会把原代码悬空在方法外——插入前先读完整方法体（到下一个 function 声明为止），锚点取「方法完整结尾 + 空行 + 下一方法注释」**。文档同步：版本记录更新 |
| 1.9.98 | 2026-10-05 | CmsPro | **开始菜单底部栏调整（用户需求：退出登录移到锁定右边；OS 设置只保留图标）**：desktop/index.blade.php L65-74 footer（.start-system-actions）按钮顺序 锁定 → 退出登录 → 版权 → OS 设置——退出登录上移至锁定后，版权 span（flex:1）仍居中撑开；OS 设置按钮去掉「OS 设置」文字仅留 fa-cog 图标，补 `aria-label="OS 设置" title="OS 设置"`（无障碍与悬停提示）。Blade 服务端渲染无需同步 public；测试补 2 条正则断言（footer 按钮顺序 + OS 设置纯图标形态）。114 测试 1093 断言通过。文档同步：版本记录更新 |
| 1.9.97 | 2026-10-05 | CmsPro | **启动台空白区域点击关闭（用户需求：点击非功能、非搜索区域自动关闭）**：根因——1.9.86 全屏化后 `.launcher-panel` 100%×100% 铺满浮层，原关闭判定 `if (event.target === layer)`（精确命中浮层根节点）在 panel 内的空白点击永不命中。修复：点击委托末尾（folder/leaf/crumb/close 四分支之后）统一判定 `if (!event.target.closest('.launcher-item') && !event.target.closest('.launcher-search')) { closeLauncherDialog(); }`——点击目标不在功能卡片、不在搜索框内即关闭；卡片/面包屑/关闭按钮在上方分支已 return 不受影响，Esc 关闭保留。**教训：全屏浮层的「点击遮罩关闭」不能用 target === 根节点判定（panel 铺满后根节点区域为零），要用反向排除法（closest 排除交互区域）**。测试：补 1 条正则断言；114 测试 1091 断言通过。文档同步：版本记录更新 |
| 1.9.96 | 2026-10-05 | CmsPro | **启动台分组卡片点击直接打开应用窗口（用户需求：就到应用，点击不要进入子菜单，直接打开应用窗口）**：launcherFolderMarkup 中有 application 关联的分组卡片，取该应用在 flatMenus 中的**代表叶子**（`state.flatMenus.find(candidate => candidate.app_id === application.app_id)`，树序第一个可打开菜单），卡片 data 属性改输出 `data-launcher-leaf="menu-{menu_id}"`——复用叶子点击链路（委托 → findEntry → openMenuByType/openEntry → 关闭浮层）直接进应用；无代表叶子兜底 data-launcher-folder，纯目录分组（无 app_id，如「管理」「系统」顶级分类）保持进入子目录。交互与开始菜单应用卡（start_item 点击打开 representative）完全一致。**设计取舍：分组卡片不再进入子菜单，子菜单通过应用窗口内左侧导航访问**。测试：补 2 条正则断言（representative 查找 + action 分支）；114 测试 1090 断言通过。文档同步：版本记录更新 |
| 1.9.95 | 2026-10-05 | CmsPro | **修复：启动台分组卡片图标与系统菜单不一致（用户第三次反馈，本次通过数据实锤真根因）**：诊断链——① 直查 admin_menus 表：「管理」下顶层节点**绝大多数自带 app_id**（儿康管理=cmspro.childrehab 等），1.9.93 的「深层叶子 app_id 为空」根因判断**错误**；② 线上 `/api/app/cmspro.captcha/icon` 与本地 CmsproCaptcha/icon.png MD5 一致（白底绿盾），图1 中「验证码设置」已显示该图 = **叶子链路 1.9.93 已生效**；③ Clineproxy/icon.png（黑底白机器人）与图2 开始菜单「Cline」图标一致 = 开始菜单卡片即各应用 icon_url 图片；④ **真正的差异源：启动台「管理」下 24 张卡片中 22 张是分组节点（children>0），走 launcherFolderMarkup 纯 safeIcon 字体渲染从不查应用图标**——只有「验证码设置」「论坛设置」两张叶子（children=0）走 launcherLeafMarkup 显示图片（恰与截图吻合：图1 仅两张彩色图片其余全字体）。开始菜单则由 startItemsForGroup 把带 app_id 分组聚合为应用卡片（entryIconMarkup → 图片）、无 app_id 的（前台用户/内容管理，admin_menus 中 app_id 为 null）走 is-folder 黄底字体卡片。修复：launcherFolderMarkup 与叶子同标准——带 app_id 走 applicationIconMarkup 应用图片；无应用输出 `launcher-item-icon is-folder-icon`（CSS：background: var(--webos-folder) #f0a11a + 白字，与开始菜单目录卡片一致）。**教训：截图证据要逐卡片核对（数清楚哪些卡片是图片哪些是字体、对应菜单树节点的 children/app_id），不要被「整屏都不对」的印象带偏；渲染不一致问题先分层定位（数据层 app_id → 接口层字段 → 渲染层函数分支 → CSS 底色），每一层用独立证据实锤**。114 测试 1088 断言通过。文档同步：版本记录更新 |
| 1.9.94 | 2026-10-05 | CmsPro | **启动台浮层右上角关闭按钮（用户需求：出现全部功能时右上角增加关闭按钮）**：① openLauncherDialog 的 layer.innerHTML 首节点插入 `<button class="launcher-close" data-launcher-close><i class="fa fa-times"></i></button>`（aria-label="关闭"）；② 浮层点击委托最前加 `data-launcher-close` 分支调 closeLauncherDialog()（closest 判定，点中按钮内部图标同样命中）；③ CSS `.launcher-close` absolute 悬浮右上角（22/26px、38px 圆形、半透明白底、hover 加亮、focus-visible 焦点环），与 Esc/点击遮罩空白并列的显式关闭入口。测试：补 markup 与委托分支 2 条正则断言；114 测试 1086 断言通过。文档同步：版本记录更新 |
| 1.9.93 | 2026-10-05 | CmsPro | **修复：启动台叶子图标仍与系统菜单不一致（用户双截图对比：全部功能全是单色菜单字体图标，系统菜单是彩色应用图片图标）**：根因——launcherLeafMarkup 用菜单树**原生节点的 item.app_id** 查找应用，但菜单树里只有顶层节点带 app_id（如「验证码」），深层叶子（「验证码管理」）为 null → application 恒 null → 走 safeIcon 菜单图标回退；而开始菜单聚合用 flattenMenus **继承后**的 app_id（flattenMenus walk：`item.app_id \|\| inheritedAppId`）所以正常。「论坛设置」恰好在树上有自身 app_id 显示正常（该反例曾误导 1.9.92 认为链路正确）。修复：① 新增 `findFlatLeaf(menuId)` 按菜单 id 从 state.flatMenus 取平铺叶子（app_id 含祖先继承，与开始菜单聚合同源）；② 渲染改用 **entryIconMarkup**（开始菜单应用项 L777 同一个函数：findApplication → applicationIconMarkup / safeIcon 回退）；③ 搜索的应用名匹配同步改继承 app_id。**教训：跨「菜单树节点」与「flattenMenus 平铺叶子」取数必须对齐字段语义——flattenMenus 的 app_id 有祖先继承，原生树节点没有**。**插曲：manifest.json 曾被上一轮 PowerShell `Set-Content -Encoding UTF8` 写入 UTF-8 BOM（PS5.x 行为）导致后端 json 解析失败、页面标题 WebOS 版本号输出为空（test_admin_can_open_webos_desktop 暴露）——Windows 下写 UTF-8 文件一律用 `[System.IO.File]::WriteAllText($p, $text, [System.Text.UTF8Encoding]::new($false))`，禁用 Set-Content -Encoding UTF8**。114 测试 1084 断言通过。文档同步：版本记录更新 |
| 1.9.92 | 2026-10-05 | CmsPro | **启动台叶子图标回归开始菜单标准（用户反馈：显示的图标和系统菜单右侧不一致，要以系统菜单为标准）**：launcherLeafMarkup 弃用 1.9.91 的服务端接口链，改回与开始菜单（系统菜单）同链路——同一查找 `state.catalog.applications.find(...)` + 同一渲染 `applicationIconMarkup(application, 'launcher-item-icon')`（catalog 链：icon_url 图片 → icon 字体）；无应用关联的叶子回退菜单自身图标 safeIcon。**更正 1.9.91 教训：应用图标渲染的「标准」是开始菜单的 catalog 链，data-app-icon-* 全局链只是图片加载失败的回退机制；recordIcon 接口主图链仅用于安装记录日志**（1.9.93 进一步修正：app_id 须取 flattenMenus 祖先继承值，并复用 entryIconMarkup）。测试断言踩坑：applicationIconMarkup 的 `is-app-icon` 是运行时拼接 `className + ' is-app-icon`（JS 字面量单引号闭合在 `src="` 之后）——断言字符串必须按源码实际拼接形态写（php -r strpos 验证），不能按「应该长什么样」脑补。114 测试 1083 断言通过。文档同步：版本记录更新 |
| 1.9.91 | 2026-10-05 | CmsPro | **启动台叶子图标改走全局图标链（用户反馈：按 icon.svg → icon.png → manifest.json → icon 顺序显示）**：launcherLeafMarkup 弃用 applicationIconMarkup（依赖 catalog 的 icon_url 字段，目录未加载/缺失时回退过早）——改为与 recordIcon 同链路：主图 `<img data-app-icon-primary src="/api/app/{id}/icon">`（服务端按 icon.svg → icon.png 顺序返回）→ 失败由全局回退委托（webos.js 8450 附近 error 监听，基于 parentNode 找 data-app-icon-fallback/final）降级到 manifest.json 的 icon 字段（市场来源经 marketIconUrl 补全）→ 最终回退菜单自身图标（data-app-icon-final）；无 app_id 的叶子直接显示菜单图标。容器 span 固定带 `is-app-icon`（img 等比约束）。**教训：应用图标渲染一律走 data-app-icon-* 全局链 + 服务端图标接口，不依赖 catalog 字段加载状态**（1.9.92 推翻：与系统菜单显示不一致，标准回归 catalog 链）。114 测试 1082 断言通过。文档同步：版本记录更新 |
| 1.9.90 | 2026-10-05 | CmsPro | **启动台重构为菜单树层级导航（用户四点反馈：去毛玻璃 / 系统目录要文件夹形式 / 要含系统目录与系统菜单一致 / 按顶级分类）**：① 数据源从 `state.catalog.applications` 平铺改回 `state.catalog.menus` 菜单树——`renderLauncherView(layer, path, query)` 按索引路径逐层渲染：目录节点（有 children）→ 文件夹卡片 `launcherFolderMarkup`（`safeIcon(item.icon \|\| 'fa fa-folder')` 目录图标优先文件夹兜底），叶子（openablePath）→ `launcherLeafMarkup`（有 app_id 用 applicationIconMarkup 应用图标，无应用回退菜单图标）；点击叶子 `findEntry('menu-'+id)` → `openMenuByType(entry) \|\| openEntry(entry)` 后关闭浮层（与窗口菜单/桌面图标打开链路一致）。② 面包屑 `.launcher-crumbs`（全部功能 / 一级 / 子级，点任意层级返回）。③ 搜索：`collectLauncherLeaves` 递归收集全部叶子，按菜单名/应用名匹配平铺。④ **移除 backdrop-filter 毛玻璃**（低配设备全屏模糊渲染开销大），遮罩加深为 rgba(9,20,30,0.92)。测试断言同步替换 4 条旧平铺断言；114 测试 1081 断言通过。文档同步：版本记录更新 |
| 1.9.89 | 2026-10-05 | CmsPro | **修复：「刷新页面」点击无反应（用户反馈）**：runDesktopContextAction 的选项卡动作分发白名单只认 `tab-close/tab-close-others/tab-close-all`，1.9.87 新增菜单项时**漏把 tab-reload 加进外层分发**——点击被丢弃，runWindowTabContextAction 从未被调用。修复：分发条件补 `action === 'tab-reload'`。**教训：右键菜单新增动作项必须同步两处——openXxxContextMenu 菜单数组 + runDesktopContextAction 分发白名单**（已加防回归断言）。114 测试 1076 断言通过。文档同步：版本记录更新 |
| 1.9.88 | 2026-10-05 | CmsPro | **双击标题栏切换最大化/还原**：bindWindowGestures 中 titlebar 拖动监听后追加 `dblclick` 监听——`target.maximized ? restoreWindow(key) : maximizeWindow(key)`，`event.target.closest('button')` 排除控制区按钮（最小化/最大化/关闭/前台菜单/选项卡条均为 button，双击不误触）。114 测试 1075 断言通过。文档同步：版本记录更新 |
| 1.9.87 | 2026-10-05 | CmsPro | **选项卡右键「刷新页面」**：openWindowTabContextMenu 菜单项数组首位插入 `['刷新页面', 'fa-refresh', 'tab-reload']`；新增 `reloadWindowTab(windowState, tab)`——清空页面容器 `dataset.pageToken` 强制 renderWindowPage 重建（iframe 重设 src / _component 重新 fetchText），行为对齐框架后台标题栏刷新按钮（layui-icon-refresh-1 重新加载当前页）；runWindowTabContextAction 加 tab-reload 分支。测试断言踩坑：跨 if 块的正则 `\s*` 匹配不了 `}`——多行断言要把中间的代码结构（大括号）纳入正则。114 测试 1074 断言通过。文档同步：版本记录更新 |
| 1.9.86 | 2026-10-05 | CmsPro | **启动台全屏 + 图片图标根治（用户截图二次反馈：仍堆叠）**：① 全屏化——`.launcher-layer` 改纵向 flex 铺满（`flex-direction: column` + `padding: 28px 44px 36px`），`.launcher-panel` 100%×100%（原 min(960px,100%)×min(640px,100%) 卡片废弃），搜索框 `flex: 0 0 auto` 顶部居中，`.launcher-grid` 加 `align-content: start`。② 堆叠根因：1.9.85 的约束选择器 `.launcher-item-icon.is-app-icon img` 依赖 `is-app-icon` 类，但该类是**各渲染位外层拼接**（entryIconMarkup/入口树），启动台直接调 `applicationIconMarkup(application, 'launcher-item-icon')` 没拼 → 选择器不匹配 → img 仍原始尺寸。**根治：is-app-icon 移入 applicationIconMarkup 图片分支源头声明**（所有调用位自动携带），删除 entryIconMarkup/入口树两处外层拼接。**教训：约束类挂在「源头渲染函数」而非「各调用点」，新增调用位才不会遗漏**。③ 调试插曲记录：hex 字节误读导致一度误判源码丢闭合引号（实际 `' is-app-icon"><img'` 是合法拼接），靠 node --check + MD5 比对回滚——**判断源码损坏必须以 node --check/解释器为准，人工 hex 推演易错**。测试断言同步 3 处；114 测试 1070 断言通过。文档同步：版本记录更新 |
| 1.9.85 | 2026-10-05 | CmsPro | **修复：启动台图片图标堆叠爆版（用户截图反馈）**：applicationIconMarkup 对图片图标（icon_url）输出 `<img>`，`.launcher-item-icon` 只约束了 44px 容器但 **img 无尺寸约束**——原图按原始尺寸渲染撑爆网格项相互覆盖（字体图标正常，因此呈「大小不一堆叠」状）。修复：`.is-app-icon img` 等比缩放规则组（width/height 100% + object-fit: contain）追加 `.webos-desktop .launcher-item-icon.is-app-icon img`（与桌面徽标/任务栏/开始菜单同一规则组），另加 `.launcher-item img { pointer-events: none }` 防原生拖拽打断点击（与桌面图标 126 行同理）。**教训：新增图标渲染位必须同步检查 img 形态约束，applicationIconMarkup 有字体/图片两种输出**。纯 CSS 改动，测试补 2 条断言；114 测试 1064 断言通过。文档同步：版本记录更新 |
| 1.9.84 | 2026-10-05 | CmsPro | **启动台改为应用级网格（用户反馈：不应平铺全部菜单项）**：renderLauncherGrid 数据源从 `state.flatMenus`（菜单叶子）改为 `state.catalog.applications`（与开始菜单「全部应用」同源）——过滤 `appHasMenu(app_id)`（无菜单应用点击无法打开）+ 应用名/app_id 即时搜索；网格项 = `applicationIconMarkup` 应用图标 + 应用名，点击复用既有 `data-open-app-id` 委托（追加 closeLauncherDialog() 后 openEntry(defaultEntryOf(appMenus)) 打开应用窗口默认菜单）。CSS 同步删除不再输出的 `.launcher-item small` 规则。测试断言踩坑：PHP 单引号串 `\n` 不转义（字面反斜杠）导致断言失配——跨行断言一律用 `\s*` 正则；一条长正则失配原因不明（hex 验证行内容正常）时改用已验证短正则并重跑全量。测试：114 测试 1062 断言通过。文档同步：版本记录更新 |
| 1.9.83 | 2026-10-05 | CmsPro | **图标别名兼容 +「全部功能」按钮位置纠正（用户反馈：改错了地方，指开始菜单 data-open-special="entries" 按钮）**：① 查库实锤 admin_menus.icon 有三种形态——fa-*/layui-icon 双类名/**iconPicker 短代码别名**（IconImage/IconUser 等，safeIcon 输出 `<i class="IconImage">` 无样式不显示）。修复：safeIcon 增加 `ICON_ALIASES` 映射（6 个别名 → layui 类名，与框架 `ConfigController::buildMenuTree` 的 iconMap 完全一致），safeIcon 是全部渲染点的唯一图标出口故一处修复全局生效。**教训：菜单图标不是「fa 类名」单一形态，iconPicker 别名是真实存量数据**。② 开始菜单按钮（index.blade.php#L60）「管理入口」→「全部功能」，JS 委托中 `openSpecial === 'entries'` 特例直接 `openLauncherDialog()`；其余 data-open-special 值仍走应用中心标签页。③ **回滚 1.9.82 误改**：应用行菜单恢复「管理入口」+ openEntryDialog（该弹窗与启动台是两个不同功能，均保留）。测试：114 测试 1059 断言通过。文档同步：版本记录更新 |
| 1.9.82 | 2026-10-05 | CmsPro | **目录图标修正 +「全部功能」启动台**：① 开始菜单目录项图标改为 `representative.folder_icon || group_icon || 'fa fa-folder'`（flattenMenus 已给目录 icon 兜底，startItemsForGroup 此前硬编码 fa-folder 丢失目录设置图标）；渲染处同步用 `item.start_icon` 不再硬编码。② 应用行菜单「管理入口」→「全部功能」（fa-th-large），action 分发改调 `openLauncherDialog()`——macOS 启动台风格浮层：`.launcher-layer`（毛玻璃 backdrop-filter，z-index 7500 低于锁屏 8000 高于窗口层）+ 顶部搜索框（title/group_title/app_id 即时过滤）+ `state.flatMenus` 全量网格（entryIconMarkup 复用应用图标），点击项走既有 `data-launch-id` 委托（追加 closeLauncherDialog()），Esc/点空白关闭。**同步清理**：删除不再被引用的 `openEntryDialog`（含其测试断言与 js 注释中的「管理入口」字样——assertStringNotContainsString('管理入口') 反向断言会命中注释，清理要连注释一起）。测试：114 测试 1053 断言通过。文档同步：版本记录更新 |
| 1.9.81 | 2026-10-01 | CmsPro | **修复：选项卡全部关闭后空状态引导残留（用户反馈）**：closeWindowTab 全部关闭时 `host.innerHTML = emptyState(...)` 直接写入 page-host，而 renderWindowPage tabs 分支对已存在页面用 `appendChild` 追加——空状态 div 不是 `.window-page` 容器，恢复选项卡时未被清除。修复：tabs 分支开头遍历 `host.children` 移除非 `.window-page` 子节点（正常多页切换时无副作用）。**教训：直接写入 host 的临时内容（空状态/加载占位）必须与页面容器的生命周期联动**。测试：补清理循环正则断言；113 测试 1044 断言通过。文档同步：版本记录更新 |
| 1.9.80 | 2026-10-01 | CmsPro | **前台菜单链接域名绑定兼容（用户反馈：论坛 /forum 域名绑定后前台链接无法访问）**：新增应用侧接口 `GET /admin/cmspro/webos/api/home-menu-urls?app_ids[]=`——按 app_id 返回 home 终端菜单「原始路径 → 访问地址」映射，**复用框架 `menu_path()`**（域名绑定 → 绑定域名根；普通应用 → main_url 主站地址；外链原样），与 admin.blade.php 前台菜单行为完全一致；app_ids 格式校验（`[A-Za-z0-9_.-]{1,100}`）+ 上限 50。前端 loadWindowHomeMenu 拿到菜单树后请求该接口合并 `leaf.url`（`leaf.url || leaf.path` 渲染），转换失败回退原始路径（不阻断菜单显示）；转换结果随 homeMenusCache 一起缓存。**踩坑提示**：AdminMenu 部分列查询必须带 `terminal_type` 列（menu_path 内部依赖 `$menu->terminal_type === 'home'` 判定，漏查则域名绑定分支静默失效）。为何不在框架菜单树接口转换：`/api/admin/menus/tree` 被菜单管理/应用管理等管理页共用，需保留原始 path 编辑语义。测试：新增 HTTP 链路（域名绑定断言域名根 + 普通应用断言主站地址 + 非法 app_id 过滤）与前端合并 2 个测试；112 测试 1041 断言通过。文档同步：版本记录更新 |
| 1.9.79 | 2026-10-01 | CmsPro | **官网动态读取条数 3 → 6**：官网接口 `api/home/moments/latest` 原生支持 `limit` 参数（1-20，默认 3——MomentApiController@latest 已定义），fetch URL 加 `?limit=6` 即可，**先查接口能力再改前端**（勿臆测接口无参数）。110 测试 1032 断言通过。文档同步：版本记录更新 |
| 1.9.78 | 2026-10-01 | CmsPro | **官网动态图片弹层预览（用户需求：点击图片最大化弹出层预览，多图可切换）**：图片点击由 window.open 改为 `layer.photos` 相册层——WebOS 已加载 layui（`layuiLayer()` 返回 window.layui.layer，checkWebosSelfUpdate 的 layer.confirm 同源），layer.photos 与框架后台 dashboard 的图片预览同一组件，内置左右切换/缩放自适应/关闭。实现细节：事件委托挂 `.official-news-images` 组容器（每条动态一组，组内多图 {src, thumb} 数组 + start 定位点击图索引）；**兜底**：layuiLayer() 为空时回退 window.open 新标签（layer 加载失败不阻断查看图片）。测试：补 2 条断言；110 测试 1032 断言通过。文档同步：版本记录更新 |
| 1.9.77 | 2026-10-01 | CmsPro | **官网动态窗口（用户需求：桌面最右侧 600×500 官网动态窗口，仅 super_admin）**：新增第 4 个特殊窗口类型（app-center/settings/notifications 之后）。要点：① `officialNewsEntry()` + `windowKey` 特判（无 app_id 的 entry 默认 key 会带 folder- 前缀，特殊窗口必须特判保持与 BUILTIN_WINDOW_KEYS 一致）；② windowMarkup 的 `isOfficialNews` 分支——sidebar/sidebarToggle 均空、windowBody 无侧栏（与 isSettings 同款）、content 为 data-official-news shell（无 page-host → 多选项卡判定自动排除）；③ 固定尺寸走 BUILTIN_WINDOW_KEYS + defaultWindowSize 双处（小屏 min 收窄防溢出），停靠位置在 openEntry 通用 left/top 计算后覆盖（left=layerRect.width-width-24 贴右、top 垂直居中）；④ 数据层复用官网公开接口 `https://www.cmspro.cn/api/home/moments/latest`（前端直连，无需应用侧路由/控制器/视图），escapeHtml → linkifyOfficialNews（链接化）顺序与 dashboard 一致；**异步渲染必须 isConnected 检查**（窗口可随时被用户关闭，防写已移除 DOM）；⑤ `isSuperAdmin` 门控在 initialize 桌面渲染完成后 openEntry（与 checkWebosSelfUpdate 同位置）；⑥ recordEntryUsage 排除（自动打开不计使用统计，避免每次进桌面多一次 saveWorkspace）。测试：6 条断言（**教训：断言前先确认既有变量名**，CSS 内容变量是 $stylesheet 不是自造的 $css）；110 测试 1030 断言通过。文档同步：版本记录更新 |
| 1.9.76 | 2026-10-01 | CmsPro | **选项卡右键菜单（用户需求：关闭当前/关闭其它/关闭全部）**：复用桌面右键菜单全套设施——容器 `#desktop-context-menu` + menuMarkupInto（items `[label, icon, action, danger, children]`，`data-desktop-id` 本为图标 id 但实为透传字符串，直接传选项卡 id）+ positionDesktopMenu + 点击后统一关闭（7969 行 closeDesktopContextMenu）。`runWindowTabContextAction` 遍历 state.windows 反查选项卡所在窗口（id 全局唯一）；**关闭其它注意点**：逐个 closeWindowTab 时激活页被关会触发相邻切换，收尾必须 `activeTabId !== tabId` 时 switchWindowTab 保住保留项激活；关闭全部直接复用逐个 closeWindowTab（最后一个激活页自动走空状态收尾）。右键监听挂 root（closest('[data-window-tab]') 委托）+ preventDefault + focusWindow 聚焦所在窗口（与左键点击行为一致）。分发入口：runDesktopContextAction 开头 tab 三分支（iconId 形参语义此时为 tabId）。测试：补 6 条断言；110 测试 1024 断言通过。文档同步：版本记录更新 |
| 1.9.75 | 2026-10-01 | CmsPro | **修复前台按钮被选项卡条隔开（用户反馈：前台应挨着「收起左侧菜单」）**：根因——`loadWindowHomeMenu` 的 inject 用 `controls.insertAdjacentHTML('afterbegin')` 固定插控制区最前；1.9.69 选项卡条（afterbegin）、1.9.72 溢出导航 prev（afterbegin）/next（bar afterend）注入后，前台按钮与 `.sidebar-toggle` 之间被隔开，且位置随调用时序漂移——openEntry 是 syncWindowTabsBar → loadWindowHomeMenu（前台跑到 prev 左侧最左），activateWindowEntry 是 loadWindowHomeMenu → syncWindowTabsBar（前台在 next 与 sidebarToggle 之间）。**教训：同一容器多路动态注入禁止用固定方位（afterbegin/beforeend），必须锚定兄弟元素插入**。修复：`anchor = controls.querySelector('.sidebar-toggle')`，`anchor.insertAdjacentHTML('beforebegin', windowHomeMenuMarkup(leaves))`，anchor 缺失回退 afterbegin。测试：补锚点两条断言；110 测试 1018 断言通过。文档同步：版本记录更新 |
| 1.9.74 | 2026-10-01 | CmsPro | **修复溢出导航箭头偏上（用户反馈截图：< > 按钮高于选项卡条中线）**：根因——`.window-controls` 是默认 stretch 的 flex 容器：`.window-tabs` 无固定高参与拉伸、内部 `align-items: center` 使选项卡垂直居中；而 `.window-tabs-nav` 固定 `height: 28px` 不参与 stretch，默认停在交叉轴起点（顶部）偏上。修复：`.window-tabs-nav` 补 `align-self: center`（居中对齐，语义化方案，优于硬编码 margin-top 下移）；110 测试 1016 断言通过。文档同步：版本记录更新 |
| 1.9.73 | 2026-10-01 | CmsPro | **品牌区副标题跟随当前菜单（用户需求：window-brand 中的 small「控制面板」随菜单切换变化）**：1.9.71 为满足「应用名固定」把品牌区整块固化（strong 与内嵌 small 都不变），本次拆分——主标题应用名仍固定，新增 `syncWindowTabSubtitle(windowState, title)`：`strong.querySelector('small')` 后仅改 `textContent`（安全转义、不重写主标题 DOM）。四处调用：switchWindowTab（`tab.title`，makeWindowTab 已存菜单名）、closeWindowTab 相邻切换（`next.title`）、closeWindowTab 全部关闭（传 `''` 清空副标题，品牌区仅留应用名）、activateWindowEntry tabs 分支（`existing.title`，新开/复用路径统一）。单页模式不变（既有整块重写逻辑中 strong=identity.title 本就固定、small=entry.title 本就跟随）。测试：补 `function syncWindowTabSubtitle` 与 `substr_count` 5（4 调用 + 1 定义行同模式匹配）断言；110 测试 1016 断言通过。文档同步：版本记录更新 |
| 1.9.71 | 2026-10-01 | CmsPro | **修复品牌区应用名随菜单变化（用户反馈：window-brand 应用名称固定）**：根因——1.9.68 的 `syncWindowTabBrand()` 在 switchWindowTab/closeWindowTab/activateWindowEntry tabs 分支三处把 tab.title（菜单名）写入 `.window-brand strong`。修复：删除该函数与三处调用，品牌区仅窗口创建时渲染一次（strong=windowIdentity().title 应用名、small=入口名副标题），当前菜单名由选项卡条高亮展示；单页模式品牌重写行为不变（其 strong 本就是应用名）。测试：补 `assertStringNotContainsString('syncWindowTabBrand')` 防回归；110 测试 1009 断言通过。文档同步：版本记录更新 |
| 1.9.70 | 2026-10-01 | CmsPro | **修复按钮不贴右 + 关闭按钮报错（用户反馈两点）**：① 选项卡模式下收起左侧菜单/最小化/最大化/关闭按钮未贴最右——1.9.69 只取消了 `margin-left: auto`，但控制区作为 titlebar 的 flex 子项宽度仍是内容宽（auto），内部 `.window-tabs` 的 flex:1 没有剩余空间可分配，tabs 不撑开；修复：`.app-window.has-window-tabs .window-controls` 补 `flex: 1` 占满品牌区之后的剩余空间，tabs 从左侧撑满、窗口按钮始终贴最右。② 点击窗口关闭报 `Uncaught TypeError: closeWindow is not a function`——**var 声明提升遮蔽陷阱**：选项卡事件委托中 `var closeWindow = tabButton.closest('[data-window-key]')`（tab 关闭分支的宿主窗口局部变量）在同一 document click 函数作用域内提升并遮蔽了同名全局函数 `closeWindow`，导致窗口关闭分支 `closeWindow(key)` 调用的是 undefined 局部变量；`var switchWindow` 同理有潜在风险（幸无同名函数）。修复：局部变量统一重命名 `tabHostWindow`（两分支互斥共用），并加注释警示「局部变量不得命名为 closeWindow/switchWindowTab 等，var 提升会遮蔽同名全局函数」。测试：test_window_tabs_preference_and_titlebar_tab_bar 补 `assertStringNotContainsString('var closeWindow =')` 防回归；110 测试 1008 断言通过。文档同步：版本记录更新 |
| 1.9.69 | 2026-10-01 | CmsPro | **修复多选项卡条贴右侧（用户反馈：选项卡应从左侧开始）**：根因——`.window-controls { margin-left: auto }` 把整个控制区（含首位的选项卡条）推到标题栏右端。修复：① `syncWindowTabsBar` 维护窗口根元素标记类——有选项卡 `classList.add('has-window-tabs')`、全部关闭移除；② CSS 新增 `.app-window.has-window-tabs .window-controls { margin-left: 0 }` 取消右推，`.window-tabs` flex:1 从标题栏左侧撑满、minimize/maximize/close 按钮自然靠右；③ 全部选项卡关闭后移除类恢复按钮贴右原状（无选项卡窗口不受影响）。测试：test_window_tabs_preference_and_titlebar_tab_bar 补 classList.add/remove('has-window-tabs') 与 `.app-window.has-window-tabs .window-controls` CSS 断言；110 测试 1006 断言通过。文档同步：版本记录更新 |
| 1.9.68 | 2026-10-01 | CmsPro | **应用窗口多选项卡（用户需求：OS 设置开关，默认关闭；类似传统后台 admin.blade.php 的多选项卡）**：① 偏好 `window_tabs` 三层同步（1.9.67 教训落地）——WorkspaceService `DEFAULT_PREFERENCES` 加 `'window_tabs' => false`、`sanitizePreferences` 加 `(bool)` 校验、WebosController::updateWorkspace 验证规则加 `'preferences.window_tabs' => ['sometimes', 'boolean']`；前端 normalizePreferences 同步默认值。② OS 设置 systemSectionMarkup 新增开关卡（settings-card + fa-clone + data-toggle-webos-setting="window_tabs"，走既有 toggleWebosSetting 开关链路）。③ webos.js 核心实现（插在 loadComponentPage 之后）：`windowTabSeq`/`makeWindowTab()`（tab 记录 id/title/path/openType——**窗口内存态不持久化**，关闭窗口即消失）、`windowTabsEnabled()`、`windowActiveTab()`（无激活回退最后一个）、`syncWindowTabsBar()`（选项卡条注入 `.window-controls` afterbegin，收起左侧菜单按钮左侧）、`syncWindowTabBrand()`（window-brand strong 显示当前 tab 标题、small 保持应用身份副标题）、`switchWindowTab()`（复用既有容器不重载）、`closeWindowTab()`（移除 .window-page 容器，激活页关闭后 `Math.min(index, length-1)` 相邻优先切换，全部关闭 emptyState 引导）。④ `renderWindowPage` 双模式：tabs 分支在前——每 tab 独立 `.window-page` 容器 + `dataset.pageToken` 加载令牌（`'_component:'+path` / `'_iframe:'+path`），首次创建后切换仅 `node.hidden = node.dataset.windowPage !== tab.id` 改显隐，**页面状态保留**（表单输入/滚动位置/iframe 不重载），末尾 `syncWindowShields()` 补挂 iframe 遮罩；单页现状逻辑保留在后。⑤ 接入点：openEntry 窗口创建（`windowTabsEnabled() && windowElement.querySelector('[data-window-page-host]')` 判定启用——**排除应用中心/OS 设置/通知中心等无 page-host 的特殊窗口**；windowState 初始 `tabs: null, activeTabId: ''`）、activateWindowEntry tabs 分支（同路径 `tab.path === entry.path` 复用既有选项卡否则 makeWindowTab 新开，rerenderWindowNav 重绘左侧菜单高亮 + loadWindowHomeMenu 同步前台菜单）、事件委托（先 `closest('[data-window-tab-close]')` 后 `closest('[data-window-tab]')`——关闭叉嵌套在 tab 按钮内必须先判内层，再 `closest('[data-window-key]')` 定位窗口）。⑥ CSS：`.window-tabs`（flex:1 min-width:0 overflow-x auto + 滚动条隐藏）、`.window-tab`（inline-flex max-width 168px，span ellipsis 截断，is-active #087f75 底白字）、`.window-tab-close`（opacity .55 hover 加深）、`.window-page`（100% 尺寸）+ **`.window-page[hidden] { display: none }`**（复用 1.9.59 hidden 教训：显式声明防容器 display 规则覆盖）、`.window-controls` 加 min-width:0（flex 子项防溢出）。测试：新增 test_window_tabs_preference_and_titlebar_tab_bar（偏好三层+开关卡+核心函数+双容器/hidden 切换/关闭叉/CSS 断言）+ test_workspace_api_persists_window_tabs_preference（HTTP PUT true → 再读 true）；110 测试 1003 断言通过。附带修正：test_window_home_menu_dropdown_injected_when_app_has_home_menus 的旧挂载点断言 `loadWindowHomeMenu(state.windows.get(key));` 随 openEntry 改造（windowState 局部变量）失效，更新为 `loadWindowHomeMenu(windowState);`（三处挂载点统一形式）。文档同步：版本记录更新 |
| 1.9.67 | 2026-10-01 | CmsPro | **修复右键「查看」勾选不跟随选择（用户反馈）**：排查链路——前端切档分支（state 更新+saveWorkspace）、点击委托（closest data-desktop-action 分发）、服务端 sanitizePreferences（Arr::only 白名单+枚举校验）均正确；实锤根因在 WebosController::updateWorkspace 的 Laravel 验证规则未声明 `preferences.icon_size`——`$validated` 只保留有规则声明的键，该键在控制器层被丢弃，saveForAdmin 的 incoming 白名单取不到 → 存量 medium 入库 → 前端 saveWorkspace 响应整体覆盖 state（state.workspace = workspace）→ desktopIconSize() 回退中档 → 勾选错误。修复：验证规则补充 `'preferences.icon_size' => ['sometimes', 'in:small,medium,large']`（1.9.65 遗漏：只补了 WorkspaceService 与 DEFAULT_PREFERENCES，漏了控制器规则层）。教训：**新增偏好字段需同步三层——DEFAULT_PREFERENCES、sanitizePreferences、updateWorkspace 验证规则（缺一即被 $validated 丢弃）**。测试：新增 test_workspace_api_persists_icon_size_preference（HTTP PUT icon_size=large 持久化断言 + getJson 再读验证 + 非法值 giant 返回 40201）；108 测试 978 断言通过。文档同步：版本记录更新 |
| 1.9.66 | 2026-10-01 | CmsPro | **版权信息与动态版本标题（用户需求：对齐框架后台页脚文案）**：① WebosController 新增 `manifestVersion()`（读 app/Apps/CmsproWebos/manifest.json 的 version 字段），index() 视图数据新增 `cmsproVersion => system_version()`（框架动态版本函数，与 admin.blade.php 页脚同源）与 `webosVersion => manifestVersion()`；② Views/Admin/desktop/index.blade.php：`<title>` 改为 `CMSPRO v{{ $cmsproVersion }} · WebOS v{{ $webosVersion }}`（动态双版本）；开始菜单 start-system-actions 中锁定与退出登录之间插入 `<span class="start-copyright">`（CMSPRO/www.cmspro.cn + v{系统版本} + © 2015-{{ date('Y') }} 动态年份 + Holley/www.renhuali.cn，与 layouts/admin.blade.php#L203-204 完全一致，保留链接与 target=_blank + rel=noopener）；③ webos.css 新增 `.start-copyright`（flex:1 居中、12px 灰色、nowrap+ellipsis 防溢出、链接 hover 主题色）；④ 测试：test_admin_can_open_webos_desktop 改为真实渲染断言——标题正则 `CMSPRO v\d+\.\d+\.\d+ · WebOS v\d+\.\d+\.\d+`、版权年份 `© 2015-{date('Y')}`；107 测试 971 断言通过。文档同步：版本记录更新 |
| 1.9.65 | 2026-10-01 | CmsPro | **桌面右键「查看」子菜单（大/中/小图标，默认中档；用户需求）**：① webos.js 顶部新增 `DESKTOP_ICON_SIZES` 三档格子常量（large 128x130/iconW 112、medium 102x104/88、small 86x88/72——中档与历史布局完全一致保证「默认中图标（当前）」）与 `desktopIconSize()`（非法值回退中档）；② renderDesktop 布局定位与拖拽（move 边界/end 落点）换算改为按档位，`root.dataset.iconSize` 同步到根节点驱动 CSS 视觉覆盖（`.webos-desktop[data-icon-size=…]` 三档 badge/按钮盒/label 字号）；③ `menuMarkupInto` 扩展 item[4] 子菜单支持：每项包 `.desktop-context-group`、父项 `has-children`（FontAwesome 尾箭头 ::after）、子菜单 `.desktop-context-submenu` hover 父项或自身均保持展开、当前档位 `is-checked` + fa-check；④ openDesktopBlankContextMenu 新增「查看」（fa-th-large）+ 三档子项（勾选态动态计算）；runDesktopContextAction 新增 icon-large/medium/small 分支（更新 preferences.icon_size → saveWorkspace(false) → renderDesktop 重排）；⑤ 后端 WorkspaceService：DEFAULT_PREFERENCES 加 icon_size=medium，sanitizePreferences 枚举校验（Arr::only 白名单过滤所需）。测试：新增 test_desktop_view_submenu_switches_icon_size（常量/两处换算/dataset/子菜单 markup/切档动作/CSS/偏好默认/后端白名单断言）；107 测试 969 断言通过。文档同步：版本记录更新 |
| 1.9.64 | 2026-10-01 | CmsPro | **停用应用桌面图标不占位（用户思路：停用不占格、启用后按排序落空白格）**：① webos.js 新增 `assignedDesktopItems()`——desktop_items 按 entryAssigned 过滤的可见集合统一出口；拖拽落点占用集合（drag end 的 occupied）、新增入口找空行（addDesktopEntry）改为基于可见集合，隐藏（停用/未分配）图标不再占格，其它图标可移入其格子；② 新增 `normalizeDesktopLayout()` 布局归一化——可见图标按 (y,x) 排序逐个落格，坐标冲突（停用期间格子被占后应用重新启用）自动下移到第一个空白格，布局有变化时 saveWorkspace(false) 静默保存；未冲突保持原坐标；③ 执行时机：refreshCatalog（菜单目录刷新/应用启停后）与 initialize（首次渲染前）；隐藏项原始坐标保留不写坏。测试：新增 test_disabled_app_icons_do_not_occupy_grid_cells（assignedDesktopItems/两处 occupied 替换/normalizeDesktopLayout 及两处调用断言）；106 测试 951 断言通过。文档同步：版本记录更新 |
| 1.9.63 | 2026-10-01 | CmsPro | **应用中心与升级提醒仅超管可用（用户需求）**：① WebosController::index 的 webosRuntime.admin 注入 `is_super_admin`（$admin->isSuperAdmin()）；② webos.js 顶部新增 `isSuperAdmin` 变量（runtime.admin.is_super_admin）——bootstrapDesktopItems 非超管不预置应用中心（首次进入桌面/任务栏为空）、entryAssigned 对 webos-app-center 返回 isSuperAdmin（历史工作区已保存的应用中心图标同样被过滤）、checkWebosSelfUpdate 非超管直接 return（升级提醒/角标检测均跳过）；③ WorkspaceService::resetForAdmin 按当前登录超管判断：非超管重置后桌面/任务栏为空（与前端初始化一致），超管保持固定应用中心。OS 设置/通知中心不受影响。测试：更新重置测试为非超管语义（空桌面）+ 新增超管重置测试（恢复固定应用中心）+ 新增 JS/PHP 断言测试（runtime 注入/isSuperAdmin 读取/初始预置/图标过滤/升级提醒/重置）；105 测试 942 断言通过。文档同步：版本记录更新 |
| 1.9.62 | 2026-10-01 | CmsPro | **权限对齐框架（用户反馈：sunzy 框架后台有儿康菜单而 WebOS 无）**：根因——角色 51 授予了 6 个儿康子菜单但未授权父级 #1045「儿康管理」与祖父 #25「管理」，`MenuService::userMenus()` 的 buildTree 从 parent_id=0 递归导致孤儿节点全部丢弃，而框架后台 `menus.json`（ConfigController::menus L113-144）有「补全父级菜单」逻辑故显示正常。修复：① **系统框架** `app/Services/MenuService.php` userMenus() 非超管分支移植祖先补全逻辑（授权子菜单时自动补全所有缺失祖先，与 menus.json 一致）——框架级修改，影响 menus/user 全部调用方（即 WebOS 目录），行为向框架后台对齐；② WebOS `webos.js` 新增 `visibleAppIds()`（目录递归收集 app_id）与 `entryAssigned(id)`（特殊入口保留/菜单入口须在 flatMenus/应用快捷方式须目录中仍有该应用菜单），renderDesktop、renderPinnedApps 渲染前过滤未分配入口，refreshCatalog 目录刷新后同步重绘——角色收回授权后桌面/任务栏幽灵图标不再显示。测试：新增 Feature 测试（孤儿补全场景：非超管+角色授权叶子未授权父级 → menus/user 树含完整祖先链且未授权菜单不出现）与 JS 断言（visibleAppIds/entryAssigned/两处 filter/refreshCatalog 重绘）；103 测试 928 断言通过。生产数据验证：以 sunzy（user=9）调用 userMenus 返回「管理 → 儿康管理 → 工作台/个人主页/排课管理/治疗记录/治疗项目/统计分析」与框架后台一致。文档同步：版本记录更新 |
| 1.9.61 | 2026-10-01 | CmsPro | **前台菜单触发按钮改文字样式（用户需求）**：windowHomeMenuMarkup 触发按钮内容由 `<i class="fa fa-globe">` + 箭头改为文字「前台」+ fa-caret-down；CSS .home-menu-trigger 尺寸固定 width: 58px / height: 100%（撑满标题栏），去 padding 改 justify-content: center 居中、gap: 3px。测试：101 测试 912 断言通过。文档同步：版本记录更新 |
| 1.9.60 | 2026-10-01 | CmsPro | **前台菜单下拉项去图标（用户需求）**：windowHomeMenuMarkup 菜单项移除 `<i class="fa ...">` 图标仅显示菜单名称；同步清理 collectHomeMenuLeaves 叶子数据的 icon 字段（无消费方）与 CSS `.window-home-menu-item .fa` 样式及 `gap: 8px` 间距。测试：101 测试 912 断言通过。文档同步：版本记录更新 |
| 1.9.59 | 2026-10-01 | CmsPro | **前台菜单下拉框修复与位置调整（用户反馈）**：① 默认展开且无法收起的根因是 `.window-home-menu-list { display: flex }` 覆盖了 HTML hidden 属性的 UA 默认样式 `display: none`（作者样式优先），hidden 切换形同虚设——新增 `.window-home-menu-list[hidden] { display: none; }` 显式声明修复；② 下拉列表定位由 `right: 0`（右对齐）改为 `left: 0`（左侧靠齐触发图标下方展开）。纯 CSS 改动，已同步 public。测试：101 测试 912 断言通过。文档同步：版本记录更新 |
| 1.9.58 | 2026-10-01 | CmsPro | **应用窗口前台菜单下拉框（用户需求）**：windowSidebarToggleMarkup 后新增 windowHomeMenuMarkup（触发器 data-window-action="toggle-home-menu" + .window-home-menu-list 菜单列表）/ collectHomeMenuLeaves（递归筛选 app_id 匹配且有 path/name 的菜单叶子）/ loadWindowHomeMenu（数据源系统接口 `/api/admin/menus/tree?terminal_type=home`，安装时 manifest home_menus 已由 syncMenusByTerminal 写入 admin_menus 表 terminal_type='home'；homeMenusCache Map 缓存叶子数组，同应用跨窗口复用，无菜单不缓存以便后续重试；system 应用跳过）；挂载点：openWindow 的 renderWindowPage 后 + activateWindowEntry 的 rerenderWindowNav 后（insertAdjacentHTML('afterbegin') 注入 .window-controls 首位即「收起左侧菜单」左侧）；事件委托：document click 开头外部点击收起全部已展开下拉、windowAction switch 新增 toggle-home-menu 分支（先收其它窗口已展开的保持互斥再切换 hidden + aria-expanded）、data-home-url 点击 window.open(url,'_blank','noopener') 后收起下拉；CSS 新增 .window-home-menu 系列（浅色主题，触发器沿用 sidebar-toggle 的 #087f75 色系，列表白底圆角阴影 max-height 260px 滚动）；应用无前台菜单时不注入任何元素。测试：新增 1 个测试 13 个断言，101 测试 912 断言通过。文档同步：版本记录更新 |
| 1.9.57 | 2026-10-01 | CmsPro | **安装依赖检测对齐传统后台（用户需求）**：排查确认依赖校验在系统后端 AppInstallerService::checkDependencies（dependencies.app_ids：未装→50004「依赖应用「X」未安装，请到应用市场搜索…」/ 已装未启用→50017「已安装但未启用」），WebOS 安装走同一接口天然生效；前端差距在错误呈现——confirmInstall 失败分支由 toast（一闪而过）改为 showErrorDialog（560px 长弹窗，复用 v1.9.40 已复刻实现：匹配「依赖应用「X」未安装/未启用」追加橙色市场引导），与 admin/app/index.blade.php 的 showErrorDialog 行为一致；local/market 两种安装来源共用此函数全覆盖。测试：新增 1 个断言，100 测试 899 断言通过。文档同步：版本记录更新 |
| 1.9.56 | 2026-10-01 | CmsPro | **应用中心角标样式调整（用户需求）**：.desktop-update-badge 定位由 top:-5px/right:-3px（外溢）改为 top:2px/right:8px（图标右上角内侧）。纯 CSS 改动，已同步 public。文档同步：版本记录更新 |
| 1.9.55 | 2026-10-01 | CmsPro | **WebOS 自身升级特殊优化（用户需求：仅 WebOS，其它应用保持现状）**：① upgradeApp 禁用拦截——appId === 'cmspro.webos' 时提示追加灰字说明「升级期间当前桌面与已打开的窗口可继续正常操作，不受影响；升级完成并启用后服务端功能恢复」（禁用只是服务端标记，内存中的页面不受影响，升级接口属系统应用中心）；② promptEnableApp 启用成功——WebOS 时改为 layer.confirm「已升级并启用至新版本，刷新页面后加载新版桌面」+「立即刷新」（location.reload）/「稍后手动刷新」，其它应用保持 layer.msg「应用已启用」。测试：新增 4 个断言，100 测试 898 断言通过。文档同步：版本记录更新 |
| 1.9.54 | 2026-10-01 | CmsPro | **WebOS 自身版本检测与自动升级引导（用户需求）**：新增 checkWebosSelfUpdate——initialize 桌面就绪后调用；数据复用 check-updates?lazy=1（服务端 24h 缓存，loadUpdateCount 补 return 返回 promise；state.updateChecked 已有数据直接用）；state.updateApps 中 app_id === 'cmspro.webos' 且 latest_version > current_version 时 layer.confirm 提示「发现新版本 vX（当前 vY）」，确认「立即升级」→ openEntry(applicationCenterEntry()) 打开应用中心窗口 + upgradeApp('cmspro.webos') 自动进入升级流程（复用 v1.9.40 链路：启用拦截 → layui 版本选择弹窗 → 升级执行 → 启用询问）；无新版本/检查失败静默。测试：新增 3 个断言，100 测试 894 断言通过。文档同步：版本记录更新 |
| 1.9.53 | 2026-10-01 | CmsPro | **修改密码弹窗宽度调整（用户需求：480px）**：openPasswordDialog 的 openActionDialog width 由 '430px' 调整为 '480px'（复用 options.width 通道写入 .action-dialog-card style.width）。测试断言同步，100 测试 891 断言通过。文档同步：版本记录更新 |
| 1.9.52 | 2026-10-01 | CmsPro | **个人设置窗口/修改密码弹窗固定宽度（用户需求：680px/430px）**：① openEntry 中对 entry.path === '/admin/account' 特判——size.width 固定 680px（Math.min(680, layerRect.width-40) 小屏收窄防溢出，MIN_WINDOW_WIDTH 兜底），覆盖 defaultWindowSize 的偏好百分比；② openPasswordDialog 复用 openActionDialog 既有 options.width 通道（写入 .action-dialog-card style.width）传 '430px'。测试：新增 2 个断言，100 测试 891 断言通过。文档同步：版本记录更新 |
| 1.9.51 | 2026-10-01 | CmsPro | **OS 设置窗口固定宽度（用户需求：1000px）**：defaultWindowSize 对 webos-settings 特判——width 固定 1000px（Math.min(1000, rect.width-40) 小屏收窄防溢出，MIN_WINDOW_WIDTH 兜底），height 保持默认 80% 比例；应用中心仍为出厂默认 78%×80%，普通应用窗口仍按管理员偏好百分比。测试：新增 1 个断言，100 测试 889 断言通过。文档同步：版本记录更新 |
| 1.9.50 | 2026-10-01 | CmsPro | **路由模式窗口内容可选中复制（用户需求）**：根因——`.webos-desktop` 全局 `user-select:none`（防桌面框选图标时误选文字），_component 路由模式注入父文档的 HTML 继承禁选，iframe 页面因独立文档不受影响；修复——`.window-page-host`（窗口内容区容器，iframe 与 _component 共用）恢复 `user-select:text`，桌面/图标/标题栏/侧栏保持禁选（拖动标题栏不误选、框选行为不变）。纯 CSS 改动，100 测试 888 断言通过。文档同步：版本记录更新 |
| 1.9.49 | 2026-10-01 | CmsPro | **底层窗口 iframe 点击聚焦（用户需求）**：根因——iframe 内部点击的 pointerdown 不会冒泡到父页面，窗口元素级 pointerdown 聚焦（bindWindowGestures 2138 行）收不到事件。方案（透明遮罩法）：① 新增 syncWindowShields——遍历窗口，含 iframe 的 [data-window-page-host] 在非 is-focused 时 append `.window-frame-shield`（绝对定位 inset:0 z-index:5 透明，host 加 position:relative），聚焦窗口移除遮罩，_component 窗口不遮罩；② focusWindow 内调 syncWindowShields（创建/恢复/最大化/任务栏聚焦全覆盖）+ renderWindowPage 重建 iframe 后补挂；③ 点击遮罩 pointerdown 冒泡至窗口元素 → focusWindow → 移除自身遮罩并给其它窗口挂遮罩，第二次点击直接操作 iframe（与 Windows「先激活再交互」一致）。CSS 新增 .window-frame-shield。测试：新增 3 个断言，100 测试 888 断言通过。文档同步：版本记录更新 |
| 1.9.48 | 2026-10-01 | CmsPro | **应用更新图标全局规则（用户需求：icon.svg → icon.png → manifest icon 兜底）**：根因——check-updates 返回的更新记录只有 app_id/name/版本字段，cardIconMarkup 无 icon_url/icon 可用直接落 fa-cube 兜底；修复 cardIconMarkup——`!app.icon_url && !app.icon` 时 `findCatalogApp(app.app_id)` 合并目录应用数据（`Object.assign({}, catalogApp, app)`，记录自身字段优先、目录图标补缺）：catalog 的 icon_url 由服务端按全局 icon.svg → icon.png 顺序解析（/api/app/{id}/icon 同源），catalog.icon 为 manifest.json 的 icon fa 类兜底；本地/市场卡片路径不受影响。测试：新增 2 个断言，100 测试 885 断言通过。文档同步：版本记录更新 |
| 1.9.47 | 2026-10-01 | CmsPro | **桌面应用中心图标可更新数量角标（用户需求）**：① renderDesktop 渲染桌面项时对 `item.id === 'webos-app-center'` 且 `state.updateApps.length > 0` 追加 `<span class="desktop-update-badge">N</span>`；② syncUpdateBadge 增加 renderDesktop() 同步（loadUpdateCount/refreshAppUpdates/dropUpdatedApp/upgradeApp 全量检查后自动联动，桌面与侧栏角标一致）；③ CSS .desktop-update-badge——按钮内绝对定位右上（top:-5px right:-3px），红色 #ef5a62 圆底白字 + 阴影（与侧栏 .window-nav-badge 同风格），pointer-events 由既有 `.desktop-icon span` 规则屏蔽不影响拖拽/点击。测试：新增 3 个断言，100 测试 883 断言通过。文档同步：版本记录更新 |
| 1.9.46 | 2026-10-01 | CmsPro | **应用更新状态栏刷新按钮（用户需求）**：① 新增 renderUpdatesStatus——状态栏统一渲染「刷新按钮（data-updates-refresh，title 提示）+ 文案」，loadAppCenterTab('updates') 本地分支与 lazy 请求成功分支共用；② 新增 refreshAppUpdates——强制 POST /api/admin/apps/check-updates（无 lazy，绕过服务端 24h 缓存口径，可发现新发布版本）→ 更新 state.updateApps/updateCount/updateChecked + syncUpdateBadge → renderUpdatesStatus + renderApplicationCards 重渲染 + toast；检查中显示 fa-spin 加载、失败标红；③ handleAppCenterAction 委托加 [data-updates-refresh] 分支（closest('[data-app-center]')）；④ CSS 新增 .updates-refresh-button（24px 图标按钮，hover/focus-visible 高亮）。测试：100 测试 880 断言通过。文档同步：版本记录更新 |
| 1.9.45 | 2026-10-01 | CmsPro | **升级链路停留当前 Tab + 更新列表即时刷新（用户需求）**：① 新增 reloadAppCenterCurrent——refreshCatalog + renderAppCenter(state.appCenterTab)（按当前 Tab 渲染，不强制切到已安装），升级链路 5 处替换（禁用后、doUpgrade 成功、一键完成×2、一键失败×2）+ promptEnableApp 增加 keepTab 参数（升级链路传 true）；② 根因：loadAppCenterTab('updates') 每次渲染都重新请求 check-updates（服务端缓存 24h 旧数据，刚升级的应用又出现）——引入 state.updateChecked 标记（loadUpdateCount/Tab 首次请求/upgradeApp 全量检查成功时置 true），已检查时直接用本地 state.updateApps 渲染（dropUpdatedApp 已移除成功项），列表即时正确；N=0 显示"暂无可用更新，应用已是最新"。测试：新增 5 个断言，100 测试 880 断言通过。文档同步：版本记录更新 |
| 1.9.44 | 2026-10-01 | CmsPro | **应用中心 loading 全面去文字（用户需求：所有 layer.load(2,{content}) 改 layer.load(2)）**：grep 定位 6 处带 content 的 loading 并全部改为 `layer.load(2)`——doUpgrade（正在下载升级包）、runAutoUpgradeStep（正在自动升级第 N 步）、runDeleteFiles（正在删除应用文件，顺带修复误插入的 legacyAjax 残行）、downloadAppBackup（正在准备下载）、备份恢复上传文件（正在上传文件）、openAppDocs（加载文档）；4580 卸载 `layer.load(2,{time:0})` 本无文字保持。测试：断言更新（含 `assertStringNotContainsString("layer.load(2, { content:")` 全局反向断言，注意本环境 PHPUnit 无 assertStringDoesNotContainString 方法），100 测试 875 断言通过。文档同步：版本记录更新 |
| 1.9.43 | 2026-10-01 | CmsPro | **上传执行 loading 去文字（用户需求）**：submitUploadPackage 的 `layer.load(2, {content:'正在处理，请耐心等待...', time:0})` 改为 `layer.load(2)` 纯加载特效（该弹层为手动升级/上传安装共用 openUploadDialog 组件，两处一并生效）。文档同步：版本记录更新 |
| 1.9.42 | 2026-10-01 | CmsPro | **桌面图标右键重命名（用户需求）**：desktopContextMenuItems 增加「重命名」（fa-pencil，置于「打开」之后；应用中心等所有桌面图标均可重命名）+ runDesktopContextAction 增加 rename 分支；新增 renameDesktopIcon——layer.prompt（formType 0，回显当前 title，maxlength 60）输入新名称，trim 后校验非空/≤60，修改 workspace.desktop_items 项 title（引用直接赋值）→ renderDesktop（桌面渲染本就用 item.title）→ saveWorkspace(false) 静默保存；应用名称/系统菜单/开始菜单不受影响。测试：新增 4 个 JS 断言，100 测试 874 断言通过。文档同步：版本记录更新 |
| 1.9.41 | 2026-09-30 | CmsPro | **修改密码弹窗去毛玻璃（用户需求）**：openPasswordDialog 的 openActionDialog 增加 `plain: true`——弹窗切换为 layui 风格白底实底（遮罩 backdrop-filter:none + dialog-card 直角 2px + layui 常规阴影），与 1.9.10「管理入口」弹窗同一模式；表单字段/校验/提交逻辑不变（API PUT /api/admin/auth/password）。文档同步：版本记录更新 |
| 1.9.40 | 2026-09-30 | CmsPro | **应用升级 layui 交互复刻传统后台（用户需求：点击更新与 admin/app/index.blade.php 一致）**：重写 upgradeApp（原为直接 POST upgrade + toast）。新流程：① 启用拦截——findCatalogApp 查 status===1 → layer.confirm「禁用并继续升级」→ POST disable → reloadInstalledAppCenter + layer.msg 后递归 upgradeApp；② 版本列表——优先取 state.updateApps 内 available_versions，否则 POST /api/admin/apps/check-updates 全量（成功后重建 state.updateApps/updateCount/syncUpdateBadge）；③ openUpgradeDialog——layer.open 复刻系统：当前版本 + 备份警告条 + layui-form radio（单步升级/跳跃升级/直接最新 + __auto__ 一键升级 + 问号 layer.tips）+ btn ['我已备份，确认升级','先去备份','取消']，success 内 window.layui.form.render('radio')（桌面页 249 行已预热 form 模块），btn2 openBackupDialog（WebOS 已有备份管理）不关层；④ doUpgrade——layer.load(2,{content:下载提示}) → POST upgrade?version= → 成功 dropUpdatedApp + reloadInstalledAppCenter + promptEnableAfterUpgrade（已有）；⑤ startAutoUpgrade/runAutoUpgradeStep——逐级升级，任一步失败即停，50010「没有可用的更新」视为完成。测试：更新角标用例断言（移除旧 toast 断言，新增 5 个函数 + 按钮 + 联动断言），100 测试 870 断言通过。文档同步：版本记录更新 |
| 1.9.39 | 2026-09-30 | CmsPro | ① **重置工作区保留应用中心（用户反馈）**：WorkspaceService::resetForAdmin 由清空 desktop_items/taskbar_items 改为写入初始默认布局——内置入口 webos-app-center（应用中心，path /admin/cmspro/webos?app=market，图标 fa fa-shopping-bag，group_title WebOS）在桌面 (0,0) 与任务栏各一份，结构与 sanitizeDesktopItem 输出同构（id/menu_id/app_id/title/path/icon/group_title/x/y，任务栏无 x/y）；偏好恢复 DEFAULT_PREFERENCES，自定义壁纸文件保留。测试断言同步更新（重置后 desktop_items.0.id = webos-app-center）。② **卷页方向修正 + 动态拉开（用户反馈，附参考图）**：1.9.38 的 sheet 渐变 225deg 分界线是「左上→右下」对角线，白角朝向错误；重写为三层结构（content z1 深蓝内容页 / pageBack z2 纸背面右下三角 clip-path polygon(100% 0,100% 100%,0 100%)，135deg 渐变折痕处 #96a1ad 暗带模拟卷筒投影 / paperFront z3 白纸正面左上三角 polygon(0 0,100% 0,0 100%)）；悬停 mouseenter/mouseleave 切换 clip-path（同顶点数 polygon 支持 transition）：容器 64→120px，paperFront 收拢 polygon(0 0,14% 0,0 14%)，pageBack translate(16px,16px) scale(.25) 翻走淡出，内容页沿折痕动态显现。文档同步：版本记录更新 |
| 1.9.38 | 2026-09-30 | CmsPro | **卷页效果重做（用户提供参考图：白色纸张卷页）**：由 3D 翻牌卡改为经典 page peel 卷页——entry.js 内联样式全部重写（该版折痕方向有误，1.9.39 修正）。文档同步：版本记录更新 |
| 1.9.37 | 2026-09-30 | CmsPro | **入口白屏修复（用户二次反馈）**：1.9.36 的 entry.css（ID 选择器 + 实色兜底）在线上仍被覆盖/未生效（已验证 CSS 请求 200 text/css、主题无冲突规则，根因未定）。改为彻底方案：entry.js 构建入口 DOM 时**全部关键样式以内联 style 写入**（position/渐变背景/backface-visibility/边框/文字颜色，内联优先级最高不受任何主题样式覆盖），悬停翻页改由 mouseenter/mouseleave 切换 inner.style.transform 驱动（不再依赖 :hover 伪类）；entry.css 保留作为渐进增强（focus-visible 轮廓）。版本参数 ?v=filemtime 随文件更新自动失效浏览器缓存。文档同步：版本记录更新 |
| 1.9.36 | 2026-09-30 | CmsPro | **入口实现方式重构（用户要求不改系统布局）**：回退 1.9.35 对 admin.blade.php 的全部改动，改用系统布局预留挂载点——`@stack('page_styles')`（13 行，head 内）/ `@stack('page_scripts')`（741 行，body 末尾）。ServiceProvider::boot 注册 `View::composer('layouts.admin', ...)`（layouts.admin 仅被 admin.index 主框架页渲染），composer 内用 `$factory->startPush()` 注入：① entry.css（`?v=filemtime` 防缓存）；② 覆盖传统后台跳转（admin.index + 未带 skip_webos=1 + 偏好 override_admin_home 开启 → head 内 `location.replace`，try/catch 容错）；③ 载体节点 `#webos-float-book-carrier`（data-url）+ entry.js。应用禁用后 ServiceProvider 不加载，入口/跳转自动消失。文档同步：版本记录更新 |
| 1.9.35 | 2026-09-30 | CmsPro | 双需求迭代（1.9.36 已重构实现方式，本版系统布局改动已回退）：① 系统布局入口；② 覆盖传统后台偏好键 override_admin_home；③ OS 设置「系统设置」Tab（覆盖传统后台 settings-toggle + 重置工作区内联确认，新路由 POST api/workspace/reset → WorkspaceService::resetForAdmin）；测试 +2 用例。文档同步：版本记录更新 |
| 1.9.34 | 2026-09-30 | CmsPro | 「显示桌面」贴边：根因——`.webos-taskbar` 有 `padding: 0 10px`，按钮右侧到屏幕右缘的 10px 空白不可点击（用户截图反馈）。修复：底部/顶部任务栏 `.show-desktop-button` 加 `margin-right: -10px` 抵消右侧内边距（分隔线随按钮右移，可点区共 24px）；左右任务栏变体加 `margin-bottom: -8px`（抵消 `padding: 8px 0` 的底缘空白）。纯 CSS 改动，文档同步：版本记录更新 |
| 1.9.33 | 2026-09-30 | CmsPro | 地球图标点击行为调整：由 `openEntry`（WebOS 内通用 iframe 窗口，1.9.31）改为 `window.open(dataset.websiteUrl, '_blank', 'noopener')` 浏览器新标签页打开站点首页。`#website-button` 的 DOM 结构与 `data-website-url="{{ url('/') }}"` 不变。文档同步：版本记录更新 |
| 1.9.32 | 2026-09-30 | CmsPro | 「显示桌面」热区回调：1.9.30 的 `flex:1 1 auto` 与 `.taskbar-spacer` 平分剩余空间，用户反馈太宽。改为 `flex:0 0 auto; width:14px` 固定窄条（比原 7px 宽一倍兼顾点击面积），保留 border-left 分隔线与 hover/focus-visible 高亮；剩余空白仍由 `.taskbar-spacer` 独占。左右任务栏变体（height:7px 横条）不受影响。纯 CSS 改动，文档同步：版本记录更新 |
| 1.9.31 | 2026-09-30 | CmsPro | 任务栏新增前台首页入口：① `desktop/index.blade.php` 在通知按钮（#notification-button）左侧插入 `#website-button`（`taskbar-button website-button` 类，`fa fa-globe` 图标，`data-website-url="{{ url('/') }}"`）；② `elements.websiteButton` 引用 + bindEvents 绑定——`openEntry({ id:'website-home', title:'前台首页', path: dataset.websiteUrl, icon:'fa fa-globe', group_title:'系统' })`，复用通用 iframe 窗口（`/` 通过 safePath 站内路径校验），windowKey 按 id 去重，重复点击激活已存在窗口；③ 样式零新增（复用 `.taskbar-button` 42×42 与 hover 高亮）。文档同步：版本记录更新 |
| 1.9.30 | 2026-09-30 | CmsPro | 「显示桌面」热区扩大：`.show-desktop-button` 由固定 `width:7px; margin-left:9px` 改为 `flex:1 1 auto; min-width:7px`——任务栏 flex 布局中与 `.taskbar-spacer`（flex:1，位于运行窗口区与通知按钮之间）平分剩余空间，时间按钮右侧到屏幕右缘整块可点击。去常驻淡色背景、加 hover/focus-visible 高亮（transition 0.15s）。点击逻辑（#show-desktop-button click → 收起/恢复窗口）不变。任务栏置顶变体（border-top 适配）不受影响。文档同步：版本记录更新 |
| 1.9.29 | 2026-09-30 | CmsPro | 修复拖动「不跟手+黏住」：根因链——按住图标（内含 img）拖动 → 浏览器原生图片拖拽 → pointercancel → button 的 setPointerCapture 被打断（move 停 → 不跟手），pointerup 永不达 button（end 不执行、监听残留），松开后 hover 在按钮上时 move 仍触发 → 黏住。修复组合：① `dragDesktopIcon`/`bindDesktopMarquee` 的 move/end 监听从 button/容器改绑 **document** + `pointercancel` 清理（收尾必达，cleanup 中 try `releasePointerCapture`）；② CSS：`.desktop-icon { user-select:none; touch-action:none }` + `.desktop-icon img/i/span { pointer-events:none }`（指针事件目标恒为按钮，原生拖拽源根除，closest 查找也更快）；③ 落格后 `wasDragged` 写到 `desktopButtonById(id)` 新节点（renderDesktop 重建 DOM 后原引用已游离，原写法导致拖完双击误打开）；④ 未用 pointerdown `preventDefault`（会拦兼容鼠标事件，保 click/dblclick）。**经验：pointer 拖拽监听一律绑 document，靠 setPointerCapture 之外必须有 pointercancel 兜底**。文档同步：版本记录更新 |
| 1.9.28 | 2026-09-30 | CmsPro | 修复 1.9.27 拖动失效：`dragItems.map(desktopButtonById)` 的 map 回调首参是 item 对象而非 id——`desktopButtonById(item)` 内 `item.dataset` undefined → TypeError → pointerdown 中断（单/多选拖动全部失效，与用户反馈一致）。修复：显式回调 `desktopButtonById(entry.id)`。教训：**数组方法直接传具名函数时必须核对回调签名**（map 传 (element, index, array)），node --check 只验语法不验运行时。另补 `bindDesktopMarquee` 的容器级 `setPointerCapture`（指针移出容器时 pointermove/pointerup 仍派发到容器，防 marquee 残留）。文档同步：版本记录更新 |
| 1.9.27 | 2026-09-30 | CmsPro | 桌面多选移动：① 状态 `state.desktopSelection`（Set，存 desktop item id），`renderDesktop` 按 Set 输出 `is-selected` class，`syncDesktopSelection()` 同步按钮高亮；② `dragDesktopIcon` 重写——pointerdown 时若按住已选中图标且选中数 >1 → 多选整体拖动（记录各按钮 initialLeft/Top，move 统一偏移 clamp），落点按网格换算后以「未拖动图标占用集」做冲突探测（按 y,x 排序逐个落格，占用则 gridY+1 向下找空位，guard 200 防死循环），未拖动时 Ctrl+单击切换选中；③ 新增 `bindDesktopMarquee()`（bindEvents 中绑定）——桌面空白 pointerdown 拖出 `.desktop-marquee` 选框（容器坐标系），pointermove 时 client 空间矩形相交检测更新选中集（Ctrl 按住不剔除框外项），位移 <5px 视为点击空白清空选择；④ CSS：`.desktop-icon.is-selected`（蓝色高亮，独立于 hover 白色）、`.desktop-marquee`（pointer-events:none + z-index:5）。文档同步：版本记录更新 |
| 1.9.26 | 2026-09-30 | CmsPro | 演示数据移除：① ServiceProvider 删 `demoTodos()` + hook 注册（boot 仅剩路由/视图）；② `WebosController::allTodos` 数据源改系统 `NotificationService::allTodos()`；③ **系统框架改动**（NotificationService.php，重构零行为变更）：`todos()` 的「hook 聚合+字段校验+权限过滤+去重+归零过滤+容错」段抽取为 `protected aggregateTodoEntries()`，新增公共 `allTodos()`（聚合但不过滤已读）——原因：`todos()` 内置已读过滤（count ≤ seen 直接移除），通知中心窗口「全部待办含已读」视图无法复用；todos() 未读口径完全不变（系统全量 273 测试回归通过）；④ DB 清理 16 条测试通知 + 已读记录 + 11 条 demo 待办已读；⑤ 测试 all-todos 用例自建临时 hook（`webos.test` 注册/`removeHooksByApp` 清理成对）。文档同步：版本记录更新 |
| 1.9.25 | 2026-09-30 | CmsPro | 移除通知面板设置齿轮（回退 1.9.24）：删除 index.blade.php 通知面板 header 的 `data-notification-setting` 按钮（header 仅剩标题+摘要）与 webos.js 面板委托中的对应分支。通知中心窗口入口由底部「查看全部」（data-open-notification-page）承载。文档同步：版本记录更新 |
| 1.9.24 | 2026-09-30 | CmsPro | 通知面板齿轮开窗化：index.blade.php 通知面板 header 的 `<a target="_blank">` 改为 `<button data-notification-setting>`（.icon-button 样式天然兼容 button：border:0+background:transparent）；面板点击委托（notificationPanel click）在「查看全部」前新增分支，`openEntry({ id: 'notifications-setting', path: '/admin/notifications', ... })` 走通用 iframe 窗口模式（windowKey='folder-notifications-setting'，与个人设置 account-settings 同模式）。无前端路由匹配需求（openEntry 直接构造 entry，不经 openLinkInWebos 菜单匹配）。文档同步：版本记录更新 |
| 1.9.23 | 2026-09-30 | CmsPro | 按钮权限子项导致父菜单消失：`flattenMenus` 只输出 `openablePath` 为真的叶子——「用户管理」（path=/admin/user）下 3 个子项（新增/编辑/删除用户，type=3、path=null）全被过滤 → 父菜单自身无叶子输出 → 开始菜单/文件夹窗口/入口管理全部缺失「用户管理」。修复（两处一致）：① `flattenMenus` walk 的 children 分支记录递归前后 `output.length`，子树零输出且 `openablePath(item)` 时 `pushLeaf` 输出父菜单自身（叶子输出抽为 `pushLeaf(item, group, appId, folder)` 内部函数，保留 group_icon 字段）；② `windowNavTree` collect 中 branch.children 为空且 `openablePath(item)` 时 push `windowNavLeaf(item)`。影响面经全库 node 扫描：20 个「有 path 且有 children」节点中 19 个子项均可打开（保持分组语义不变），仅「用户管理」一类被兜底救回（总叶子 242→243）。文档同步：版本记录更新 |
| 1.9.22 | 2026-09-30 | CmsPro | 系统菜单隐藏 WebOS 项：`menuGroups()`（开始菜单唯一数据源，仅 `groupsFromMenus` 调用）中 flatMenus 循环跳过 `app_id === 'cmspro.webos'`（manifest 的「WebOS 管理桌面」菜单，open_type=_blank 在 WebOS 内无意义），删除原固定 `groups.set('webos', ...)` 块（应用中心 special 入口不再出现在开始菜单）。`applicationCenterEntry()` 仍有 6 处调用（初始固定/findEntry/窗口导航/应用中心打开），非死代码。入口管理（entryManagement）不走 menuGroups，WebOS 菜单项管理不受影响。文档同步：版本记录更新 |
| 1.9.21 | 2026-09-30 | CmsPro | 菜单「+」按钮悬停浮动：`renderStartMenu()` 的 pin-button 追加 `is-pinned`（已固定时）；CSS `.pin-button` 默认 `opacity:0/visibility:hidden/translateX(4px)` + 0.15s 过渡，`.start-app-item:hover`/`:focus-within`/`.pin-button.is-pinned` 显示。`.pin-button` 仅在开始菜单渲染（JS 唯一渲染点），无其他使用处。文档同步：版本记录更新 |
| 1.9.20 | 2026-09-30 | CmsPro | 通知/待办 link 无效导致不开窗：`openLinkInWebos` 精确匹配 `state.flatMenus[].path`，1.9.19 测试数据中 6 个 link 为推测路径（admin_menus 无此菜单）→ 回退 `window.open` 新标签。修复：`demoTodos()` 重写为真实菜单路径（/admin/attachment、/admin/finance/currency-types、/admin/points/types、/admin/cmspro/taskmgr、/admin/cmspro/signin/records、/admin/appstore/apps，key 同步 attachments/finance/points/tasks/signins/appstore），注释标明「link 必须与 admin_menus 核对」；DB 修正 2 条通知 link（backup→app、mail→setting），`/admin/cmspro/demo/tasks` 经查询确认存在。测试断言同步（data.8.key）。文档同步：版本记录更新 |
| 1.9.19 | 2026-09-30 | CmsPro | 测试数据扩充：① `ServiceProvider::demoTodos()` 3→9 条（新增 comments/orders/roles/backups/logs/mails，count 值域 1/2/3/5/8/12/26/66/150 覆盖徽标 99+ 折叠；link 全部真实菜单路径）；② 通知测试数据经 tinker 临时脚本（`storage/temp-notify-seed.php`，执行后删除）灌库——`AdminNotification::create`（level 用系统 `NotificationService::LEVELS` 四值）+ `markRead`（正规已读链路 `admin_notification_reads`）写入 12 条，时间分布 subDays(0/1/3/5/6/8) 验证窗口「今天/昨天/更早」分组。tinker 实测：panel todos=6（9 条中 3 条已读被过滤）notices=10 unread=7，paginate total=16。测试断言同步（all-todos count 3→9，含 data.8.count=150）。文档同步：版本记录更新 |
| 1.9.18 | 2026-09-30 | CmsPro | 面板通知 Tab 未读口径：`NotificationService::panel()` 的 notifications 来自 `visibleQuery()` 最新 10 条（含已读），面板 `renderNotificationItems` 此前直接渲染全量。修复：渲染前 `.filter(n => Number(n.is_read) !== 1)`，仅显未读（蓝点恒显示、is-read 分支随之清理），空态改「暂无新通知」。点击通知 → `/read` → `loadNotifications` → panel 重拉 → 已读条目被过滤消失；「全部已读」同理收敛。窗口通知 Tab 维持全量口径（`loadNotificationCenterNotices` 不受影响）。文档同步：版本记录更新 |
| 1.9.17 | 2026-09-30 | CmsPro | 任务栏通知徽标刷新修复：徽标唯一驱动是 `loadNotifications()`（panel + `/badge` 接口 → `elements.notificationBadge`）。窗口通知行点击的 `/read` then 回调此前只更新行内 DOM（is-read 类 + 移除蓝点）未调 `loadNotifications()` → 徽标不收敛；补上。面板通知行（`renderNotificationItems`）此前 markup 无通知 id、点击只 `openLinkInWebos` 不标记已读 → 通知读完了徽标还在；`notice.id` 存在时输出 `data-notification-id`，点击委托中先调 `/read`（`.then(loadNotifications)`）再开窗（幂等无害）。待办卡片点击走 `markTodoRead`（内部已 `.then(loadNotifications)`）无需改动。文档同步：版本记录更新 |
| 1.9.16 | 2026-09-30 | CmsPro | 通知中心窗口筛选与批量已读：`notificationCenterState` 增加 `todoFilter`/`noticeFilter`（默认 'all'，两 Tab 独立）；新增 `notificationToolbarActionsMarkup()`（工具栏右侧分段筛选按钮组 + 全部已读按钮，`.app-center-toolbar` 已是 space-between 直接挂载）与 `markAllNotificationCenterRead(button)`（待办：`all-todos` 取未读逐条 `todo-read` 幂等标记；通知：系统 `read-all`；按钮 loading 态 + 完成后 `loadNotifications` 刷徽标 + 重拉当前 Tab 列表）；`loadNotificationCenterTodos` 按 `todoFilter` 前端过滤（数据量小）；`loadNotificationCenterNotices` 通知筛选走系统 `is_read=0/1` 服务端参数（分页口径正确），切筛选重置 page=1；委托处理校验按钮所在窗口 `entry.special === 'notifications'`（不影响应用中心 Tab 与面板）。CSS 新增 `.notification-toolbar-actions` / `.notification-filter` / `.notification-filter-btn(.is-active)` / `.notification-read-all`。文档同步：版本记录更新 |
| 1.9.15 | 2026-09-30 | CmsPro | 通知中心窗口全量列表：`ServiceProvider` 抽 `public static function demoTodos()`（hook filter 与全量接口共用单一数据源）；`WebosController::allTodos()` 新增——`AdminTodoRead`（user_id+todo_key→seen_count）计算已读（seen ≥ count 视为已读），返回全部待办含 `seen`/`is_read`；路由 `GET /admin/cmspro/webos/api/all-todos`。前端：`loadNotificationCenterTodos` 改双请求（panel 仅刷新侧栏徽标 + all-todos 渲染列表）；`renderTodoCards` 支持 `is_read`（对勾图标/「已处理」/「已读」徽标）；`renderNotifications` 窗口待办刷新分支改为重拉全量；CSS 新增 `.notification-todo.is-read` 弱化规则。通知 Tab 无需改动（系统 `paginate` 默认全量返回，`is_read` 仅在显式传参时过滤）。新增功能测试 `test_all_todos_api_returns_full_list_with_read_state`（未读全未读 → 点击后单条 is_read=true）。文档同步：版本记录更新 |
| 1.9.14 | 2026-09-30 | CmsPro | 待办测试数据源：`ServiceProvider::boot` 注册 `HookManager::registerFilter('admin.notifications.todos', ..., 10, 'cmspro.webos')`，返回 3 条演示待办（`webos.demo.content`/`webos.demo.users`/`webos.demo.apps`，link 为系统菜单真实路径 `/admin/content`、`/admin/user`、`/admin/app`）。数据契约：key/title/link 为字符串、count 为 int>0（0 被后端过滤）、已读由 `AdminTodoRead`（user_id+todo_key→seen_count）驱动，点击后 count≤seen 时条目消失。tinker 实测聚合返回 3 条。后续接真实待办只需替换 filter 回调内数据源。文档同步：版本记录更新 |
| 1.9.13 | 2026-09-30 | CmsPro | 通知/待办点击改为 WebOS 内打开应用窗口：新增 `openLinkInWebos(link)` helper（路径去 query 后与 `state.flatMenus` 的 `entry.path` 精确匹配 → `openEntry` 打开/复用应用窗口；`#` 或空链接不跳转；无匹配或入口不可打开时回退 `window.open` 新标签页）。四处点击处理接入：面板待办（`preventDefault` 拦截 a 标签默认跳转 + 标记已读 + 开窗）、面板通知、窗口待办卡片（同面板待办）、窗口通知行（标记已读 + 开窗）。`openEntry` 内部自带 `closePanels()`，面板点击后自动收起。文档同步：版本记录更新 |
| 1.9.12 | 2026-09-30 | CmsPro | 通知中心窗口通知列表不显示修复：`loadNotificationCenterNotices` 的 `api()` then 回调中 `payload.data` 取值错误（api() resolve 的已是 `payload.data` 即 `{items, pagination}`），再取 `.data` 恒为 undefined → notices 恒为空数组 → 恒显「暂无通知消息」。修正为直接使用 then 参数（`body.items` / `body.pagination`）。排查依据：tinker 实测 `NotificationService::paginate` 返回 4 条通知且结构字段正确，后端无问题。文档同步：版本记录更新 |
| 1.9.11 | 2026-09-30 | CmsPro | 任务栏重复图标修复：`renderTaskbarWindows` 末尾联动 `renderPinnedApps`（单向），但 `addTaskbarItem`/`removeTaskbarItem`/`pruneDesktopItemsByAppId` 此前只调 `renderPinnedApps`——固定运行中应用后运行区窗口按钮未按「已固定过滤」重绘，固定图标 + 窗口按钮并存。三处统一改为 `renderTaskbarWindows()`。`addTaskbarItem` 去重升级为 `entryAppKey` 聚合键比对（同应用多菜单入口只固定一个）；`taskbarItems()` 读取时按 id 去重（兜底历史重复数据，保存时顺带清洗）。文档同步：版本记录更新 |
| 1.9.10 | 2026-09-30 | CmsPro | 管理入口弹窗 layui 风格：`openEntryDialog` 的 `openActionDialog` 调用增加 `plain: true`（纯色遮罩、无毛玻璃），与卸载/删除确认弹窗一致。文档同步：版本记录更新 |
| 1.9.9 | 2026-09-30 | CmsPro | 任务栏与桌面解耦：新增迁移 `2026_09_30_000001_add_taskbar_items_to_app_cmspro_webos_workspaces_table`（`taskbar_items` JSON 列）；`WebosWorkspace` 模型 fillable/casts 补 `taskbar_items`；`WorkspaceService` 新增 `sanitizeTaskbarItems`（最多 12 个，复用 `sanitizeDesktopItem` 剔除 x/y），`getForAdmin` 对旧数据兜底（taskbar_items 为 null 时取 desktop_items 前 4）；`WebosController::saveWorkspace` 增加 taskbar_items 验证规则。前端：新增 `taskbarItems()`（独立数据源）、`addTaskbarItem`/`removeTaskbarItem`（pin/unpin 动作改写任务栏列表）、`dragTaskbarIcon`（pointer 拖动 + `elementFromPoint` 换位 + `taskbarDragJustEnded` 吞掉松开后的误触 click）；`renderPinnedApps`/`pinnedTaskbarKeys` 改用 taskbarItems；`saveWorkspace` body 携带 taskbar_items；`pruneDesktopItemsByAppId` 卸载时同步清理两列表；`bootstrapDesktopItems` 新用户任务栏默认固定应用中心。文档同步：版本记录更新 |
| 1.9.8 | 2026-09-30 | CmsPro | 类型筛选右移与名称搜索：`appCenterExtrasMarkup` 增加 records 分支（在右上角工具区渲染 `data-records-operation` 下拉，复用 `.app-center-filter` 样式与搜索框并排）；`renderRecordsLayout` 移除整个 `.records-toolbar`；`renderRecordGroups` 过滤条件扩展为同时匹配 `log.app_id` 与 `recordAppName()`（名称走目录/市场缓存兜底链）；占位更新为「搜索应用名称或标识」。CSS 清理 `.records-toolbar` 及其 select 规则。文档同步：版本记录更新 |
| 1.9.7 | 2026-09-30 | CmsPro | 安装记录搜索上移：`appCenterSearchMarkup` 占位配置新增 `records: '搜索应用标识'`；`renderRecordsLayout` 移除列表内 `data-records-search` 输入框（工具栏仅留类型筛选下拉）；input 委托中 `[data-app-search]` 增加 `state.appCenterTab === 'records'` 分流（写 `state.recordsKeyword` 后 `refreshRecordsPanels` 局部重渲染）；`loadRecordsTab` 重置关键字时同步清空右上角搜索框值（筛选变更后保持显示与过滤一致）。CSS 清理 `.records-toolbar input` 及 `:focus` 规则。文档同步：版本记录更新 |
| 1.9.6 | 2026-09-30 | CmsPro | 已卸载应用名称显示修复：`recordAppName`/`recordIcon` 兜底链扩展（`findCatalogApp` → `state.marketApps` 市场缓存 → app_id/fa-cube），市场来源 icon 相对路径经 `marketIconUrl()` 补全；新增 `fillRecordAppInfo()`——`loadRecordsTab` 渲染后对目录缺失的 appId 先走 `/api/admin/apps/available`（本地文件还在的应用，写入缓存 `_source: 'local'`），仍缺失的并行查 `/api/admin/market/apps/{appId}`（`_source: 'market'`），补齐后 `refreshRecordsPanels` 局部重渲染（面板已关闭时静默跳过）。文档同步：版本记录更新 |
| 1.9.5 | 2026-09-30 | CmsPro | 图标裁剪修复：`.record-row-icon` 加 `position: relative`，img 改绝对定位（`inset: 4px` + `calc(100% - 8px)` + `object-fit: contain`），不再依赖 flex 子项百分比高度解析（修复 img 按内在尺寸 120px 渲染溢出被 overflow:hidden 裁剪的问题）。文档同步：版本记录更新 |
| 1.9.4 | 2026-09-30 | CmsPro | 安装记录图标链修复：`recordIcon` 主图直连 `/api/app/{appId}/icon`（服务端 icon.svg → icon.png 顺序，对齐全局图标规则），失败经全局兜底机制降级 manifest `icon` 字段（fa 类或图片 URL）→ `fa-cube`；不再依赖前端 catalog 加载状态（修复目录未就绪时全部显示占位图标的问题）。CSS `.record-row-icon img` 改 `object-fit: contain` + 4px 内边距（修复非方形 SVG 被 cover 裁剪）。文档同步：版本记录更新 |
| 1.9.3 | 2026-09-30 | CmsPro | 通知中心窗口：新增 `notificationCenterEntry()`（`special: 'notifications'`，id `webos-notification-page`）；`windowMarkup` 增加 isNotifications 分支（侧栏复用固定 Tab 模式 + `data-webos-notifications` 内容壳）；`notificationCenterSidebarMarkup` 渲染待办/通知菜单（待办徽标 = todos count 合计）；`renderNotificationCenter` 按Tab渲染——待办走 `/api/admin/notifications/panel`（`renderTodoCards` 与面板共用），通知走 `/api/admin/notifications?per_page=15&page=N`（`ApiResponse::paginate` 的 `items/pagination` 结构，`renderNoticeRows` + `noticePaginationMarkup`）；点击委托：special-tab 按所在窗口分流、待办 `markTodoRead`（与面板共用）、通知行 `POST {id}/read` 本地置灰 + `data-notice-link` 跳转、分页按钮重载。`activateWindowEntry` 排除该 special id；`renderNotifications` 同步刷新窗口侧栏徽标与待办列表。文档同步：版本记录更新 |
| 1.9.2 | 2026-09-30 | CmsPro | 安装记录命名与图标：新增 `recordAppName(appId)`（`findCatalogApp` 取应用名，回退 app_id），记录行 strong 与详情 head strong 改显应用名（详情 small 保留 app_id 对照）；`recordIcon` 改为复用 `applicationIconMarkup`（icon_url → icon 字段 → fa-cube 完整兜底链，全局 error 捕获自动降级），CSS 新增 `.record-row-icon-inner` 铺满圆角方块居中。文档同步：版本记录更新 |
| 1.9.1 | 2026-09-30 | CmsPro | 「查看全部」窗口化：footer 链接改为 `data-open-notification-page` 按钮，点击委托中合成 entry（`id: webos-notification-page, path: /admin/notifications, open_type: _iframe`）走 `openEntry()` 打开 WebOS iframe 窗口（复用窗口机制：windowKey 生成 `folder-webos-notification-page`，无子菜单自动折叠侧栏）。文档同步：版本记录更新 |
| 1.9.0 | 2026-09-30 | CmsPro | 通知中心：面板改为「Tabs + 内容区 + footer」结构；`renderNotifications` 拆分为 `renderNotificationTodos`（待办卡片：`link` 跳转 + `todo-read` 接口标记已读）与 `renderNotificationItems`（未读蓝点 + `compactNoticeTime` 相对时间）；`setNotificationTab` 前端切换。安装记录：`renderOperationLogs` 表格废弃，改为 `loadRecordsTab`（工具栏搜索 + `operation` 筛选参数）+ `renderRecordGroups`（今天/昨天/更早分组）+ `renderRecordDetail`（右侧详情，失败展示 `error_message`）；点击记录 `data-record-index` 委托切换详情；搜索为本地过滤保留输入焦点；废弃样式 `.records-table`/`.status-pill` 已移除。文档同步：版本记录更新 |
| 1.8.16 | 2026-09-30 | CmsPro | 卸载加载提示精简：`runUninstall()` 的加载层改为 `layer.load(2, { time: 0 })` 仅显示转圈图标（去掉文字提示）；删除流程保持文字提示不变。文档同步：版本记录更新 |
| 1.8.15 | 2026-09-30 | CmsPro | 卸载/删除执行反馈：`runUninstall()` 与 `runDeleteFiles()` 请求发起前 `layer.load(2, { content: '...', time: 0 })` 显示加载层（文案对标后台「正在卸载「x」，请耐心等待...」），成功/失败回调中 `layer.close(loadIndex)` 关闭。文档同步：版本记录更新 |
| 1.8.14 | 2026-09-30 | CmsPro | 卸载/删除确认按钮视觉反馈：input 委托中匹配成功时给提交按钮加 `is-armed` 类，CSS `.webos-button.danger:disabled`（`filter: grayscale(1); opacity: 0.45`）灰显、`.is-armed` 播放 `webos-button-armed` 脉冲动画（box-shadow 扩散 + scale）；安装快捷方式命名：`confirmInstall()` 中 `addDesktopEntry(Object.assign({}, entry, { title: target.name || entry.title }))`，桌面图标标题显示应用名称（桌面渲染直接使用 `desktop_items[].title`）。文档同步：版本记录更新 |
| 1.8.13 | 2026-09-30 | CmsPro | 备份管理弹层 `renderBackupDialog()` area 由 `['680px','480px']` 改 `['90%','90%']`；文档预览 `openDocViewer()` area 由 `['100%','100%']` 改 `['90%','90%']`；CSS 新增 `.doc-tree-dir span, .doc-tree-file span`（`white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0`）文件名超宽省略号截断。文档同步：版本记录更新 |
| 1.8.12 | 2026-09-30 | CmsPro | 卸载/删除对话宽度 660px：`openActionDialog(options)` 新增 `width` 参数（设置 `.action-dialog-card` 的 `style.width`，未指定时回退 CSS 默认 720px）；`openDisableFirstDialog`/`openUninstallDialog`/`openDeleteFilesDialog` 三处传 `width: 'min(660px, calc(100vw - 60px))'`。文档同步：版本记录更新 |
| 1.8.11 | 2026-09-30 | CmsPro | 已安装列表排序对齐后台：`sortInstalledApps()` 按 `install_time`/`update_time` 较新者降序、无时间排最后（复刻后台 `applySort` 默认规则），在 `renderInstalledList()` 渲染前执行；未安装卡片新增 `has-remove` 类（`padding-right: 42px`），操作按钮列整体左移避让右上角 × 删除按钮，解决遮挡。文档同步：版本记录更新 |
| 1.8.10 | 2026-09-30 | CmsPro | 修复“已安装”行菜单被窗口底部遮挡：`toggleAppRowMenu()` 渲染菜单后用 `getBoundingClientRect()` 判断按钮下方空间（按钮底部 + 菜单高度 + 8px 超过视口底部预留 12px 时），为菜单加 `.row-menu.is-above` 类向上弹出（CSS `top: auto; bottom: calc(100% - 6px)`）。文档同步：版本记录更新 |
| 1.8.9 | 2026-09-30 | CmsPro | 桌面「应用中心」图标不允许删除：`desktopContextMenuItems()` 对 `entry.id === 'webos-app-center'` 不再 push「删除图标」项；`removeDesktopEntry()` 增加 `webos-app-center` 拦截（toast「应用中心为内置入口，不允许删除」），覆盖入口管理面板等所有删除路径。文档同步：版本记录更新 |
| 1.8.8 | 2026-09-30 | CmsPro | 未安装列表操作补齐并修复卸载入口：① `appCardsMarkup()` local 模式卡片按钮区在“安装”下增加“导出”（`data-export-id` + `data-export-source="local"`，委托 `findLocalApp()` 取数据后复用 `exportAppPackage()`）；② 卡片右上角新增 `.app-card-remove` × 按钮（`display:none`，`.app-card:hover` 时显示），点击打开 `openDeleteFilesDialog()`（对标后台 `deleteAppFiles`：警告提示 + 输入应用名确认），`runDeleteFiles()` 调 `POST /api/admin/apps/{id}/delete` 后 `renderAppCenter('uninstalled')` 刷新；③ 修复卸载入口消失问题：行菜单/桌面右键/任务栏右键的 `!is_system` 条件改为 `Number(is_system) !== 1`（兼容接口返回字符串类型）；④ 启用状态拦截收敛进 `openUninstallDialog()`（桌面/任务栏右键去重），`openActionDialog()` 支持 `plain` 参数切换 `webos-dialog--plain` 皮肤，卸载确认、“请先禁用应用”引导、删除确认弹窗均为 layui 精简风格；⑤ 卸载/删除输入确认委托扩展为 `[data-uninstall-input], [data-delete-input]`。文档同步：版本记录更新 |
| 1.8.7 | 2026-09-30 | CmsPro | 修复窗口拖动/缩放结束闪动：`.app-window.is-window-gesturing` 原用 `animation: none` 移除入场动画，手势结束移除类后 `animation: webos-window-enter ... both` 重新应用并从头播放（透明度/位移变化），窗口出现闪动；改为 `animation-play-state: paused` 暂停动画，手势结束后动画不再重播（动画进行中抓取窗口时暂停、松手后继续，均无跳变）。文档同步：版本记录更新 |
| 1.8.6 | 2026-09-30 | CmsPro | 应用中心交互对齐传统后台：① `confirmInstall()` 安装成功后调用既有 `promptEnableApp()`（layer.confirm 询问启用，启用走 `/api/admin/apps/{id}/enable`，成功后 `reloadInstalledAppCenter()` 刷新），移除安装成功 toast；② 新增本地应用详情 `openLocalDetail()` / `findLocalApp()` / `localDetailMarkup()`（头部/基本信息/介绍复用市场详情布局与样式，图标走 `applicationIconMarkup`，基本信息仅本地字段，无截图/版本章节），`appCardsMarkup()`（local/updates 模式）与 `installedRowMarkup()` 的应用名称加 `data-local-detail` + `data-local-source` 属性，点击委托 `openLocalDetail`，操作按钮复用 `data-install-id` / `data-upgrade-id` 委托；CSS 新增 `.app-card-title` 可点击态。文档同步：安装弹窗与版本记录更新 |
| 1.8.5 | 2026-09-30 | CmsPro | 安装弹窗精简（layui 风格）：弹窗节点增加 `webos-dialog--plain` 皮肤（遮罩纯色、`backdrop-filter: none`、卡片 2px 圆角浅阴影）；视图移除 `install-options` 三选一 radio 与对应 CSS，改为“安装后默认添加到系统菜单”提示 + `install-create-shortcut` 可选复选框；JS `confirmInstall` 改读复选框（`installCreateShortcut.checked`）决定是否创建桌面快捷方式，菜单挂载逻辑不变，打开弹窗时重置勾选。文档同步：安装弹窗说明更新 |
| 1.8.4 | 2026-09-30 | CmsPro | 应用中心数据源全面复用系统接口：删除自建 `GET api/catalog` 接口（含 `AdminMenuCatalogService`、`ApplicationIconService` 及对应单测），已安装应用列表改用 `GET /api/admin/apps`、后台菜单目录改用 `GET /api/admin/menus/user`（前端 `filterAdminMenus` 按 `terminal_type` 过滤后台菜单）、安装记录改用 `GET /api/admin/app-logs?per_page=30` 按需加载；已安装列表排序跟随系统接口；图标兜底链简化为 `icon_url`（服务端 icon.svg/icon.png）→ 应用 `icon` 字段 → `fa fa-cube`，`config_groups` 判定与状态文案改由前端按系统接口字段计算。文档同步：工作区接口表与复用说明更新 |
| 1.8.3 | 2026-09-30 | CmsPro | 新增 `showModalDialog()` / `hideModalDialog()` / `trapModalFocus()` 统一管理原生 WebOS 模态弹窗焦点：打开后聚焦首个可用控件，Tab 正反向循环限制在弹窗内，Esc 只关闭顶层弹窗，关闭后通过 `_webosReturnFocus` 恢复触发位置。`closeAppRowMenus()` 在移除获得焦点的菜单前先聚焦对应“更多操作”按钮，确保后续弹窗能记录稳定的返回目标。 |
| 1.8.2 | 2026-09-30 | CmsPro | `createFrameScheduler()` 将窗口拖拽与八方向缩放的高频 `pointermove` 合并到 `requestAnimationFrame`，结束时刷新最后一帧，并处理 `pointercancel` / `lostpointercapture`；CSS 新增三档动效变量、窗口/面板/弹层入场动效与全局键盘焦点环，移除 `transition: all`，系统 `prefers-reduced-motion` 与 WebOS 动效开关均可关闭动画。应用市场以全局单调请求序号和 `AbortController` 在切换分类、搜索或离开市场时取消旧请求，防止迟到响应覆盖新结果。已安装列表调整操作层级、文本提示与状态按钮忙碌态；图片增加异步解码，远程市场图标增加懒加载。控制器以已发布 CSS/JS 的最新修改时间生成资源版本参数，避免应用升级后浏览器继续使用旧缓存。 |
| 1.8.1 | 2026-09-29 | CmsPro | 任务栏与账号菜单头像照抄后台 `layouts/admin.blade.php` 写法，`avatar ?: asset('Admin/images/avatar.png')` 始终渲染 `<img>`，未上传时显示系统默认头像；`bootstrapDesktopItems()` 初始数据只固定「应用中心」，不再按关键词挑选系统菜单；修复 `buildEntryTree()` 应用节点不收集应用菜单的问题（新增 `appNodeOf` / `collectAppMenu`，`fresh` 判据：首次创建应用节点时其根菜单子项直接铺开，同一应用后续顶级菜单以自身名称为子分组）；展开/收起应用节点时重渲染前后保持列表滚动位置；入口管理树的应用节点（`appNodeOf` 存 `application` 引用，`entryTreeNodeMarkup` 按节点类型分发：应用节点用 `applicationIconMarkup`、分支保持字体图标）与桌面入口行（`renderEntryManager` 改用 `entryIconMarkup(findEntry(item.id) || item)`）显示应用图标（icon_url → manifest_icon 兜底链与全局规则一致，纯菜单项保持字体图标）；`webos.css` 补 `.entry-row .entry-row-icon` / `.entry-tree-toggle .start-app-item-icon` / `.entry-tree-toggle .entry-tree-app-icon` 38px 尺寸规则。备份弹窗工具栏照抄后台移到顶部并补齐“导入恢复”：`renderBackupDialog` 顶部渲染 `backupToolbarMarkup(count, appId, appName)`（左「共 N 条备份记录 + 导入恢复」/ 右「立即备份」）、列表在下，`#dlgBtnImportBackup` 绑定 `openImportDialog`；移植后台 `openImportDialog` / `bindImportDialogEvents` / `loadServerBackupFiles` / `doLocalRestore`（上传文件与从服务器选择两个 Tab，`.zip` 上传 `/backups/upload-file` 成功后 `doRestoreWithProgress({ local_file_name })` 分步恢复，服务器列表懒加载 `GET /backups/local-files`，隐藏 fileInput 随弹层 `remove` 清理）。文档同步：入口管理与账号菜单描述更新 |
| 1.8.0 | 2026-09-29 | CmsPro | 应用中心「已安装」的导出、手动升级、备份、文档四项操作照抄传统后台 `admin/app/index.blade.php` 实现：导出改 XHR blob 并解析 `Content-Disposition`、识别 JSON 错误体；上传安装与手动升级共用拖拽/点选弹层（`fileInput` 随弹层 `end` 销毁，删除桌面页静态 `#app-package-input`）；备份改三步分卷并新增三步恢复（单表最多重试 3 次）、下载与删除；文档改文件树 + 正文 + 目录三栏预览，代码块接入 highlight.js。新增公共 helper `layuiLayer` / `layuiJquery` / `legacyAjax` / `formatSize` / `showErrorDialog` / `resolveBlobFileName` / `saveBlobAsFile` / `readBlobError`；用 `backupLayerStack` 替代后台的 `layer.closeAll()` 以免误关应用窗口；清理 `handleAppCenterAction` 的 7 个失效委托分支、`state.actionApp`（改 `state.docAppId`）、`elements.packageInput` 与孤儿函数 `downloadFile`。文档同步：桌面页资源环境补充 `marked` / `highlight.js`，“复用系统接口”一节新增四项操作的接口与调用要点对照表 |
| 1.7.1 | 2026-09-29 | CmsPro | 对齐 `_component`（路由模式）片段的资源环境：桌面页新增加载 `CmsProUi/component/pear/pear.js`，补上 `layui.config({ base })` 与 `extend` 映射，片段内 `layui.use` 的 pear 扩展模块不再 404；新增预热 `element` / `form` / `jquery` / `layer` / `toast` / `button` / `popup` 七个无 DOM 副作用的模块（框架级 `admin` / `menu` / `tabPage` 等不预热）。`evalInlineScripts` 重命名为 `runFragmentScripts` 并拆出 `runScriptNode`，用 Promise 串行链按文档顺序执行片段脚本、外链脚本等 `load` / `error` 后放行，与后台 jQuery `.html()` 语义一致；`loadComponentPage` 改为脚本执行完毕后再调 `initLayuiComponents`。文档同步：`_component` 行为与资源环境说明、前端扩展原则第 2 条修订并新增第 14 条 |
| 1.7.0 | 2026-09-29 | CmsPro | 窗口左侧菜单支持后台声明的四种打开方式：`_iframe` 渲染进新增的 `[data-window-page-host]` 容器；`_component` 由 `fetchText` 拉取 HTML 片段注入，`evalInlineScripts`（1.7.1 起更名为 `runFragmentScripts`）重建脚本节点使其执行、`initLayuiComponents` 重渲染 layui 组件；`_blank` 用 `window.open` 打开新标签页，新增 `externalPath` / `openablePath` 放行 `http(s)` 外链；`_layer` 由 `openLayerWindow` 调 layui 弹层打开。`openMenuByType` 统一分流，`defaultEntryOf` 让默认入口跳过 `_blank` / `_layer`。文档同步：`open_type` 取值对照表、接入要求与前端扩展原则第 1 / 2 / 5 条，并新增第 12 / 13 条 |
| 1.6.0 | 2026-09-29 | CmsPro | 窗口左侧菜单改为按菜单声明层级渲染的树：新增 `windowNavTree` / `windowNavNodesMarkup` / `windowNavExpanded` / `revealActiveNavBranch` / `collapseNavSiblings` / `toggleWindowNavBranch` / `rerenderWindowNav`，含 `children` 的菜单渲染为可展开收起的分组（同级手风琴互斥，自动展开当前页所在链路），分组显隐由 `.window-nav-group.is-open` class 控制；移除已无引用的 `siblingEntries`，默认收起判定改用 `countNavLeaves`；侧栏宽度 146px → 180px |
| 1.5.5 | 2026-09-29 | CmsPro | 新增 `WallpaperService`（`listFor` / `store` / `delete`，构造参数 `$directoryOverride` 供测试注入临时目录）与壁纸接口 `GET api/wallpapers`、`DELETE api/wallpaper`（`POST api/wallpaper` 于 1.5.0 引入，此处一并登记）；背景设置“当前背景”改为左右布局并在右侧渲染自定义壁纸库，支持缩略图切换与内联二次确认删除 |
| 1.4.8 | 2026-09-29 | CmsPro | 卸载应用后前端按 `app_id` 清理工作区 `desktop_items` 并持久化；“请先禁用应用”提示中禁用成功后自动弹出卸载确认；任务栏左/右停靠时开始面板改为顶部对齐开始按钮（纯 CSS）；日历面板接入农历与节气数据展示；目录接口 `applications` 排序对齐后台“应用管理”（install_time/update_time 较新者降序，无时间排最后）；应用市场新增分类 Tab（Ajax 加载 `/api/admin/market/categories`，切换分类按 `category` 参数重新分页）与滚动到底自动加载下一页（每页 20，状态行按 `pagination.total` 显示总数） |
| 1.4.7 | 2026-09-29 | CmsPro | 工作区偏好新增 `window_width`、`window_height`（百分比，40-100），供 OS 设置调整新建窗口默认尺寸 |
| 1.4.6 | 2026-09-29 | CmsPro | 账号菜单移除任务栏位置设置；个人设置复用后台 `/admin/account` 页面；修改密码复用系统 `PUT /api/admin/auth/password` 接口 |
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
