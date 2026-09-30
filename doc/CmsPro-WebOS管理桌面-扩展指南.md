# CmsPro WebOS 管理桌面 · 扩展指南

> 文档版本：1.8.3 | 更新日期：2026-09-30
> 适用应用：CmsproWebos v1.5.5+

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

## 四、工作区接口

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/admin/cmspro/webos/api/workspace` | 读取当前管理员工作区 |
| PUT | `/admin/cmspro/webos/api/workspace` | 保存当前管理员工作区 |
| POST | `/admin/cmspro/webos/api/wallpaper` | 上传自定义壁纸（`multipart/form-data`，字段 `wallpaper`，jpg/jpeg/png/gif/webp，≤5MB），返回相对路径 `url` |
| GET | `/admin/cmspro/webos/api/wallpapers` | 读取当前管理员的自定义壁纸列表（`url`、`size`、`uploaded_at`） |
| DELETE | `/admin/cmspro/webos/api/wallpaper` | 删除自定义壁纸（JSON 字段 `url`），非本人或非法路径返回业务码 `40302` |

接口使用后台 Session 认证和 CSRF 保护，返回统一的 `code`、`message`、`data`、`timestamp` 结构。

WebOS 不新增应用管理接口，应用中心的数据与操作全部复用系统已有接口，与传统后台应用管理页面（`resources/views/admin/app/index.blade.php`）保持同一数据源，便于统一维护：

| 数据 | 系统接口 |
|---|---|
| 已安装应用列表 | `GET /api/admin/apps`（含 `icon_url`、`is_system`、`status`、`manifest` 等完整字段） |
| 后台菜单目录 | `GET /api/admin/menus/user`，前端按 `terminal_type` 过滤仅保留 `admin` 后台菜单 |
| 操作记录 | `GET /api/admin/app-logs?per_page=30`（分页接口，切换到「安装记录」时按需读取） |
| 应用操作 | `/api/admin/apps/*`、`/api/admin/market/*`：市场安装、本地安装、启用/禁用、卸载、导出、备份、文档、配置和上传安装 |

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

1. 新应用页面保持完整 HTML 结构，确保可在 WebOS iframe 中独立渲染；菜单声明为 `_component`（路由模式）时，同一地址应能返回可直接注入的 HTML 片段。
2. 页面不得依赖父窗口中的全局变量；权限脚本应在自身页面注入。`_component` 片段是例外，它运行在 WebOS 主文档中并共享全局作用域，可依赖桌面页已加载的 layui / pear 环境，但需自行避免变量与样式冲突。
3. 与 WebOS 的交互优先依赖菜单和系统 API，不直接访问其内部 DOM。
4. 静态资源必须本地化，不通过 CDN 加载。
5. WebOS 窗口按 `app_id` 复用：同一应用的不同菜单只在窗口内容区内切换（iframe 重载或片段重新注入），不重复创建窗口，窗口位置与尺寸保持不变。
6. 若菜单缺少 `app_id`，WebOS 会将第二级系统菜单视为文件夹；顶级菜单下的直属叶子菜单聚合为顶级同名文件夹。新应用应正确关联 `app_id`，不要依赖系统菜单回退行为。
7. 同一 `app_id` 只有一个可访问叶子菜单时，WebOS 默认隐藏窗口左侧菜单；声明多个后台菜单时默认展开，用户可在标题栏手动收起。
8. 在 `manifest.json` 中用 `children` 声明多级菜单时，WebOS 窗口左侧会按同样的层级渲染为树：含下级的节点显示为分组，点击分组标题展开或收起，同级分组互斥（手风琴），打开窗口时自动展开当前页面所在的分组链路。中间分组不再被丢弃，但分组自身若声明了 `path` 仍不会作为可点击项渲染，需要访问的页面请放在叶子菜单上。
9. 普通应用窗口标题按 `icon.svg`、`icon.png`、`manifest.json.icon` 顺序显示应用图标；应用记录的 `is_system` 为真时保留 CMSPRO Logo。
10. 桌面快捷方式、任务栏图标与开始菜单卡片同样按第 9 条的顺序解析应用图标；不带 `app_id` 的系统菜单保留菜单自带图标。
11. 应用如需在 WebOS 中提供“设置”入口，应在 `manifest.json` 声明 `config_groups`；配置项类型继续使用系统的 `text`、`textarea`、`number`、`select`、`switch`、`image`，WebOS 设置弹窗会按同样类型渲染。
12. 声明为 `_blank` 或 `_layer` 的菜单不占用窗口内容区：点击后只打开新标签页或 layui 弹层，窗口内已显示的页面与侧栏选中态保持不变，也不会被记为“当前页面”。这类菜单在开始菜单聚合与应用市场“打开”按钮的默认入口选择中会被跳过。
13. `http(s)` 外链菜单只有声明为 `_blank` 时才会出现在 WebOS 窗口左侧菜单中；外链入口不能被添加为桌面快捷方式，也不显示在应用中心的“入口管理”树中（桌面路径由后端 `WorkspaceService` 校验，仅接受站内路径）。
14. `_component` 片段不应重复引入 `layui.js`、`pear.js`、`pear.css`、jQuery 等主框架资源（桌面页已加载，重复引入会覆盖 `layui.config` 并破坏模块解析）。片段只输出业务结构、业务样式与业务脚本；组件渲染需在脚本中自行调用 `element.init()` / `form.render()`，WebOS 也会在全部脚本执行完毕后再补调一次。

## 七、数据与卸载注意事项

- 工作区表仅存菜单路径、图标类名、界面偏好和入口使用统计，不复制业务数据。
- 目标应用卸载后，历史桌面入口可能暂时保留；用户可在入口管理中移除。后续版本可通过系统生命周期钩子增加自动清理。
- 卸载 WebOS 会删除 `app_cmspro_webos_workspaces` 表，因此应在卸载前备份需要保留的布局。

## 八、版本与更新日志

| 版本 | 日期 | 更新人 | 说明 |
|---|---|---|---|
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
