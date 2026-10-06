# CmsPro WebOS 管理桌面 · 特性清单

> 文档版本：2.0.2 | 更新日期：2026-10-06
> 应用版本：2.0.2 | 应用 ID：`cmspro.webos`

## 一、应用定位

WebOS 管理桌面是 CmsPro 后台的可选交互层，不替换系统后台框架。应用读取当前管理员可见菜单，并通过同源 iframe、多窗口和任务栏提供桌面式操作体验。

## 二、功能清单

| 功能 | 说明 | 数据来源 |
|---|---|---|
| 桌面快捷方式 | 添加、移除、拖动后台菜单入口；右键提供打开应用、重命名、删除图标和卸载应用；桌面空白处右键提供刷新、查看（大/中/小图标三档子菜单，当前档位勾选，默认中图标，随工作区持久化）、设置背景、个性设置和显示桌面（仅桌面区域生效，窗口、任务栏、面板内不触发）；停用应用图标不占格位，应用重新启用后坐标冲突自动落空白格 | 当前管理员菜单 + WebOS 工作区 + 系统应用管理 API |
| 开始菜单 | 仅展示 `admin` 后台顶级菜单；应用按 `app_id` 聚合，系统菜单按文件夹聚合，可搜索和固定应用入口；菜单可见性与框架后台对齐（仅授权子菜单时自动补全祖先链），图标按「已分配」过滤 | 系统菜单接口 `GET /api/admin/menus/user` + 前端后台菜单过滤 + 系统应用管理 API |
| 常用入口 | 按当前管理员的打开次数降序、最近打开时间降序显示最多 6 个入口 | 工作区 `preferences.usage_stats` |
| 系统菜单文件夹 | 点击文件夹后打开独立窗口，左侧按层级树形显示该文件夹子菜单（下级分组可展开收起）并默认打开第一项可由窗口内容区承载的菜单 | 当前管理员菜单 |
| 应用独立窗口 | 每个应用只创建一个窗口；普通应用显示自身图标，系统应用显示 CMSPRO Logo；左侧菜单按声明层级树形显示、分组可展开收起，整栏支持收起；菜单按后台声明的四种打开方式（嵌套网页、路由模式、新建窗口、弹窗网页）分别承载；应用有前台（home）菜单时标题栏「收起左侧菜单」左侧显示「前台」下拉按钮（文字按钮，下拉项仅菜单名，点击新标签页打开）；可开启应用窗口多选项卡（同路径复用、切换不重载、溢出导航、右键关闭菜单） | 浏览器运行时 + 应用目录 |
| 任务栏 | 紧凑展示固定入口、运行窗口、通知、账号和时钟，可设为上、下、左、右；运行与固定图标支持右键菜单（打开、还原、最大化、最小化、关闭、固定/解除固定、卸载）；已固定应用运行时复用固定图标（按应用聚合，显示运行指示，点击聚焦/最小化切换），不在运行区重复显示 | 工作区 + 系统接口 |
| 应用市场 | 内容区顶部提供「首页 / 分类」子 Tab（默认首页）：首页聚合展示「不可错过的应用」（随机 2 行图标+名称网格）、「官方精选」（精选应用优先、不足 2 行按下载量补全）、「装机必备/官方精选/运营推荐」推荐位列表区（每列 5 个，图标+名称+两行描述，读取市场服务端推荐位）与「辅助推荐」网格区（推荐位 aux，2 行）；分类子 Tab 为原有分类浏览：分类以 Tab 形式展示（Ajax 加载分类接口），列表滚动到底自动分页加载，状态行显示连接与总数，失败时降级到本地可安装应用；图标读取市场远程地址，并按本机版本显示安装 / 已安装 / 更新；点击应用卡片打开应用详情页（首页卡片同样可点击） | 系统应用市场 API + 市场首页聚合接口 `/api/admin/market/home` + `apps` 表 |
| 应用详情 | 覆盖在应用中心面板上展示市场应用详情：头部（图标、名称、推荐标记、应用文档与作者链接、安装/更新按钮）、基本信息、应用介绍、应用截图与版本记录 | 系统应用市场详情 API `/api/admin/market/apps/{appId}` |
| 安装应用 | 自动识别 `admin`、`user`、`home` 菜单声明，按实际终端分别选择顶级菜单挂载位置，安装后可创建后台桌面入口 | 系统安装 API |
| 已安装应用 | 以列表展示应用信息、状态开关与操作；支持状态筛选、搜索和上传安装；图标按 icon_url（服务端 SVG/PNG）→ 应用 icon 字段 → 通用占位回退，SVG/PNG 图片图标以白色为底、字体图标保持彩色底；数据直接来自系统应用管理接口 `GET /api/admin/apps`，与传统后台“应用管理”同一数据源与排序 | 系统应用管理 API + 应用根目录 |
| 应用操作 | 操作列直接提供打开、手动升级和导出；更多菜单复用系统应用管理接口提供管理入口、备份、文档、设置和卸载。四项操作的交互与传统后台“应用管理”页面一致：导出走 XHR blob 下载、手动升级与上传安装共用拖拽/点选弹层、备份为三步分卷并支持三步恢复、删除与导入恢复（弹窗顶部工具栏照抄后台：左「共 N 条备份记录 + 导入恢复」/ 右「立即备份」）、文档为文件树 + 正文 + 目录三栏预览 | 系统应用管理 API |
| 未安装应用 | 重新列出本地未安装应用并提供安装入口；图标回退规则与已安装列表一致 | 系统本地应用 API + 应用根目录 |
| 应用更新 | 检查并执行应用更新；打开应用中心窗口时静默检查一次，左侧菜单「应用更新」右上角按可用更新数量显示红色角标（超过 99 折叠为 `99+`，无更新或检查失败不显示） | 系统更新 API |
| 安装记录 | 展示最近 30 条应用操作记录，切换到该界面时按需读取系统操作日志接口 | 系统操作日志 API `/api/admin/app-logs` |
| 入口管理 | 集中管理桌面快捷方式和可用菜单；“可用菜单”以树结构展示（应用聚合文件夹节点、系统菜单层级分支，默认收起，叶子显示菜单图标），展开应用节点后显示该应用的菜单；应用节点与桌面入口行复用 `entryIconMarkup` / `applicationIconMarkup` 显示应用图标（icon_url → 应用 icon 字段兜底，与全局图标规则一致） | 工作区 + 系统菜单接口 + 系统应用管理 API |
| 日历 | 点击任务栏时间弹出，按月展示并支持翻月与选择日期，日期格标注农历与二十四节气，“回到今天”仅在选择非今天日期时显示 | WebOS 日历接口（农历换算） + 浏览器本地时间 |
| 官网动态窗口 | 仅超级管理员桌面自动打开：固定 600×500 停靠桌面最右侧并垂直居中，可最小化/最大化/关闭；展示官网公开接口最新 6 条动态（正文链接化、图片网格、发布时间），点击图片用 layer.photos 相册层全屏预览（同一条动态多图可左右切换，layui 异常回退新标签页），「更多」新标签页打开官网；自动打开不计入使用统计 | 官网公开接口 `api/home/moments/latest?limit=6`（与框架后台 dashboard 同源） |
| 通知中心 | 显示系统推送与待办，支持全部已读 | 系统通知 API |
| 系统操作 | 开始菜单底部提供锁定、退出登录、OS 设置；锁定与退出登录之间显示版权信息条（CMSPRO 链接 + 系统版本号 + © 2015-当年动态年份 + Holley 链接，与框架后台页脚一致）；桌面页面标题动态显示「CMSPRO v{系统版本} · WebOS v{应用版本}」 | 系统认证 API + 工作区 |
| 账号菜单 | 头像显示当前管理员头像，未上传时显示系统默认头像；个人设置在独立窗口打开后台个人中心页；修改密码通过弹窗修改；提供锁定桌面与退出登录 | 系统后台页面 + 系统认证 API |
| OS 设置 | 分“基本设置”（任务栏位置、时钟格式、秒数、界面动效、窗口默认宽高比例）、“背景设置”（壁纸风格、自定义壁纸上传/恢复默认、当前壁纸预览 + 右侧自定义壁纸库：缩略图点击切换、内联二次确认删除）与“系统设置”（应用窗口多选项卡开关、覆盖传统后台、重置工作区）三个 Tab | 工作区偏好 + 壁纸目录 |

## 三、交互规则

