# CmsPro WebOS 管理桌面 · 扩展指南

> 文档版本：1.8.2 | 更新日期：2026-09-30
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
| GET | `/admin/cmspro/webos/api/catalog` | 读取有权的后台 `admin` 菜单、已安装应用和最近操作记录 |
| POST | `/admin/cmspro/webos/api/wallpaper` | 上传自定义壁纸（`multipart/form-data`，字段 `wallpaper`，jpg/jpeg/png/gif/webp，≤5MB），返回相对路径 `url` |
| GET | `/admin/cmspro/webos/api/wallpapers` | 读取当前管理员的自定义壁纸列表（`url`、`size`、`uploaded_at`） |
| DELETE | `/admin/cmspro/webos/api/wallpaper` | 删除自定义壁纸（JSON 字段 `url`），非本人或非法路径返回业务码 `40302` |

接口使用后台 Session 认证和 CSRF 保护，返回统一的 `code`、`message`、`data`、`timestamp` 结构。目录接口中的每个应用包含 `icon_url`、`manifest_icon`、`has_config`、`is_system`、`status` 等字段，供前端渲染图标与应用操作。

WebOS 不新增应用管理接口：应用中心的市场安装、本地安装、启用/禁用、卸载、导出、备份、文档、配置和上传安装都直接调用系统已有的 `/api/admin/apps/*`、`/api/admin/market/*` 接口。页面同时通过 `data-market-base-url` 把系统应用市场地址（`apps.market.api_url`，协议相对形式）传给前端，用于解析远程市场图标。

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