1. 双击桌面图标打开窗口；拖动图标后自动保存位置；右键桌面图标打开操作菜单（打开应用、删除图标、卸载应用），菜单在点击其他位置或按 Esc 时关闭。桌面入口保存所属应用标识 `app_id`，应用禁用后仍可从图标识别所属应用并进入卸载流程；系统菜单入口不提供卸载项。
2. 开始菜单中的图钉按钮用于添加或移除桌面快捷方式；固定应用时桌面入口名称取应用名称，而不是该应用第一个菜单的名称。
3. 同一应用只保留一个运行窗口；打开该应用的其他菜单时，会在原窗口内切换内容和选中菜单。打开方式为“新建窗口”或“弹窗网页”的菜单例外：点击后只打开新标签页或弹层，窗口内容与侧栏选中态均保持不变。
4. 桌面窗口的内容区仅加载以 `/` 开头的站内路径，拒绝外部地址进入 iframe；打开方式为“新建窗口”的菜单额外放行 `http(s)` 外链，其余伪协议与协议相对地址一律拒绝。
5. 本地和市场安装沿用 CmsPro 安装器。弹窗根据应用清单自动显示后台、用户端、前端菜单挂载项；未声明的终端不显示，不选择父级则安装为独立顶级菜单。
6. 开始菜单左侧仅保留后台 `admin` 顶级菜单层级，不显示 `user`、`home` 菜单；右侧相同 `app_id` 的后台菜单只显示一个应用卡片。
7. 不属于应用的后台菜单按第二级菜单聚合为文件夹；顶级菜单下的直属叶子菜单聚合为同名文件夹。点击后默认打开文件夹内第一项可由窗口内容区承载的菜单。
8. “常用”最多显示 6 个入口，按打开次数和最近打开时间排序；统计数据按管理员隔离。
9. 窗口左侧菜单按后台声明的层级树形渲染：含下级的菜单显示为分组，点击分组标题展开或收起，同级分组互斥（手风琴）；首次打开和切换菜单时自动展开当前页面所在的分组链路。只有一个可访问叶子菜单时默认收起整栏，用户可通过标题栏按钮随时展开或再次收起。
10. 普通应用窗口标题使用应用根目录图标并回退到清单图标；`is_system` 应用、系统菜单文件夹和 WebOS 内置窗口使用 CMSPRO Logo。窗口标题栏中的 SVG/PNG 图片图标以白色为底，字体图标保持原彩色底，系统 CMSPRO Logo 保持透明底。
11. 桌面快捷方式、任务栏图标与开始菜单应用卡片统一使用应用根目录图标（`icon.svg` → `icon.png` → 清单 `icon`）；无 `app_id` 的系统菜单保留菜单图标。
12. 应用市场图标使用远程市场返回的 `icon`，相对路径按系统应用市场地址拼接；远程图标加载失败时降级为通用占位图标。
13. 已安装列表的状态开关直接启用或禁用应用；状态列只显示开关，状态文字通过提示与无障碍标签表达；卸载前必须先禁用应用，系统返回“请先禁用应用”时 WebOS 会引导先禁用，在提示中点击“禁用应用”成功后会自动弹出卸载确认界面。卸载成功后自动从当前管理员工作区移除该应用的桌面快捷方式与任务栏固定图标并持久化。
14. 任务栏停靠在左侧或右侧时，开始菜单从开始按钮下方展开，水平方向紧贴任务栏；停靠在上方或底部时保持原位置。
15. 应用操作（管理入口、备份、文档、手动升级、导出、设置、卸载）与上传安装全部调用系统应用管理接口，WebOS 不重复实现安装器逻辑；导出、手动升级、备份、文档四项的交互与传统后台“应用管理”页面（`resources/views/admin/app/index.blade.php`）保持一致。
16. 状态筛选仅出现在“已安装”界面，且只提供全部状态、已启用、已禁用；应用市场与未安装界面只保留搜索。
17. 由应用中心自身弹窗容器承载的操作弹窗（管理入口、设置、卸载）高度不超过视口，内容过长时只滚动弹窗主体，标题、关闭按钮和底部操作始终可见；备份与文档改用 layui 弹层承载（需要多层叠加与全屏三栏布局），弹层关闭只影响自己打开的层级，不会误关应用窗口。
18. 开始菜单、通知中心、账号菜单和日历展开后，点击面板外部（桌面空白区域、桌面图标、任务栏、应用窗口）会收起面板；面板内部交互不收起，任务栏上对应的按钮仍可反复开合。
19. 任务栏时间按方位分行：上/下为「年月日」+「周几 时间」两行，左/右为「年月日」「周几」「时间」三行，上/下停靠时年月日居中显示；时钟、日期与星期按管理员的时间格式偏好渲染。
20. 账号菜单只保留个人设置、修改密码、锁定桌面和退出登录；任务栏位置统一在 OS 设置中调整，修改密码复用系统 `PUT /api/admin/auth/password`，密码规则与原后台一致（6-20 位、不能与原密码相同、两次一致）。
21. 窗口默认宽高以可用桌面区域（不含任务栏）的百分比保存，取值 40–100（默认 78 / 80），仅作用于新建的应用窗口；WebOS 内置窗口（应用中心、OS 设置）不受该偏好影响，始终按出厂默认 78% / 80% 打开；窗口最小尺寸为 420 × 320 像素，手动缩放（右下角拖拽）仍可覆盖默认值。
22. 应用市场仅远程来源（`_source === 'market'`）的卡片可点击进入详情页，本地降级卡片不提供详情入口；详情页以覆盖层形式渲染，先用列表已加载的数据即时展示，再通过详情接口补全，接口失败时保留已缓存信息并提示错误。详情页内的安装与更新复用市场列表的点击委托，点击“返回列表”或切换应用中心 Tab 关闭详情页，列表的分页进度与滚动位置保持不变；应用截图以单行横向轨道展示，超出部分通过轨道两侧箭头左右切换（滚动到边界或未溢出时对应箭头自动禁用），点击缩略图打开全屏预览层并可在预览中左右切换（支持 `←` / `→` 方向键，`Esc` 或点击遮罩关闭），不再新标签页打开原图；预览层大图可用区域为视口的 90% × 90%（`.shot-viewer` 内边距 `5vh 5vw`，`.shot-viewer-stage` 上限 `90vw` × `90vh`，图片 `max-height: calc(90vh - 32px)` 预留页码高度后等比缩放，不拉伸变形），左右切换按钮为绝对定位叠加在预览层两侧（脱离文档流，不再作为 flex 兄弟节点挤占舞台宽度）；缩略图加载失败自动移除并刷新箭头可用态，预览大图加载失败时显示文字占位，切换到其他截图后自动恢复。
23. 自定义壁纸库仅在切到“背景设置” Tab 时按需加载并缓存，上传成功后清空缓存以便重新拉取；列表只包含当前管理员本人上传的壁纸（按文件名 `admin_{id}_` 前缀判定），正在使用的壁纸以高亮边框与对勾标记。点击缩略图直接写入 `wallpaper_url` 偏好并即时生效；删除采用卡片内联二次确认（不使用原生 `confirm`），同一时刻只允许一个卡片处于确认态，点击“取消”或切换到其他卡片的删除按钮会收起当前确认态。删除的壁纸若正是当前背景，会自动清空 `wallpaper_url` 回退系统默认壁纸；列表读取失败时不伪装为空列表，而是显示错误提示与“重新读取”按钮。
24. 窗口左侧菜单支持后台 `admin_menus.open_type` 声明的四种打开方式：**嵌套网页**（`_iframe`，默认值，窗口内容区以 iframe 承载完整页面）、**路由模式**（`_component`，AJAX 拉取 HTML 片段直接注入内容区容器，注入后按文档顺序串行执行片段脚本、外链脚本等 `load` / `error` 后放行，脚本全部执行完毕再调用 `layui.element.init()` / `layui.form.render()` 重渲染组件）、**新建窗口**（`_blank`，`window.open` 打开新标签页并带 `noopener`）、**弹窗网页**（`_layer`，`layer.open({ type: 2, area: ['80%', '80%'], maxmin: true })`，layui 未就绪时降级为新标签页）。非法或历史空值兜底为 `_iframe`。iframe 与片段注入共用同一 `[data-window-page-host]` 容器，以 `_iframe:path` / `_component:path` 作为幂等键，两种承载方式互相切换时只重建内容区，不重建窗口，窗口位置、尺寸与焦点保持不变；片段请求带竞态令牌，用户已切走后到达的过期响应会被丢弃，请求失败时内容区显示错误提示，401 跳转登录页。
25. 桌面页的资源环境与传统后台 `layouts/admin.blade.php` 对齐，`_component`（路由模式）片段可直接复用后台的 CSS 与 JS：`pear.css`、`font-awesome 4.7`、`admin.css`、`variables.css`、`reset.css`、全量版 `layui.js`、`pear.js` 均已加载，`window.CMSPRO_PERMISSIONS` / `window.hasPermission` 亦已注入，片段可 `layui.use` 任意 pear 扩展模块（`toast` / `button` / `popup` / `dtree` / `echarts` / `tinymce` 等）。桌面页启动时预热 `element`、`form`、`jquery`、`layer`、`toast`、`button`、`popup`；`admin`、`menu`、`tabPage`、`page`、`menuSearch`、`messageCenter` 等会操作后台侧栏与选项卡 DOM 的框架级模块不预热。与后台一致，桌面页不向全局暴露 `$` / `window.jQuery`。`admin.dark.css` 不加载（其选择器全部以 `.pear-admin-dark` 前缀限定，与 WebOS 根元素 `.webos-desktop` 永不匹配）。片段自带的 `<link>` / `<style>` 注入即生效，自带的 `<script>` 按文档顺序串行执行。
26. 桌面空白处右键「查看」子菜单提供大 / 中 / 小图标三档：默认中图标（与历史布局一致），当前档位带勾选标记，切换后按新档位重排桌面并随工作区持久化（非法值回退中档）；停用（禁用）应用的图标不占格位，其它图标可移入其格子，应用重新启用后若原坐标被占用自动落到第一个空白格（未占用则回原位）。
27. 应用窗口多选项卡：偏好 `window_tabs` 默认关闭，仅对开启后新打开的应用窗口生效（内容区存在 `[data-window-page-host]` 才启用，应用中心 / OS 设置 / 通知中心 / 官网动态等特殊窗口天然排除）；点击左侧菜单以选项卡打开（选项卡条在标题栏左侧、窗口按钮贴最右），同一路径复用既有选项卡，切换仅改 `.window-page` 容器显隐、不重载页面（表单输入、滚动位置、iframe 状态保留）；选项卡溢出时出现 `<` / `>` 导航箭头（激活项自动滚入可视区）；选项卡右键弹出「刷新页面 / 关闭当前 / 关闭其它 / 关闭全部」（刷新页面重新加载当前激活页——iframe 重设 src / 组件重新拉取，对齐框架后台刷新按钮；刷新/关闭动作经 runDesktopContextAction 分发白名单路由，右键同时聚焦所在窗口）；品牌区应用名固定，`small` 副标题实时跟随当前激活选项卡的菜单名，全部关闭后副标题清空。
28. 前台菜单下拉：应用声明 `home_menus`（前台终端菜单）时，窗口标题栏「收起左侧菜单」按钮左侧注入固定 58px 宽的文字按钮「前台 ▾」；数据来自系统菜单树接口 `GET /api/admin/menus/tree?terminal_type=home`，按窗口应用的 `app_id` 收集叶子并按应用缓存；下拉项仅显示菜单名称，点击以新标签页打开前台地址；系统应用与无前台菜单的应用不显示，读取失败静默降级。
29. 权限对齐：普通管理员仅授权应用子菜单未授权父级分组时，WebOS 目录自动补全祖先链（框架 `MenuService` 行为，与框架后台菜单完全一致）；桌面/任务栏图标按「已分配」过滤，角色收回授权后对应图标不再显示；应用中心入口与应用中心 / WebOS 升级提醒仅对超级管理员（super_admin）生效，非超管工作区重置后桌面为空。
30. 官网动态窗口：仅超级管理员进入桌面时自动打开（内置入口 `webos-official-news`，特殊窗口无 `[data-window-page-host]`，不启用多选项卡）；固定 600×500 停靠桌面最右侧并垂直居中，可最小化/最大化/关闭；数据来自官网公开接口（前端直连，窗口关闭后异步返回不再写入 DOM）；自动打开不计入使用统计（`recordEntryUsage` 排除）。
31. 前台菜单链接域名绑定兼容：前台下拉链接经应用侧接口 `GET /admin/cmspro/webos/api/home-menu-urls?app_ids[]=` 换算（复用框架 `menu_path()`）——域名绑定应用返回绑定域名根（如论坛 `/forum` → `https://forum.xxx.com/`）、普通应用返回主站地址（兼容子域名部署）、外链原样返回；转换失败回退原始路径；应用侧零声明，安装即自动兼容。
32. 系统菜单目录图标：开始菜单目录项优先使用目录/分组菜单自身设置的图标，无图标时才以文件夹图标（fa-folder）兜底，不再统一硬编码文件夹图标；iconPicker 短代码别名（IconSetting/IconUser/IconShield/IconMenu/IconImage/IconFile）经 safeIcon 的 ICON_ALIASES 映射自动转为 layui 图标类名（与框架 ConfigController 一致），图标输出唯一出口，全站渲染点同步生效。
33. 全部功能（启动台）：开始菜单「全部功能」按钮（data-open-special="entries"）点击打开 macOS Launchpad 风格**全屏**浮层——高不透明深色遮罩（**不用 backdrop-filter 毛玻璃，低配设备全屏模糊易卡顿**）+ 顶部居中搜索框（跨层级按菜单名/应用名平铺匹配叶子）+ **与系统菜单同源的菜单树层级导航**：按顶级分类逐层进入，目录节点以文件夹卡片显示（目录自身图标优先、fa-folder 兜底），叶子以应用图标卡片显示（**与开始菜单同标准**：app_id 取 flattenMenus 祖先继承值 + 开始菜单同款 entryIconMarkup 渲染，icon_url 图片 → icon 字体；无应用回退菜单自身图标），点击文件夹进入子级、点击叶子经 findEntry → openMenuByType \|\| openEntry 打开窗口并关闭浮层，面包屑（全部功能 / 一级 / 子级）点击任意层级返回，Esc 或点击空白处关闭；层级 7500（锁屏之下、窗口之上）。图片图标与字体图标统一为 44px 图标容器（`is-app-icon` 由 applicationIconMarkup 图片分支源头声明，所有渲染位共享等比缩放 object-fit: contain，禁用原生拖拽），卡片尺寸一致不堆叠。
34. 双击窗口标题栏空白/品牌区切换最大化还原（Windows 桌面惯例）；控制区按钮（最小化/最大化/关闭/前台菜单/选项卡）双击不触发。

## 四、数据结构

### `app_cmspro_webos_workspaces`

| 字段 | 类型 | 说明 |
|---|---|---|
| `id` | BIGINT | 主键 |
| `admin_user_id` | BIGINT | 管理员 ID，唯一 |
| `desktop_items` | JSON | 桌面快捷方式、所属应用 `app_id`、路径和网格位置 |
| `taskbar_items` | JSON | 任务栏固定项（结构同桌面项但无坐标，最多 12 个），与桌面快捷方式相互独立 |
| `preferences` | JSON | 壁纸、图标尺寸、多选项卡、任务栏、时钟、动效、窗口默认宽高、覆盖传统后台和常用入口统计 |
| `status` | TINYINT | 0 禁用，1 启用 |
| `create_time` | TIMESTAMP | 创建时间 |
| `update_time` | TIMESTAMP | 更新时间 |

## 五、安全边界

- 页面与 API 均使用 `web`、`auth:admin` 中间件。
- 工作区记录强制绑定当前管理员 ID，客户端不能提交其他管理员 ID。
- 桌面路径仅允许站内绝对路径，禁止协议地址和 `//` 协议相对地址。
- 桌面入口最多 48 个；标题、图标类名、坐标和偏好均做白名单与边界处理。
- 自定义壁纸只允许 `jpg/jpeg/png/gif/webp` 且不超过 5MB；文件名重写为 `admin_{adminId}_{YmdHis}_{随机6位}.{ext}`，数据库与偏好只保存相对路径 `apps/cmspro.webos/wallpapers/...`。
- 壁纸列表与删除均以管理员 ID 隔离：删除请求需依次通过“相对路径前缀校验 → 文件名白名单正则 → `admin_{id}_` 归属前缀”三重判定，任一不通过返回业务码 `40302`，无法删除他人壁纸或目录外文件。
- 所有动态文本写入 DOM 前进行 HTML 转义。

## 六、目录结构

```text
CmsproWebos/
├── Assets/                 # CSS、JS、壁纸
├── Controllers/Admin/      # 页面与工作区 API
├── Database/               # 人工升级与回滚 SQL
├── Migrations/             # Laravel 可逆迁移
├── Models/                 # 工作区模型
├── Routes/                 # 后台路由
├── Services/               # 工作区规范化与持久化、壁纸存取
├── Tests/                  # 单元与功能测试
├── Views/Admin/desktop/    # WebOS 完整页面
├── doc/                    # 应用文档
├── Install.php
├── ServiceProvider.php
└── manifest.json
```

## 七、版本历史

| 版本 | 日期 | 说明 |
|---|---|---|
| 2.0.2 | 2026-10-06 | 应用市场新增「首页」子 Tab（用户需求，配合 Appstore v2.1.0 推荐位功能）：① 应用中心市场区顶部渲染「首页 / 分类」子 Tab（`MARKET_SUB_TABS` + `switchMarketSubTab`，state.marketSubTab 默认 home，`data-market-sub-tab` 全局点击委托）；② 首页调 `GET /api/admin/market/home`（框架新代理，远程市场 `GET /api/market/home`）渲染四大区块——「不可错过的应用」随机 2 行网格（每行 6 个，图标+名称）、「官方精选」精选优先按下载量补全 2 行、「装机必备/官方精选/运营推荐」推荐位列表区（每列 5 个，图标+名称+两行描述）、底部「辅助推荐」网格（推荐位 aux，2 行）；③ 卡片复用 `data-market-detail` 全局委托打开详情 + `cacheMarketApps` 写入 state.marketApps 缓存；④ 分类子 Tab 保留原分类浏览主体（抽取 `renderMarketCategoryView`），市场搜索时自动切到分类 Tab；⑤ CSS 新增 `.market-sub-tabs`/`.market-home` 系列样式（网格 repeat(6,1fr)、列区 repeat(3,1fr)）。 |
| 2.0.1 | 2026-10-05 | 账号菜单新增「清除缓存」（用户需求：修改密码下方增加，点击触发 /api/admin/cache/clear）：index.blade.php 账号菜单「修改密码」下新增按钮（fa-eraser 图标）；点击分发新增 clear-cache 分支——复用系统后台缓存清理接口（GET /api/admin/cache/clear，ConfigController::clearCache），成功 toast「缓存已清除」，失败展示错误信息。115 测试 1118 断言通过。 |
| 2.0.0 | 2026-10-05 | ① 账号菜单新增「清除缓存」（修改密码下方，点击触发 /api/admin/cache/clear）。② 系统菜单新增拖拽应用换顶级分类（超管专属）：开始菜单应用卡片可拖拽（draggable），移动语义为**整个应用（含应用文件夹节点）整体移动**——flattenMenus 为叶子输出所属应用文件夹节点 id（app_node_id），卡片收集优先收节点 id（服务端只改父节点、子树随行），直接挂在分类下的散叶子补自身 id；拖到左侧分类按钮高亮松手后调 PUT /api/admin/menus/move（MenuService::batchMove，服务端已防环形引用），成功后 refreshCatalog 重绘菜单（loadCatalog GET 附时间戳破坏 HTTP 缓存，确保拿到实时分组数据）；「常用」虚拟分组不可作为目标，搜索结果不启用拖拽。③ 修复线上 all-todos 接口 500（应用内子类 + 旧版框架降级兜底）。④ 标题栏刷新按钮支持特殊窗口（用户反馈：非应用窗口点击刷新无响应）：无选项卡的应用中心/OS 设置/通知中心/官网动态窗口点刷新——清页面令牌强制重建整页并重新执行各自数据渲染（页面令牌相同会被 renderWindowPage 跳过，是此前静默无响应的根因）。115 测试 1133 断言通过。 |
| 1.9.105 | 2026-10-05 | 修复线上 all-todos 接口 500（production.ERROR: Call to undefined method App\Services\NotificationService::allTodos()）：根因——接口依赖全局框架服务上新增的 allTodos() 包装方法，线上框架文件未部署该方法。修复：按「一切扩展通过应用实现」规范，新增应用内子类 WebosNotificationService（extends NotificationService，公开 protected aggregateTodoEntries），控制器改用子类并移除全局 allTodos 依赖——部署应用目录即可生效，不依赖框架文件同步。115 测试 1113 断言通过。 |
| 1.9.104 | 2026-10-05 | 修复刷新按钮位置（用户反馈：无选项卡条窗口的刷新按钮跑到「收起左侧菜单」旁，未在品牌区右侧）：根因——按钮挂在 .window-controls 内，无选项卡时控制区 margin-left:auto 右推，按钮随之贴右；1.9.103 的 order 方案只对有选项卡条时生效。修复：按钮移出控制区，作为标题栏直属子元素插到 .window-brand 之后（新类 .window-refresh，margin-left:4px）——无论有无选项卡条恒居品牌区右侧，选项卡条从按钮右侧展开；手势排除逻辑（closest('button')）天然防误触拖动/双击最大化。115 测试 1109 断言通过。 |
| 1.9.103 | 2026-10-05 | 修复标题栏刷新按钮位置（用户反馈：按钮在多选项卡右侧，应在左侧）：根因——syncWindowTabsBar 动态将选项卡条/溢出导航 insertAdjacentElement('afterbegin') 插到 .window-controls 最前，把 DOM 首位的刷新按钮挤到选项卡右侧。修复：CSS order 方案——`.window-controls [data-window-action="refresh"] { order: -2 }` 提到控制区最前（在所有 tabs/nav 之前），刷新按钮恒居选项卡条左侧。115 测试 1109 断言通过。 |
| 1.9.102 | 2026-10-05 | 修复右键菜单点击 iframe 内容不关闭（用户反馈：选项卡右键菜单打开后点击 iframe 内任何位置菜单残留）：根因——iframe 是独立文档，其内部点击**不会冒泡到父文档**，父文档 document click 委托末尾的关闭调用收不到事件。修复：bindEvents 补 `window.addEventListener('blur', ...)` ——点击 iframe 内容会使父窗口失焦，blur 时统一关闭桌面/任务栏/选项卡右键菜单及应用行菜单（同源语义顺带覆盖）。115 测试 1108 断言通过。 |
| 1.9.101 | 2026-10-05 | 应用窗口标题栏新增刷新按钮（用户需求：window-brand 右侧增加刷新按钮，点击刷新当前显示内容，同选项卡右键「刷新页面」）：window-controls 最前新增 `data-window-action="refresh"` 按钮（fa-refresh，仅选项卡模式渲染——windowTabsEnabled() 条件输出，非选项卡单页模式不显示）；点击分发新增 refresh 分支——按窗口 key 取 windowState，定位当前激活选项卡（activeTabId 反查，兜底首个）复用 reloadWindowTab（清 pageToken 强制重建 iframe/组件）。115 测试 1107 断言通过。 |
| 1.9.100 | 2026-10-05 | 开始菜单应用卡图钉与名称优化（用户需求：添加到桌面/从桌面移除默认不显示、悬停显示；应用名显示更长）：① `.pin-button` 取消 `.is-pinned` 常显——「添加到桌面/从桌面移除」按钮统一默认隐藏，仅 hover/focus-within 浮动显示，is-pinned 改为主题色状态配色（图钉色与加号区分）；② `.start-app-item` 右 padding 34px→10px（图钉为绝对定位浮动不再预留），应用名宽度增加 24px，超出仍 ellipsis 隐藏。CSS 改动（webos.css 同步 public）。115 测试 1104 断言通过。 |
| 1.9.99 | 2026-10-05 | OS 设置→系统设置新增「点击菜单进入」选项（用户需求：默认当前系统菜单，可选进入全部功能）：新布尔偏好 `menu_open_launcher`（默认 false）三层同步（DEFAULT_PREFERENCES / sanitize / 验证规则）；系统设置页新增开关卡「直接进入全部功能」；开启后点击任务栏「菜单」按钮关闭已开面板并直接打开「全部功能」启动台，默认（关闭）保持打开系统菜单面板。新增 HTTP 持久化测试。115 测试 1104 断言通过。 |
| 1.9.98 | 2026-10-05 | 开始菜单底部栏调整（用户需求：退出登录移到锁定右边；OS 设置只保留图标）：footer 顺序改为 锁定 → 退出登录 → 版权（flex:1 撑开居中）→ OS 设置；OS 设置按钮去文字仅保留 fa-cog 图标，补 aria-label="OS 设置" + title 提供无障碍与悬停提示。Blade 视图改动（服务端渲染无需同步 public）。114 测试 1093 断言通过。 |
| 1.9.97 | 2026-10-05 | 启动台空白区域点击关闭（用户需求「点击非功能、非搜索区域自动关闭」）：此前仅精确点击浮层根节点（event.target === layer）才关闭，全屏 panel 下点击面板空白/网格空隙/面包屑缝隙等均不命中。改为点击委托末尾统一判定——目标不在 .launcher-item（功能卡片）且不在 .launcher-search（搜索框）内即 closeLauncherDialog；卡片/面包屑/关闭按钮在上方分支已 return 不受影响，Esc 关闭保留。114 测试 1091 断言通过。 |
| 1.9.96 | 2026-10-05 | 启动台分组卡片点击直接打开应用窗口（用户需求「就到应用，点击不要进入子菜单，直接打开应用窗口」）：带 app_id 的分组卡片（儿康管理、财务管理等）输出应用代表叶子（flatMenus 中该应用第一个可打开菜单）的 data-launcher-leaf，点击复用叶子打开链路（findEntry → openMenuByType/openEntry 后关闭浮层）直接进应用，与开始菜单应用卡交互一致；纯目录分组（无 app_id，如「管理」「系统」顶级分类）保持进入子目录。114 测试 1090 断言通过。 |
| 1.9.95 | 2026-10-05 | 修复启动台分组卡片图标与系统菜单不一致（用户再次截图对比，实锤根因）：启动台「管理」目录下 24 张卡片中 22 张是**分组节点**（前台用户、儿康管理、财务管理等，children>0），此前走 launcherFolderMarkup 纯字体渲染从不查应用图标；仅「验证码设置」「论坛设置」两张叶子卡片显示图片。开始菜单则把带 app_id 的分组聚合为应用卡片（entryIconMarkup → 应用图片）。修复：launcherFolderMarkup 与叶子同标准——带 app_id 走 applicationIconMarkup 应用图片；无应用关联（如「前台用户」「内容管理」）显示 is-folder-icon 底色风格（folder 黄底 #f0a11a + 白色字体，与开始菜单目录卡片一致）。114 测试 1088 断言通过。 |
| 1.9.94 | 2026-10-05 | 启动台浮层右上角新增关闭按钮（用户需求「出现全部功能时，右上角增加关闭按钮」）：圆形半透明 × 按钮（fa-times，38px，悬浮右上角 22/26px），hover 加亮 + focus-visible 焦点环；点击经浮层点击委托 data-launcher-close 调 closeLauncherDialog，与 Esc/点击空白并列的显式关闭入口。114 测试 1086 断言通过。 |
| 1.9.93 | 2026-10-05 | 启动台叶子图标链路修复：launcherLeafMarkup 改用开始菜单同款 entryIconMarkup 渲染 + findFlatLeaf 按菜单 id 取 flattenMenus 平铺叶子（app_id 含祖先继承），搜索的应用名匹配同步修复；附带修复 manifest.json 被上轮 PowerShell Set-Content 写入 UTF-8 BOM 导致页面版本号输出为空。（1.9.95 复查：叶子链路有效——「验证码设置」等叶子已显示应用图片；当日用户截图的真正差异源是**分组节点**，见 1.9.95）114 测试 1084 断言通过。 |
| 1.9.92 | 2026-10-05 | 启动台叶子图标回归开始菜单标准（用户反馈「显示的图标和系统菜单右侧不一致，要以系统菜单为标准」）：launcherLeafMarkup 与开始菜单同一 catalog 应用查找 + applicationIconMarkup 同一渲染（icon_url 图片 → icon 字体），无应用关联回退菜单自身图标；推翻 1.9.91 服务端接口链（`/api/app/{id}/icon` 链路仅用于安装记录日志图标）——1.9.93 修正实现：app_id 需取 flattenMenus 祖先继承值，原生树节点深层叶子无 app_id。114 测试 1083 断言通过。 |
| 1.9.91 | 2026-10-05 | 启动台叶子卡片应用图标改走服务端图标接口链（icon.svg → icon.png → manifest.json icon → 菜单图标兜底）——1.9.92 推翻（与系统菜单显示不一致），最终回归 catalog 链。 |
| 1.9.90 | 2026-10-05 | 启动台重构为菜单树层级导航：与系统菜单同源，按顶级分类逐层进入（目录=文件夹卡片、叶子=应用图标卡片），面包屑任意层级返回；搜索跨层级平铺匹配；移除毛玻璃特效改高不透明深色遮罩（低配设备全屏模糊易卡顿）。 |
| 1.9.89 | 2026-10-05 | 修复选项卡右键「刷新页面」点击无反应：桌面右键动作分发白名单漏 tab-reload，补入并加防回归断言。 |
| 1.9.88 | 2026-10-05 | 双击窗口标题栏（品牌区/空白处）切换最大化/还原（Windows 桌面惯例）；控制区按钮双击不触发。 |
| 1.9.87 | 2026-10-05 | 选项卡右键菜单新增「刷新页面」（第一项）：重新加载当前激活选项卡页面（iframe 重设 src / 组件重新拉取），行为对齐框架后台刷新按钮。 |
| 1.9.86 | 2026-10-05 | 启动台全屏化：浮层铺满桌面、搜索框顶部居中；根治图片图标堆叠——`is-app-icon` 类移入 applicationIconMarkup 图片分支源头声明，所有渲染位共享等比缩放约束。 |
| 1.9.85 | 2026-10-05 | 修复启动台图片图标无尺寸约束堆叠爆版：补 img 等比缩放（object-fit: contain）与原生拖拽防护。 |
| 1.9.84 | 2026-10-05 | 启动台改为应用级网格：与开始菜单「全部应用」同源（已安装应用），应用图标+名称、点击直接打开应用窗口。 |
| 1.9.83 | 2026-10-05 | iconPicker 短代码别名映射（6 个 → layui 类名，与框架 ConfigController 一致）；开始菜单「管理入口」按钮改「全部功能」打开启动台（应用行菜单保持「管理入口」）。 |
| 1.9.82 | 2026-10-05 | 系统菜单目录图标改用目录/分组菜单自身设置图标（fa-folder 兜底）+「全部功能」启动台初版（顶部搜索 + 全量网格浮层）。 |
| 1.9.81 | 2026-10-01 | 修复选项卡全部关闭后空状态引导残留：renderWindowPage 开头清除 page-host 内非页面容器节点。 |
| 1.9.80 | 2026-10-01 | 前台菜单链接域名绑定兼容：应用侧接口 `home-menu-urls` 复用框架 `menu_path()` 换算访问地址（域名绑定→绑定域名根、普通应用→主站地址、外链原样），转换失败回退原始路径。 |
| 1.9.79 | 2026-10-01 | 官网动态读取条数 3 → 6（用户需求「官网动态 读取3条改为6条」）：fetch URL 加 `?limit=6`——官网公开接口 `api/home/moments/latest` 原生支持 limit 参数（1-20，默认 3，见 Cmsprohome MomentApiController@latest），无需服务端改动。110 测试 1032 断言通过。 |
| 1.9.78 | 2026-10-01 | 官网动态图片弹层预览（用户需求「点击图片最大化弹出层预览，多张图片时可预览切换」）：renderOfficialNews 的图片点击由 window.open 新标签改为 **layer.photos 相册层**（复用 `layuiLayer()`，与框架后台 dashboard.blade.php 的预览方式一致）——事件委托挂 `.official-news-images` 组容器（group.querySelectorAll 收集 {src, thumb}，start=Array.prototype.indexOf 定位点击图），layer.photos 内置左右切换/关闭/自适应；`layuiLayer()` 为空（layui 未加载）时回退 window.open 新标签打开原图。测试：补 `layer.photos({` 与回退 window.open 2 条断言；110 测试 1032 断言通过。 |
| 1.9.77 | 2026-10-01 | 官网动态窗口（用户需求「桌面最右侧增加一个窗口显示 dashboard 的官网动态，600×500，可缩小放大关闭，仅 super_admin」）：① 入口工厂 `officialNewsEntry()`（id=webos-official-news、special=official-news、path=/admin/cmspro/webos?app=official-news），`windowKey` 加特判返回固定 key；② windowMarkup 特殊窗口分支——`isOfficialNews` 无左侧菜单/无收起按钮，content 为 `<div class="official-news-shell" data-official-news>`（无 page-host 不启用多选项卡）；③ 尺寸与停靠——`BUILTIN_WINDOW_KEYS` 加入（不读窗口尺寸偏好），`defaultWindowSize` 固定 600×500（小屏收窄），openEntry 中 left=桌面宽-600-24、top 垂直居中；④ 内容渲染 `renderOfficialNews()`——fetch `https://www.cmspro.cn/api/home/moments/latest`（与 dashboard.blade.php 同源公开接口），escapeHtml + `linkifyOfficialNews`（链接化剥末尾标点）+ 图片网格（点击 window.open 新标签看原图）+ create_time，空态/失败「暂无动态」，异步返回时 `isConnected` 检查防写已关闭窗口；⑤ 权限——initialize 桌面渲染完成后 `isSuperAdmin` 才 openEntry（复用 1.9.63 的全局 isSuperAdmin）；⑥ 附带排除：activateWindowEntry 特殊窗口列表、recordEntryUsage 使用统计（自动打开不计使用次数）。CSS：.official-news-shell/head/list/item/images/time/empty。测试：补 6 条断言（含修正 $css→$stylesheet 变量名、更新 BUILTIN_WINDOW_KEYS 断言）；110 测试 1030 断言通过。 |
| 1.9.76 | 2026-10-01 | 选项卡右键菜单（用户需求「在选项卡点击右键，出现菜单，可进行操作 关闭当前、关闭其它、关闭全部」）：① `openWindowTabContextMenu(tabId, x, y)`——**复用桌面右键菜单容器** `#desktop-context-menu`（menuMarkupInto 渲染 + positionDesktopMenu 定位），data-desktop-id 透传选项卡 id；菜单项：关闭当前（fa-times/tab-close）、关闭其它（fa-columns/tab-close-others）、关闭全部（fa-trash/tab-close-all 红色 danger）。② `runWindowTabContextAction`——遍历 state.windows 按选项卡 id 反查所在窗口；tab-close 直接 closeWindowTab；tab-close-others 逐个关闭其余（非激活页静默删除、激活页走相邻切换），收尾 `activeTabId !== tabId` 时 switchWindowTab 保住保留项激活；tab-close-all 逐个关闭，最后一个（激活页）走既有空状态收尾。③ 右键监听（root 委托 `closest('[data-window-tab]')`）——preventDefault + 反查 `[data-window-key]` 聚焦所在窗口（focusWindow）再弹菜单；点击分发复用 7959 行 `[data-desktop-action]` 委托（runDesktopContextAction 开头加 tab 三分支），点击后统一 closeDesktopContextMenu。测试：补函数/动作/委托/focusWindow 6 条断言；110 测试 1024 断言通过。 |
| 1.9.75 | 2026-10-01 | 修复前台按钮与收起菜单按钮被隔开（用户反馈「当有前台时，前台在最右侧位置了，应该挨着收起左侧菜单」）：根因——`loadWindowHomeMenu` 注入用 `controls.insertAdjacentHTML('afterbegin')` 固定插在控制区最前，1.9.69/1.9.72 引入的选项卡条（afterbegin）与溢出导航 prev（afterbegin）/next（bar 后）注入后，前台按钮与 `.sidebar-toggle` 之间被 prev+bar+next 隔开，且位置随「loadWindowHomeMenu 与 syncWindowTabsBar 的调用时序」漂移（openEntry 与 activateWindowEntry 顺序相反）。修复：inject 改为锚点插入——`anchor = controls.querySelector('.sidebar-toggle')`，`anchor.insertAdjacentHTML('beforebegin', ...)`（sidebar-toggle 缺失时回退 afterbegin），前台按钮始终紧挨「收起左侧菜单」左侧、时序无关。测试：补 `controls.querySelector('.sidebar-toggle')` 与 `anchor.insertAdjacentHTML('beforebegin', windowHomeMenuMarkup(leaves));` 断言；110 测试 1018 断言通过。 |
| 1.9.74 | 2026-10-01 | 修复溢出导航箭头偏上（用户反馈截图：< > 按钮高于选项卡条）：根因——`.window-controls` 为 stretch 布局，`.window-tabs` 无固定高被拉伸居中内容，而 `.window-tabs-nav` 固定 28px 高不参与拉伸、默认停在交叉轴起点（顶部）。修复：`.window-tabs-nav` 补 `align-self: center` 垂直居中对齐选项卡条；110 测试 1016 断言通过。 |
| 1.9.73 | 2026-10-01 | 品牌区副标题跟随当前菜单（用户需求「切换菜单，window-brand 中的 small 控制面板要随着改变」）：1.9.71 固定了品牌区整个 strong（应用名+副标题都不再变化），本次拆分职责——主标题（应用名）保持固定，新增 `syncWindowTabSubtitle(windowState, title)` 仅更新 strong 内 small 文本（textContent 安全转义，不重写主标题）。调用四处：switchWindowTab（tab.title）、closeWindowTab 相邻切换（next.title）、closeWindowTab 全部关闭（清空 ''，品牌区仅保留应用名）、activateWindowEntry tabs 分支（existing.title，新开/复用均跟随）。单页模式（未开多选项卡）行为不变（2400 行既有整块重写，strong=identity.title 应用名固定 + small=当前菜单名本就跟随）。测试：补 `function syncWindowTabSubtitle` + 5 次 `syncWindowTabSubtitle(windowState,`（4 调用+1 定义）断言；110 测试 1016 断言通过。 |
| 1.9.72 | 2026-10-01 | 选项卡条溢出导航（用户需求「超出显示宽度则出现向左向右切换，例如：< 选项卡 选项卡 … 选项卡 >」）：① CSS——`.window-tab` 由 `flex: 0 1 auto` 改 **`flex: 0 0 auto`**（此前选项卡会相互收缩变窄永不溢出，导航无从触发），保留 max-width 168px 与 span ellipsis；新增 `.window-tabs-nav` 左右箭头按钮（22×28 白底圆角、fa-angle-left/right、hover 主题色）+ **`.window-tabs-nav[hidden]` 显式声明**（自定义 display 元素 hidden 失效，复用 1.9.59 教训）。② webos.js——`updateWindowTabsNav(controls)`（scrollLeft/maxScroll 按 1px 容差显隐 prev/next）、`revealWindowTab(controls, tabId)`（激活选项卡 `scrollIntoView({block:'nearest', inline:'nearest'})` 滚入可视区）；`syncWindowTabsBar` 渲染后创建 `< 按钮（afterbegin 在 bar 前）与 > 按钮（bar afterend），绑定 bar scroll 事件实时更新，末尾 reveal 激活选项卡（覆盖切换/关闭/新开/窗口创建全部路径）；全部关闭时连同导航按钮一并移除。③ 事件委托 `data-window-tabs-scroll`（prev/next → `scrollBy ±clientWidth*0.6` smooth）。④ initialize 挂全局 resize 监听（动画帧节流）重算所有窗口导航显隐。测试：补 updateWindowTabsNav/revealWindowTab/data-window-tabs-scroll/`.window-tabs-nav[hidden]`/`.window-tab flex:0 0 auto` 断言；110 测试 1014 断言通过。 |
| 1.9.71 | 2026-10-01 | 修复品牌区应用名随菜单变化（用户反馈「window-brand 中的应用名称不要随菜单点击改变，要始终显示当前应用的名称」）：根因——1.9.68 实现的 `syncWindowTabBrand()` 在切换/关闭/新开选项卡时把 tab.title（菜单名）写入 `.window-brand strong`，且 `activateWindowEntry` tabs 分支更新 `windowState.entry = entry`（菜单入口）后副标题随之变化。修复：删除 `syncWindowTabBrand()` 函数及全部三处调用（switchWindowTab/closeWindowTab/activateWindowEntry tabs 分支），品牌区仅窗口创建时按应用身份渲染一次（strong=应用名 windowIdentity().title，small=入口名副标题），当前菜单名由选项卡条 is-active 高亮展示；单页模式（未开多选项卡）行为不变。测试：补 `assertStringNotContainsString('syncWindowTabBrand')` 防回归；110 测试 1009 断言通过。 |
| 1.9.70 | 2026-10-01 | 修复选项卡模式下窗口按钮不贴右 + 关闭按钮报错（用户反馈）：① 按钮不贴右——1.9.69 的 `.app-window.has-window-tabs .window-controls { margin-left: 0 }` 后控制区宽度仍为内容宽（flex 子项 auto），内部 tabs 的 flex:1 无剩余空间可分；补 `flex: 1` 让控制区占满品牌区之后空间，选项卡条从左侧展开、最小化/最大化/关闭按钮始终贴最右。② 关闭按钮报错 `Uncaught TypeError: closeWindow is not a function`——选项卡事件委托中局部变量 `var closeWindow = tabButton.closest(...)` 因 **var 声明提升**在同一 document click 函数作用域内遮蔽了同名全局函数 `closeWindow`，点击关闭时 7572 行调用的是 undefined 变量；修复：局部变量重命名 `tabHostWindow`（两个分支统一），并加注释「局部变量不得命名为 closeWindow/switchWindowTab 等」。测试：补 `assertStringNotContainsString('var closeWindow =')` 防回归断言；110 测试 1008 断言通过。 |
| 1.9.69 | 2026-10-01 | 修复多选项卡条贴右侧（用户反馈「选项卡在左侧位置开始，现在是在右侧」）：根因——`.window-controls { margin-left: auto }` 把整个控制区推到标题栏右端，选项卡条虽在 controls 首位仍贴右侧。修复：`syncWindowTabsBar` 有选项卡时窗口根元素加 `has-window-tabs` 类（无选项卡移除），CSS `.app-window.has-window-tabs .window-controls { margin-left: 0 }` 取消右推，`.window-tabs` flex:1 从标题栏左侧撑满、控制按钮自然靠右；全部关闭选项卡后恢复按钮贴右原状。测试：test_window_tabs_preference_and_titlebar_tab_bar 补 classList.add/remove 与 CSS 规则断言；110 测试 1006 断言通过。 |
| 1.9.68 | 2026-10-01 | 应用窗口多选项卡（用户需求：类似传统后台 admin.blade.php 的多选项卡）：① 偏好新增 `window_tabs`（默认 false）——三层同步：WorkspaceService `DEFAULT_PREFERENCES` + `sanitizePreferences`（(bool) 校验）+ WebosController::updateWorkspace 验证规则 `'preferences.window_tabs' => ['sometimes', 'boolean']`，前端 normalizePreferences 同步默认值；② OS 设置系统页新增开关卡（settings-card，data-toggle-webos-setting="window_tabs"，说明「仅对开启后新打开的应用窗口生效」）；③ webos.js 核心实现——`makeWindowTab()`（tab 记录：id/title/path/openType，窗口内存态不持久化）、`windowTabsEnabled()`（读偏好）、`syncWindowTabsBar()`（选项卡条注入 `.window-controls` 首位，is-active 高亮 + data-window-tab-close 关闭叉）、`switchWindowTab()`/`closeWindowTab()`（关闭激活页按相邻优先切换，全部关闭显示 emptyState 引导）、`syncWindowTabBrand()`（标题栏品牌区显示当前页面标题）；④ `renderWindowPage` 双模式改造——tabs 分支每个选项卡独立 `.window-page` 容器（pageToken 记录加载令牌，首次创建后切换仅 hidden 显示不重载，iframe/组件内容状态保留），单页现状逻辑保留；⑤ openEntry 窗口创建接入（`windowTabsEnabled() && windowElement.querySelector('[data-window-page-host]')` 判定，排除应用中心/OS 设置/通知中心等特殊窗口）+ activateWindowEntry tabs 分支（同路径复用既有选项卡否则新开 + rerenderWindowNav 高亮）+ 事件委托（先判 tabClose 后判 tabButton，嵌套处理）；⑥ CSS——`.window-tabs` 横向选项卡条（overflow-x auto 滚动条隐藏）、`.window-tab`（max-width 168px 省略号，is-active 主题色底白字）、`.window-page` + `.window-page[hidden]`（同 1.9.59 hidden 教训：显式声明防 display 覆盖）、`.window-controls` 加 min-width:0 防溢出。测试：新增 test_window_tabs_preference_and_titlebar_tab_bar（源码断言）+ test_workspace_api_persists_window_tabs_preference（HTTP PUT 持久化链路）；110 测试 1003 断言通过。 |
| 1.9.66 | 2026-10-01 | 版权信息与动态版本标题：① WebosController 新增 `manifestVersion()`（读应用 manifest.json version），index 视图数据加 `cmsproVersion => system_version()`（框架动态版本函数）与 `webosVersion`；② 桌面标题改为 `CMSPRO v{系统版本} · WebOS v{应用版本}`；③ 开始菜单系统操作区锁定与退出登录之间插入 `.start-copyright` 版权行（CMSPRO/www.cmspro.cn 链接 + 系统版本 + 2015-当年动态年份 + Holley/www.renhuali.cn 链接，与 layouts/admin.blade.php#L203 页脚一致），CSS 弹性居中省略号；④ 测试：test_admin_can_open_webos_desktop 增加标题双版本正则与版权年份断言（真实渲染验证）。 |
| 1.9.65 | 2026-10-01 | 桌面右键「查看」子菜单（大/中/小图标）：① JS 新增 `DESKTOP_ICON_SIZES` 三档格子常量（large 128x130 / medium 102x104 / small 86x88，中档与历史布局一致保证默认不变）与 `desktopIconSize()` 档位取值；renderDesktop 布局与拖拽落点换算按档位计算，`root.dataset.iconSize` 驱动 CSS 视觉覆盖（badge/按钮盒/字号三档）；② `menuMarkupInto` 支持 item[4] 子菜单（`.desktop-context-group` 包裹 + `has-children` 尾箭头 + hover 展开 `.desktop-context-submenu`，当前档位 `is-checked` 勾选）；③ 空白右键菜单新增「查看 > 大/中/小图标」，`runDesktopContextAction` 的 icon-large/medium/small 分支更新 `preferences.icon_size` 并持久化重排；④ 后端 `DEFAULT_PREFERENCES` 加 icon_size=medium，`sanitizePreferences` 枚举校验（非法值回退中档）。 |
| 1.9.64 | 2026-10-01 | 停用应用图标不占位（用户反馈：停用图标隐藏了但仍占格，其它图标无法移入）：① 新增 `assignedDesktopItems()` 统一出口——渲染/拖拽占用/找空行全部基于已分配可见集合，隐藏项的格子可移入、新增入口可落在其空行；② 新增 `normalizeDesktopLayout()` 布局归一化——可见图标坐标冲突时（停用期间格子被占后重新启用）按 (y,x) 顺序下移到第一个空白格并静默保存，未冲突保持原位；③ 在 `refreshCatalog`（目录刷新后）与 `initialize`（首次渲染前）两个时机执行归一化。隐藏项原始坐标保留不动。 |
| 1.9.63 | 2026-10-01 | 应用中心与升级提醒仅超级管理员可用：① WebosController webosRuntime.admin 注入 is_super_admin；② 前端 isSuperAdmin 变量——bootstrapDesktopItems 非超管不预置应用中心（桌面/任务栏空）、entryAssigned 对 webos-app-center 返回 isSuperAdmin（历史工作区数据的图标同样过滤）、checkWebosSelfUpdate 非超管直接跳过；③ WorkspaceService::resetForAdmin 非超管重置后桌面/任务栏为空（与前端初始化一致）。 |
| 1.9.62 | 2026-10-01 | 权限对齐框架（用户反馈：sunzy 框架后台有「儿康管理」菜单而 WebOS 无）：① 系统框架 `MenuService::userMenus()` 补全父级菜单链——非超管授权子菜单未授权父级时（孤儿节点），自动补全所有祖先（与 `ConfigController::menus` 的 menus.json 补全逻辑一致），修复 WebOS 目录/开始菜单/窗口侧栏整体丢失应用的问题；② WebOS 桌面/任务栏图标按「已分配」过滤——新增 `visibleAppIds()`（从当前用户菜单目录递归收集 app_id）与 `entryAssigned(id)`（特殊入口保留/菜单入口须在 flatMenus/应用快捷方式须目录中仍有该应用菜单），`renderDesktop`、`renderPinnedApps` 渲染前过滤，`refreshCatalog` 刷新目录后同步重绘，角色收回授权后幽灵图标不再显示。 |
| 1.9.61 | 2026-10-01 | 前台菜单触发按钮样式调整：地球图标（fa-globe）改为文字「前台」+ fa-caret-down 下拉箭头；按钮尺寸固定 width: 58px / height: 100%（撑满标题栏，justify-content 居中）。 |
| 1.9.60 | 2026-10-01 | 前台菜单下拉项去掉 FontAwesome 图标仅显示菜单名称：windowHomeMenuMarkup 菜单项移除 `<i class="fa ...">`；collectHomeMenuLeaves 叶子不再收集 icon 字段；CSS 移除 .window-home-menu-item .fa 样式与 gap 间距。 |
| 1.9.59 | 2026-10-01 | 前台菜单下拉框修复与位置调整：① 新增 `.window-home-menu-list[hidden] { display: none; }`——修复 display:flex 覆盖 hidden 属性浏览器默认样式导致列表默认展开且切换无效的问题；② 下拉列表定位由 right:0（右对齐）改为 left:0（左侧靠齐触发图标下方展开）。 |
| 1.9.58 | 2026-10-01 | 应用窗口标题栏前台菜单下拉框：在「收起左侧菜单」左侧注入前台菜单触发器（地球+下拉箭头图标）；数据源为系统菜单树接口 `/api/admin/menus/tree?terminal_type=home`（安装时 manifest home_menus 已写入 admin_menus），前端按窗口应用的 app_id 递归筛选菜单叶子（collectHomeMenuLeaves）并缓存（homeMenusCache，同应用跨窗口复用）；点击菜单项 `window.open(path, '_blank', 'noopener')` 新标签页打开前台地址并收起下拉；应用无前台菜单时不注入任何元素；支持外部点击关闭、多窗口展开互斥、aria-expanded 无障碍状态。 |
| 1.9.57 | 2026-10-01 | 安装应用依赖检测对齐传统后台：安装失败改用 showErrorDialog 长错误弹窗（依赖应用未安装/未启用时附「到应用市场搜索安装并启用」引导），不再 toast 一闪而过；依赖校验本身由系统安装接口统一执行（checkDependencies：dependencies.app_ids 未装 50004 / 已装未启用 50017）。 |
| 1.9.56 | 2026-10-01 | 桌面「应用中心」可更新数量角标样式调整：定位改为 top: 2px / right: 8px（图标右上角内侧）。 |
| 1.9.55 | 2026-10-01 | WebOS 自身升级特殊优化：禁用拦截提示附「升级期间桌面与已开窗口可继续操作」说明；升级并启用成功后引导「立即刷新」加载新版桌面（其它应用保持通用提示不变）。 |
| 1.9.54 | 2026-10-01 | 每次进入 WebOS 桌面自动检查应用市场中 WebOS 自身是否有更高版本：有则弹出确认提示，确认「立即升级」后打开应用中心窗口并自动触发升级流程（启用拦截 → 版本选择弹窗）；无新版本或检查失败时静默。 |
| 1.9.53 | 2026-10-01 | 修改密码弹窗宽度由 430px 调整为 480px。 |
| 1.9.52 | 2026-10-01 | 个人设置窗口宽度固定 680px（小屏收窄避免溢出）；修改密码弹窗宽度固定 430px（openActionDialog 传入 width）。 |
| 1.9.51 | 2026-10-01 | OS 设置窗口宽度固定 1000px（不再随屏幕宽度按百分比拉伸；小屏自动收窄避免溢出，高度仍按默认比例 80%）。 |
| 1.9.50 | 2026-10-01 | 修复路由模式（_component）窗口内容无法选中复制：桌面全局 user-select:none 防框选误选，路由模式注入父文档的 HTML 继承禁选；窗口内容区 .window-page-host 恢复 user-select:text，iframe 页面与标题栏/侧栏行为不变。 |
| 1.9.49 | 2026-10-01 | 修复多窗口堆叠时点击底层窗口 iframe 内容无法切换聚焦的问题：未聚焦窗口的 iframe 覆盖透明遮罩（iframe 内点击不冒泡到父页面），点击遮罩即冒泡聚焦该窗口并移除遮罩，第二次点击直接操作 iframe 内容；聚焦状态变化由 focusWindow/renderWindowPage 统一同步。 |
| 1.9.48 | 2026-10-01 | 应用更新列表的应用图标遵循全局规则：更新记录不含图标数据时合并本地目录应用图标——icon_url 由服务端按 icon.svg → icon.png 顺序解析，catalog.icon（manifest.json 的 icon）兜底，目录缺失时回退默认方块图标。 |
| 1.9.47 | 2026-10-01 | 桌面「应用中心」图标右上角显示可更新应用数量角标（红色圆底白字，与侧栏角标同风格）：检查更新结果变化时由 syncUpdateBadge 同步重渲染桌面，升级成功/刷新检查后数量即时收敛，无更新时不显示。 |
| 1.9.46 | 2026-10-01 | 「应用更新」状态栏刷新图标改为可点击按钮：点击后强制全量检查更新（绕过 lazy 缓存口径）并重渲染更新列表，检查中显示加载特效、完成后提示；状态栏文案统一（发现 N 个可用更新 / 暂无可用更新），悬停有高亮反馈。 |
| 1.9.45 | 2026-10-01 | 应用升级全链路停留在当前 Tab（不再切换到已安装）：新增 reloadAppCenterCurrent 按当前 Tab 刷新；「应用更新」Tab 改为本地数据渲染（updateChecked 标记，规避服务端 24h 缓存旧数据），升级成功 dropUpdatedApp 后更新列表即时移除已升级应用。 |
| 1.9.44 | 2026-10-01 | 应用中心全部 `layer.load(2, {content: '…'})` 统一改为 `layer.load(2)` 纯加载特效（覆盖：升级包下载、一键升级各步、删除应用文件、备份准备下载、备份文件上传、应用文档加载），执行期间不再显示文字提示。 |
| 1.9.43 | 2026-10-01 | 应用包上传执行（手动升级/上传安装共用弹层）时仅显示加载特效，不再显示「正在处理」文字提示。 |
| 1.9.42 | 2026-10-01 | 桌面图标右键菜单新增「重命名」：layui 输入框修改桌面显示名称（保存到工作区 desktop_items[].title），应用名称、系统菜单等应用本身数据不受影响；支持空值/超长校验（≤60 字符），修改后即时重渲染并静默保存工作区。 |
| 1.9.41 | 2026-09-30 | 「修改密码」弹窗改为 layui 风格白底弹窗（plain 模式，与「管理入口」弹窗一致），不再使用毛玻璃效果。 |
| 1.9.40 | 2026-09-30 | 应用升级交互完整复刻传统后台：启用中的应用先禁用（确认后自动继续）→ 获取可用版本列表 → layui 弹窗选择升级版本（单步/跳跃/直接最新/一键升级到最新版 + 备份提醒 +「先去备份」直达备份管理）→ 下载升级包安装（loading 进度提示）→ 成功后清除待升级记录、刷新目录并询问是否启用；一键升级逐级执行、任一步失败即停止。 |
| 1.9.39 | 2026-09-30 | ① 重置工作区改为恢复初始默认布局（仅保留「应用中心」内置入口，桌面+任务栏），不再清空桌面；② 卷页入口方向修正：白纸沿「右上→左下」对角折痕翻起（折起区在右下角），悬停时纸张沿折痕动态拉开（白纸收拢到左上角、纸背面翻走）露出深蓝「进入 WebOS」内容页。 |
| 1.9.38 | 2026-09-30 | 「进入 WebOS」入口改为卷页（page peel）效果：静态为右下角白色卷页折角（对角折痕 + 卷筒阴影渐变 + 圆润页角），悬停时书页翻开、卷角翻走，露出深蓝底「进入 WebOS」内容页；贴屏幕右下角，升级按钮出现时上移避让。 |
| 1.9.37 | 2026-09-30 | 修复「进入 WebOS」入口仍显示白色：entry.js 改为全部关键样式内联（内联优先级最高，彻底杜绝后台主题覆盖），悬停翻页改由 mouseenter/mouseleave 驱动，不依赖外部 CSS 加载。 |
| 1.9.36 | 2026-09-30 | 回退 1.9.35 对系统布局的改动，改用合规方式实现右下角入口：ServiceProvider 注册 `View::composer('layouts.admin')`，向系统布局预留的 `@stack('page_styles')`/`@stack('page_scripts')` 注入入口 CSS 与构建脚本；「覆盖传统后台」跳转同样改由 composer 注入（head 内 location.replace）。 |
| 1.9.35 | 2026-09-30 | 传统后台右下角新增「进入 WebOS」书本入口；OS 设置新增「系统设置」Tab（覆盖传统后台 + 重置工作区）；覆盖开启后访问后台首页直达 WebOS 桌面（?skip_webos=1 临时绕过）。 |
| 1.9.34 | 2026-09-30 | 「显示桌面」窄条贴屏幕边缘：负外边距抵消任务栏内边距（右缘 10px / 左右布局底缘 8px），边缘空白也可点击。 |
| 1.9.33 | 2026-09-30 | 地球图标点击改为浏览器新标签页打开站点首页（1.9.31 为 WebOS 内 iframe 窗口）。 |
| 1.9.32 | 2026-09-30 | 「显示桌面」热区回调为固定 14px 窄条：不再平分任务栏剩余空间，保留左侧分隔线与 hover 高亮。 |
| 1.9.31 | 2026-09-30 | 任务栏新增前台首页入口：通知按钮左侧地球图标，点击在 WebOS 内以通用窗口打开站点首页（重复点击激活已存在窗口）。 |
| 1.9.30 | 2026-09-30 | 「显示桌面」热区填满时间右侧：固定 7px 窄条改为弹性占满时间按钮右侧剩余空间，hover 高亮。 |
| 1.9.29 | 2026-09-30 | 修复单图标拖动「不跟手、松开后黏住鼠标」：拖动/框选收尾监听改绑 document + pointercancel 兜底，图标内容禁用指针事件根断原生拖拽源。 |
| 1.9.28 | 2026-09-30 | 修复 1.9.27 拖动失效：map 回调传参错误导致拖拽抛异常，单/多选均不能移动。 |
| 1.9.27 | 2026-09-30 | 桌面图标多选：空白处框选、Ctrl+点击追加、多选整体拖动（网格吸附 + 冲突自动寻位）。 |
| 1.9.26 | 2026-09-30 | 移除演示待办初始化与测试数据：WebOS 不再自带任何待办演示数据（hook 接入机制保留），库中测试通知全部清空。 |
| 1.9.25 | 2026-09-30 | 移除通知面板右上角设置齿轮按钮（1.9.24 开窗功能），底部「查看全部」已覆盖窗口入口。 |
| 1.9.24 | 2026-09-30 | 通知面板齿轮改为 WebOS 内开窗：不再跳浏览器新标签页。 |
| 1.9.23 | 2026-09-30 | 修复「系统账号」窗口只显示角色管理：子项全为按钮权限（path 为空）的菜单（如用户管理）整树被过滤消失，现父菜单自身兜底为可打开叶子。 |
| 1.9.22 | 2026-09-30 | 系统菜单默认隐藏 WebOS 自身项：不再显示「WebOS」分组与应用菜单（桌面/任务栏入口不受影响）。 |
| 1.9.21 | 2026-09-30 | 系统菜单「+」按钮改为悬停浮动显示：默认隐藏，悬停/聚焦菜单项时浮现，已固定的图钉常显。 |
| 1.9.20 | 2026-09-30 | 修复点击通知/待办未在对应窗口打开：测试数据 link 使用了菜单中不存在的路径（/admin/comment 等），已全部改为 admin_menus 真实路径。 |
| 1.9.19 | 2026-09-30 | 测试数据扩充：待办 3→9 条（不同数量级含 99+ 场景），灌入 12 条通知（4 级别 × 已读/未读 × 时间分组 × 有无链接 × 系统/应用来源）。 |
| 1.9.18 | 2026-09-30 | 任务栏通知面板通知 Tab 改为未读口径：过滤已读通知只显示未读（窗口保持全量口径），点击/全部已读后随刷新收敛。 |
| 1.9.17 | 2026-09-30 | 修复通知已读后任务栏徽标不消失：窗口通知行/面板通知行标记已读后同步刷新徽标；面板通知行点击补齐标记已读。 |
| 1.9.16 | 2026-09-30 | 通知中心窗口工具栏右侧新增「全部/未读/已读」筛选与「全部已读」按钮（待办/通知两个 Tab 独立筛选状态）。 |
| 1.9.15 | 2026-09-30 | 通知中心窗口列表显示全部：待办 Tab 改用应用侧全量接口（含已读条目，弱化显示），通知 Tab 本就返回全部通知（含已读标记）。 |
| 1.9.14 | 2026-09-30 | 通知中心待办接入系统 hook 测试数据源：ServiceProvider 注册 `admin.notifications.todos` filter，返回 3 条演示待办（真实菜单路径，点击可在 WebOS 内弹出对应应用窗口）。 |
| 1.9.13 | 2026-09-30 | 通知/待办点击改为在 WebOS 内弹出对应应用窗口（按站内路径匹配菜单入口，无匹配回退新标签页），通知中心窗口与任务栏通知面板行为一致。 |
| 1.9.12 | 2026-09-30 | 修复通知中心窗口「通知」列表恒为空：api() 已返回 data 载荷，去掉多余的二次取值。 |
| 1.9.11 | 2026-09-30 | 修复任务栏固定后出现重复图标：固定/解除固定/卸载清理统一重绘任务栏两个区域，固定按应用聚合键去重并按 id 清洗历史重复数据。 |
| 1.9.10 | 2026-09-30 | 应用中心「已安装」的管理入口弹窗改为 layui 精简风格（纯色遮罩、无毛玻璃）。 |
| 1.9.9 | 2026-09-30 | 任务栏与桌面解耦：新增独立任务栏固定项（taskbar_items），任务栏固定图标支持拖动排序，桌面操作与任务栏互不影响。 |
| 1.9.8 | 2026-09-30 | 安装记录类型筛选移至右上角搜索框右侧，搜索同时支持应用名称与标识。 |
| 1.9.7 | 2026-09-30 | 安装记录搜索移至窗口右上角全局搜索框（专属占位「搜索应用标识」），列表工具栏仅保留类型筛选。 |
| 1.9.6 | 2026-09-30 | 安装记录已卸载应用名称显示修复：名称与图标兜底接入市场缓存（目录 → 市场缓存 → 应用标识），新增 `fillRecordAppInfo` 自动补齐缺失应用信息后局部重渲染。 |
| 1.9.5 | 2026-09-30 | 安装记录图标绝对定位铺满显示，彻底修复溢出裁剪。 |
| 1.9.4 | 2026-09-30 | 安装记录图标按全局优先级显示且完整不裁剪。 |
| 1.9.3 | 2026-09-30 | WebOS 通知中心窗口：左侧待办/通知菜单 + 待办卡片 + 通知分页列表（点击已读/跳转）。 |
| 1.9.2 | 2026-09-30 | 安装记录显示应用名称与完整兜底图标链。 |
| 1.9.1 | 2026-09-30 | 通知中心「查看全部」改为 WebOS 窗口内打开。 |
| 1.9.0 | 2026-09-30 | 通知中心双 Tab（待办/通知）+ 待办徽标跳转已读 + 未读蓝点；安装记录搜索/筛选/日期分组 + 右侧操作详情。 |
| 1.8.16 | 2026-09-30 | 卸载执行期间仅显示转圈加载图标（无文字提示）。 |
| 1.8.15 | 2026-09-30 | 卸载/删除执行期间显示加载层进度提示。 |
| 1.8.14 | 2026-09-30 | 卸载/删除确认按钮输入匹配视觉反馈（灰显→红色脉冲点亮）；桌面快捷方式名称使用应用名。 |
| 1.8.13 | 2026-09-30 | 备份管理与文档预览弹层宽高 90%；文档树文件名超宽省略号截断。 |
| 1.8.12 | 2026-09-30 | 卸载确认、禁用引导与删除确认对话宽度调整为 660px。 |
| 1.8.11 | 2026-09-30 | 已安装列表按安装/更新时间较新者降序（与后台一致）；未安装卡片操作按钮避让右上角删除按钮。 |
| 1.8.10 | 2026-09-30 | 修复“已安装”行菜单被窗口底部遮挡：行位于应用中心底部时菜单自动向上弹出。 |
| 1.8.9 | 2026-09-30 | 桌面「应用中心」图标改为内置入口：右键不显示“删除图标”，所有删除路径对其拦截。 |
| 1.8.8 | 2026-09-30 | 未安装列表操作补齐并修复卸载入口：未安装卡片增加“导出”（与已安装对等）与右上角 × 物理删除（悬停显示，输入应用名确认，调系统删除接口）；修复已安装行菜单/桌面右键/任务栏右键“卸载”入口（is_system 类型兼容）；卸载确认、禁用引导与删除确认弹窗改为 layui 精简风格（无毛玻璃）。 |
| 1.8.7 | 2026-09-30 | 修复窗口拖动/缩放结束后的闪动：手势样式改为暂停入场动画（`animation-play-state: paused`），不再因样式切换重播入场动画。 |
| 1.8.6 | 2026-09-30 | 应用中心交互对齐传统后台：应用市场/未安装安装成功后弹窗询问是否立即启用；已安装、未安装、应用更新列表的应用名称可点击进入本地应用详情（复用市场详情布局，操作按钮按来源提供安装/更新）。 |
| 1.8.5 | 2026-09-30 | 安装弹窗精简：改为 layui 精简风格（纯色遮罩、无毛玻璃特效、小圆角白卡片）；安装默认添加到系统菜单（移除“添加到系统菜单 / 两者都创建”选项），“创建桌面快捷方式”改为可选复选框（默认不勾选、每次打开重置）。 |
| 1.8.4 | 2026-09-30 | 应用中心数据源全面复用系统接口：删除自建 `GET api/catalog` 目录接口（含 `AdminMenuCatalogService` / `ApplicationIconService` 及对应单测），已安装应用列表改用 `GET /api/admin/apps`、后台菜单目录改用 `GET /api/admin/menus/user`（前端 `filterAdminMenus` 按 `terminal_type` 过滤）、安装记录改用 `GET /api/admin/app-logs?per_page=30` 按需加载，与传统后台“应用管理”同一数据源与排序；图标兜底链简化为 icon_url → 应用 icon 字段 → 通用占位图标，`config_groups`（设置入口）与状态文案由前端按系统接口字段计算。 |
| 1.8.3 | 2026-09-30 | 模态弹窗焦点闭环：应用安装与应用操作弹窗统一记录触发位置，打开后自动聚焦，Tab / Shift+Tab 不越过遮罩，Esc 只关闭顶层弹窗，关闭后恢复焦点；已安装应用行的“更多”菜单在移除前恢复到原按钮，避免焦点退回页面主体。 |
| 1.8.2 | 2026-09-30 | 企业级 UI/UX 优化：窗口拖拽与缩放使用 `requestAnimationFrame` 合帧更新并补齐异常结束清理；动效使用 120/180/240ms 统一变量，窗口、面板与弹层使用一致入场节奏，控件补齐键盘焦点环，移除宽泛 `transition: all` 并完整支持 `prefers-reduced-motion`；应用市场使用单调请求序号与 `AbortController` 防止旧搜索/分类响应覆盖新结果，离开市场时同步取消请求，搜索防抖缩短至 220ms；已安装列表将“手动”明确为“手动升级”，导出移入更多菜单，名称与说明补充完整悬停信息，状态切换增加 `aria-busy`、禁用态及失败恢复；应用图标异步解码，远程市场图标懒加载；CSS/JS 地址附带已发布文件修改时间，升级后自动失效旧浏览器缓存。 |
| 1.8.1 | 2026-09-29 | `index.blade.php`：任务栏头像与账号菜单头像改用后台统一写法 `avatar ?: asset('Admin/images/avatar.png')` 始终渲染 `<img>`，未上传头像时显示系统默认头像图而非字体图标。`webos.js`：`bootstrapDesktopItems()` 移除按关键词挑选「文件/内容/系统」进桌面与任务栏的初始数据，首次进入只固定「应用中心」；`buildEntryTree()` 修复应用节点 children 恒空——新增 `appNodeOf()`（惰性创建应用聚合节点）与 `collectAppMenu()`（叶子直接落位、分组递归收集），以 `fresh = !appIndex.has(appId)` 判定首次创建时根菜单子项直接铺开，同一应用的其余顶级菜单保留自身名称为子分组；入口树展开/收起重渲染前记住滚动位置、渲染后恢复，不再跳回顶部；入口管理树的应用节点（`appNodeOf` 存真实应用引用，`entryTreeNodeMarkup` 用 `applicationIconMarkup` 渲染）与桌面入口行（`renderEntryManager` 改用 `entryIconMarkup(findEntry(item.id) || item)`）显示应用图标，纯菜单项保持字体图标；`webos.css` 新增 `.entry-row .entry-row-icon` / `.entry-tree-toggle .start-app-item-icon` / `.entry-tree-toggle .entry-tree-app-icon` 38px 尺寸规则。`webos.js`：备份弹窗工具栏移到顶部并补齐“导入恢复”——`renderBackupDialog` 内容改为 `backupToolbarMarkup(count, appId, appName)`（顶部，左「共 N 条备份记录 + 导入恢复」/ 右「立即备份」）在前、列表 `renderBackupList()` 在下，`bindBackupDialogEvents` 新增 `#dlgBtnImportBackup` 绑定；移植后台 `openImportDialog`（`importDialogMarkup` 上传文件/从服务器选择两个 Tab + `bindImportDialogEvents` 拖拽点选 `.zip`、上传 `/backups/upload-file` 成功后 `doRestoreWithProgress({ local_file_name })`）、`loadServerBackupFiles`（`GET /backups/local-files` 列表，`data-local-file` 委托调用）、`doLocalRestore`（本地文件确认后分步恢复），弹层关闭时 `layero.on('remove')` 清理隐藏 fileInput |
| 1.8.0 | 2026-09-29 | 应用中心「已安装」的导出、手动升级、备份、文档四项操作照抄传统后台“应用管理”页面实现。`webos.js`：新增公共 helper `layuiLayer()` / `layuiJquery()` / `legacyAjax()`（每次注入 `runtime.csrfToken`，替代后台的 `$.ajaxSetup`）/ `formatSize()`（`0` 显示 `0 B`，与既有 `formatFileSize()` 的 `-` 区分）/ `showErrorDialog()` / `resolveBlobFileName()` / `saveBlobAsFile()` / `readBlobError()`；导出改 XHR `responseType: 'blob'`，先嗅探 JSON 错误体再落盘，文件名取 `Content-Disposition`；上传安装与手动升级共用 `openUploadDialog()`（拆为 `uploadZoneMarkup` / `bindUploadPicker` / `bindUploadDropzone` / `showPickedFile` / `clearPickedFile` / `submitPickedFile` 等，`context` 对象承载状态以满足参数 ≤4 约束），`fileInput` 随弹层 `end` 销毁，桌面页不再保留静态 `#app-package-input`；备份新增 `openBackupDialog()` + 三步分卷（`prepare` / `table` 分页游标 `last_id` / `finish`）与三步恢复（`restore-prepare` / `restore-table` 按 `offset` / `restore-finish`，`maxRetries = 3`，业务错误 1s、网络错误 2s 重试，拆为 `handleRestoreFailure` / `handleRestoreError`），多层弹层用 `backupLayerStack` + `pushBackupLayer()` / `closeBackupLayers()` 只关自己开的层级，不用后台的 `layer.closeAll()`（会误关 WebOS 应用窗口）；文档改三栏 `openAppDocs()` + `renderDocViewerToc()`（拆出 `renderDocTocItem` / `bindDocTocScroll` / `keepTocItemVisible`），正文加载用 `data: { path: path }` 传参与后台 `$.get` 一致，代码块交给 `hljs`，`vue` / `html` 归一到 `xml`。清理死代码：`handleAppCenterAction` 删除 7 个失效委托分支（`[data-upload-submit]` / `[data-manual-upgrade]` / `[data-export-submit]` / `[data-backup-create]` / `[data-backup-download]` / `[data-doc-download]` / `[data-doc-path]`，其中备份下载若保留会与弹层内 jQuery 绑定双重触发导致重复下载）、`state.actionApp` 改为 `state.docAppId`、删除 `elements.packageInput` 及其监听、删除已无调用方的 `downloadFile()`。`webos.css`：`.upload-field` 系列替换为 `.app-upload-*` 全系列，删除整段 `.backup-*`（新 UI 用 `.layui-table` / `.layui-btn`），`.doc-*` 段重写为三栏布局 + `.doc-toc-*` + `.doc-markdown-body` 全系列，进度条与按钮直接用 layui 类名不自写。`index.blade.php`：head 引入 `highlight.js/styles/github-dark.min.css`，body 末尾引入 `highlight.min.js` 与 bash/css/javascript/json/php/sql/xml 语言包，删除静态 `#app-package-input` |
| 1.7.3 | 2026-09-29 | 应用中心左侧菜单「应用更新」新增可用更新数量角标。`webos.js`：`state` 新增 `updateCount`（`null` 表示未检查/检查失败）与 `updateApps` 配套维护，新增 `updateBadgeMarkup()`（`count <= 0` 不渲染，`> 99` 折叠为 `99+`）并由 `appCenterSidebarMarkup()` 仅在 `item.id === 'updates'` 时拼接；新增 `loadUpdateCount()` 在 `openWindow` 的应用中心分支静默调用 `POST /api/admin/apps/check-updates?lazy=1`（复用服务端 24h 缓存节流，失败时静默不提示），`syncUpdateBadge()` 复用既有 `rerenderWindowNav()` 只重绘侧栏、不重建窗口；`loadAppCenterTab` 的 `updates` 分支复用已有请求结果同步角标；`upgradeApp` 成功后调 `dropUpdatedApp(appId)` 从 `state.updateApps` 本地过滤后重算数量——服务端 `app_update_last_result` 缓存 TTL 为 86400 秒且升级不清除，重新请求会读到过期数量。`webos.css`：新增 `.window-nav-badge`（绝对定位右上角，`#ef5a62` 红底，参照既有 `.notification-badge`）与 `.window-nav-button[data-special-tab="updates"] { position: relative }`，定位上下文只给该按钮，不影响菜单树按钮（`.window-nav-caret` 是 `margin-left: auto` 的 flex 项而非绝对定位，建立定位上下文不改变既有布局） |
| 1.7.2 | 2026-09-29 | 应用市场详情页的截图预览层大图可用区域改为视口的 90% × 90%：`.shot-viewer` 内边距由 `40px` 改为 `5vh 5vw` 并移除 `gap`，`.shot-viewer-stage` 上限由 `min(1080px, 88vw)` × `100%` 改为 `90vw` × `90vh`（补 `min-width: 0` 允许 flex 项收缩），图片 `max-height` 由 `calc(100vh - 150px)` 改为 `calc(90vh - 32px)` 预留页码高度；`.shot-viewer-nav` 由 `position: relative` + `flex: 0 0 auto` 改为 `position: absolute` + `top: 50%` / `margin-top: -32px`，并以 `[data-shot-viewer-prev]` / `[data-shot-viewer-next]` 属性选择器分别定位到 `left: 12px` / `right: 12px`，脱离文档流后不再挤占舞台宽度（此前 nav 与 gap 共占 108px，须屏宽 ≥ 1880px 才可能达到 90vw）。纯 CSS 改动，`webos.js` 未变更 |
| 1.7.1 | 2026-09-29 | 补齐 `_component`（路由模式）片段的后台资源环境：桌面页新增 `pear.js`，使 `layui.config({ base })` 与 `extend` 映射生效，片段内 `layui.use` 的 pear 扩展模块不再 404；新增预热 `element` / `form` / `jquery` / `layer` / `toast` / `button` / `popup`（框架级模块不预热）。`evalInlineScripts` 重命名为 `runFragmentScripts` 并拆出 `runScriptNode`，改用 Promise 串行链按文档顺序执行片段脚本，外链脚本等 `load` / `error` 后放行，并在全部脚本执行完毕后再调 `initLayuiComponents`，与后台 jQuery `.html()` 语义一致。CSS 侧无需改动（片段 `<link>` / `<style>` 注入即生效，主框架样式与 `layui.css` 已齐） |
| 1.7.0 | 2026-09-29 | 窗口左侧菜单支持后台声明的四种打开方式：新增 `openMenuByType` 统一分流，`_iframe` 由 `renderWindowPage` 渲染进新增的 `[data-window-page-host]` 容器；`_component` 走新增的 `fetchText` 拉取 HTML 片段并注入，`evalInlineScripts`（1.7.1 起更名为 `runFragmentScripts`）重建脚本节点使其执行、`initLayuiComponents` 重渲染 layui 组件；`_blank` 用 `window.open` 打开新标签页，`openablePath` / `externalPath` 额外放行 `http(s)` 外链（`flattenMenus` 与 `windowNavTree` 的叶子过滤同步放宽，否则外链菜单在侧栏不显示）；`_layer` 由 `openLayerWindow` 调 layui 弹层以 80% × 80% 打开，layui 未就绪时降级为新标签页。`_blank` / `_layer` 点击后不切换窗口内容也不改侧栏高亮，`defaultEntryOf` 使开始菜单聚合与应用市场“打开”按钮跳过这两类菜单 |
| 1.6.1 | 2026-09-29 | 应用市场首次加载时列表区不再渲染大字号 `.panel-empty` 占位提示，加载状态统一由底部 `.market-sentinel` 哨兵显示，消除同时出现两条“正在读取应用市场…”的问题（与 1.5.6 切换分类的处理保持一致） |
| 1.6.0 | 2026-09-29 | 窗口左侧菜单不再平铺：新增 `windowNavTree(entry)` 从原始菜单树提取当前窗口子树并保留分组层级，`windowNavNodesMarkup` 递归渲染，分组标题带 `data-window-nav-branch` 可展开收起，同级手风琴互斥（`collapseNavSiblings`），`revealActiveNavBranch` 自动展开当前页所在链路；切换菜单时 `rerenderWindowNav` 仅重绘侧栏，不重建窗口避免 iframe 重载；`countNavLeaves` 替代原 `siblingEntries` 判定默认收起，侧栏宽度 146px → 180px |
| 1.5.9 | 2026-09-29 | `window_width` / `window_height` 偏好的作用范围收窄为应用窗口：`defaultWindowSize(key)` 依据窗口标识判断，应用中心与 OS 设置这两个 WebOS 内置窗口忽略偏好、固定按出厂默认 78% / 80% 打开；设置界面说明文案同步为“仅作用于应用窗口” |
| 1.5.8 | 2026-09-29 | 窗口标题栏图标底色规则对齐应用中心：应用的 SVG/PNG 图片图标改为白色底，字体图标仍保持原彩色底；`is_system` 应用与 WebOS 内置窗口的 CMSPRO Logo 保持原有透明底 |
| 1.5.7 | 2026-09-29 | 应用详情页的“应用截图”由多行网格改为单行横向轨道，超出部分通过轨道两侧箭头左右切换；点击缩略图不再新标签页打开原图，改为弹出全屏预览层并支持在预览中左右切换（箭头按钮、`←` / `→` 方向键，`Esc` 或点击遮罩关闭） |
| 1.5.6 | 2026-09-29 | 应用市场切换分类时不再在列表区插入“正在加载该分类应用…”占位，改为清空旧卡片，加载提示统一由列表底部哨兵“正在读取应用市场…”承担，避免双提示同时出现 |
| 1.5.5 | 2026-09-29 | 背景设置“当前背景”改为左右布局（卡片占满整行，1180px 以下降级堆叠），右侧新增自定义壁纸库：按需 Ajax 加载、仅展示本人上传、缩略图点击切换背景、内联二次确认删除并自动回退默认壁纸；新增 `WallpaperService`（`listFor` / `store` / `delete`）与 `GET api/wallpapers`、`DELETE api/wallpaper` 接口，越权或非法路径返回 `40302` |
| 1.5.4 | 2026-09-29 | 应用市场新增应用详情页：点击卡片进入覆盖式详情层，展示基本信息、应用介绍、应用截图与版本记录，支持详情页内直接安装/更新，返回列表保留分页与滚动状态 |
| 1.5.3 | 2026-09-29 | 桌面空白处右键菜单改为白名单判定（桌面根节点与图标层空白处），修复应用窗口、任务栏与面板内右键误弹桌面菜单；修复 `.app-center-status`、`.market-tabs` 的 `display: flex` 覆盖 `hidden` 导致市场状态行常驻显示 |
| 1.5.2 | 2026-09-29 | 修复应用市场分页解析（`pagination` 位于响应 data 层）；移除市场连接状态行；分类切换显示加载占位；搜索改为 `keyword` 全量 Ajax 搜索并支持滚动分页 |
| 1.5.1 | 2026-09-29 | 入口管理“可用菜单”改为树结构展示：应用菜单按应用聚合（文件夹图标），系统菜单保留原生层级，默认全部收起，叶子菜单显示自身图标并支持添加到桌面 |
| 1.5.0 | 2026-09-29 | 窗口 8 方向拖拽调整宽高；背景设置支持自定义壁纸上传与恢复默认（新增壁纸上传接口，偏好新增 `wallpaper_url` 相对路径字段） |
| 1.4.9 | 2026-09-29 | 应用中心“已安装”排序对齐后台；应用市场分类 Tab 与 Ajax 分页加载；SVG/PNG 图标白色底；开始菜单搜索框移入标题栏；固定应用运行时复用固定图标；OS 设置拆分基本设置/背景设置 Tab；桌面空白处右键菜单（刷新、设置背景、个性设置、显示桌面） |
| 1.4.8 | 2026-09-29 | 日历显示农历与节气并按选择状态显示“回到今天”；状态栏上/下停靠时时钟居中；最大化窗口显示还原图标；任务栏左/右停靠时开始菜单对齐开始按钮；卸载应用后自动移除其桌面与任务栏图标；任务栏运行与固定图标支持右键菜单 |
| 1.4.7 | 2026-09-29 | OS 设置新增窗口默认宽高（按百分比），新建窗口按该比例显示 |
| 1.4.6 | 2026-09-29 | 账号菜单移除任务栏位置设置，个人设置改为打开后台个人中心页，新增修改密码弹窗 |
| 1.4.5 | 2026-09-29 | 任务栏时间按方位自适应分行，新增点击时间弹出的日历面板 |
| 1.4.4 | 2026-09-29 | 应用窗口左侧菜单栏固定宽度调整为 146px，窄屏断点不再单独加宽 |
| 1.4.3 | 2026-09-29 | 桌面图标右键菜单（打开应用、删除图标、卸载应用），桌面入口保存 `app_id` |
| 1.4.2 | 2026-09-29 | 面板外点击收起开始菜单、通知中心与账号菜单 |
| 1.4.1 | 2026-09-29 | 已安装列表精简为三列；手动升级与导出改为直接操作，管理入口移入更多菜单；状态筛选收敛并限高操作弹窗；开始菜单固定到桌面使用应用名称 |
| 1.4.0 | 2026-09-29 | 图标规则扩展到桌面、任务栏和开始菜单；应用市场接入远程图标与安装升级状态；已安装改为列表视图并新增状态筛选、应用操作与上传安装 |
| 1.3.3 | 2026-09-29 | 应用窗口标题显示当前应用图标，系统应用继续使用 CMSPRO Logo |
| 1.3.2 | 2026-09-29 | 应用窗口菜单支持手动收起；单菜单应用默认隐藏左侧菜单 |
| 1.3.1 | 2026-09-29 | 已安装与未安装应用图标支持根目录 SVG、PNG 和清单图标三级回退 |
| 1.3.0 | 2026-09-29 | 系统菜单按文件夹打开；新增常用入口、开始菜单系统操作和 OS 设置窗口 |
| 1.2.0 | 2026-09-29 | 开始菜单限定为后台菜单；安装应用时自动识别 admin/user/home 菜单并按终端选择顶级挂载位置 |
| 1.1.0 | 2026-09-29 | 缩小任务栏并支持四边停靠；应用窗口按应用隔离菜单；开始菜单按顶级菜单与应用聚合；应用中心新增未安装列表 |
| 1.0.0 | 2026-09-28 | 初始版本：桌面、开始菜单、多窗口、任务栏、应用中心、通知与账号集成 |
