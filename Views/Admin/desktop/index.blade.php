<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>CMSPRO WebOS</title>
    <link rel="stylesheet" href="{{ asset('CmsProUi/component/pear/css/pear.css') }}">
    <link rel="stylesheet" href="{{ asset('CmsProUi/font-awesome/4.7.0/css/font-awesome.min.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/admin.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/variables.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/reset.css') }}">
    <link rel="stylesheet" href="{{ asset('apps/cmspro.webos/css/webos.css') }}">
    @include('admin.partials.permission-script')
</head>
<body>
<main
    id="webos-desktop"
    class="webos-desktop is-loading"
    data-workspace-url="{{ url('/admin/cmspro/webos/api/workspace') }}"
    data-catalog-url="{{ url('/admin/cmspro/webos/api/catalog') }}"
    data-wallpaper-url="{{ asset('apps/cmspro.webos/images/webos-wallpaper.png') }}"
    data-login-url="{{ route('admin.login') }}"
    data-market-base-url="{{ $marketBaseUrl }}"
    data-taskbar-position="bottom"
>
    <div class="webos-wallpaper" aria-hidden="true"></div>

    <section id="desktop-icons" class="desktop-icons" aria-label="桌面快捷方式"></section>
    <section id="window-layer" class="window-layer" aria-label="应用窗口"></section>

    <section id="start-panel" class="webos-panel start-panel" aria-label="开始菜单" hidden>
        <header class="panel-header start-panel-header">
            <div class="brand-lockup">
                <img src="{{ asset('Images/logo-100x100.png') }}" alt="CMSPRO">
                <div>
                    <strong>CMSPRO</strong>
                    <span>WebOS 管理桌面</span>
                </div>
            </div>
            <button class="icon-button" type="button" data-action="close-start" aria-label="关闭开始菜单">
                <i class="fa fa-times" aria-hidden="true"></i>
            </button>
        </header>
        <div class="start-search-wrap">
            <i class="fa fa-search" aria-hidden="true"></i>
            <input id="start-search" type="search" placeholder="搜索菜单与应用" autocomplete="off">
        </div>
        <div class="start-columns">
            <nav id="start-categories" class="start-categories" aria-label="菜单分组"></nav>
            <div class="start-content">
                <div class="start-content-title">
                    <div>
                        <strong id="start-group-title">全部应用</strong>
                        <span id="start-group-count">0 个入口</span>
                    </div>
                    <button type="button" class="text-button" data-open-special="entries">管理入口</button>
                </div>
                <div id="start-app-grid" class="start-app-grid"></div>
            </div>
        </div>
        <footer class="start-system-actions" aria-label="系统操作">
            <button type="button" data-action="lock-desktop"><i class="fa fa-lock"></i>锁定</button>
            <button type="button" data-action="logout"><i class="fa fa-sign-out"></i>退出登录</button>
            <button type="button" class="os-settings" data-open-webos-settings><i class="fa fa-cog"></i>OS 设置</button>
        </footer>
    </section>

    <section id="notification-panel" class="webos-panel notification-panel" aria-label="通知中心" hidden>
        <header class="panel-header">
            <div>
                <strong>通知中心</strong>
                <span id="notification-summary">正在读取通知</span>
            </div>
            <button id="notification-read-all" class="text-button" type="button">全部已读</button>
        </header>
        <div id="notification-list" class="notification-list"></div>
        <a class="panel-footer-link" href="/admin/notifications" target="_blank" rel="noopener">查看全部通知</a>
    </section>

    <section id="calendar-panel" class="webos-panel calendar-panel" aria-label="日历" hidden>
        <header class="panel-header">
            <div>
                <strong id="calendar-title">--</strong>
                <span id="calendar-subtitle">--</span>
            </div>
            <button class="icon-button" type="button" data-action="close-calendar" aria-label="关闭日历">
                <i class="fa fa-times" aria-hidden="true"></i>
            </button>
        </header>
        <div class="calendar-toolbar">
            <button class="icon-button" type="button" data-calendar-nav="prev" aria-label="上个月"><i class="fa fa-chevron-left"></i></button>
            <button class="text-button" type="button" data-calendar-today>回到今天</button>
            <button class="icon-button" type="button" data-calendar-nav="next" aria-label="下个月"><i class="fa fa-chevron-right"></i></button>
        </div>
        <div class="calendar-weekdays" aria-hidden="true">
            <span>一</span><span>二</span><span>三</span><span>四</span><span>五</span><span>六</span><span>日</span>
        </div>
        <div class="calendar-grid" id="calendar-grid"></div>
    </section>

    <section id="account-panel" class="webos-panel account-panel" aria-label="账号菜单" hidden>
        <div class="account-card">
            <span class="account-avatar" aria-hidden="true">
                @if($admin->avatar)
                    <img src="{{ $admin->avatar }}" alt="">
                @else
                    <i class="fa fa-user" aria-hidden="true"></i>
                @endif
            </span>
            <div>
                <strong>{{ $admin->name ?: $admin->username }}</strong>
                <span>{{ $admin->username }}</span>
            </div>
        </div>
        <div class="taskbar-position-setting">
            <span>任务栏位置</span>
            <div class="taskbar-position-options" role="group" aria-label="任务栏位置">
                <button type="button" data-set-taskbar-position="top" title="置于顶部" aria-label="任务栏置于顶部"><i class="fa fa-arrow-up"></i></button>
                <button type="button" data-set-taskbar-position="bottom" title="置于底部" aria-label="任务栏置于底部"><i class="fa fa-arrow-down"></i></button>
                <button type="button" data-set-taskbar-position="left" title="置于左侧" aria-label="任务栏置于左侧"><i class="fa fa-arrow-left"></i></button>
                <button type="button" data-set-taskbar-position="right" title="置于右侧" aria-label="任务栏置于右侧"><i class="fa fa-arrow-right"></i></button>
            </div>
        </div>
        <nav class="account-actions">
            <button type="button" data-account-path="/admin/config"><i class="fa fa-cog"></i>个人设置</button>
            <button type="button" data-action="lock-desktop"><i class="fa fa-lock"></i>锁定桌面</button>
            <button type="button" class="danger" data-action="logout"><i class="fa fa-sign-out"></i>退出登录</button>
        </nav>
    </section>

    <section id="install-dialog" class="webos-dialog" role="dialog" aria-modal="true" aria-labelledby="install-dialog-title" hidden>
        <div class="dialog-card">
            <header class="dialog-header">
                <div>
                    <span class="dialog-kicker">应用安装</span>
                    <h2 id="install-dialog-title">安装应用</h2>
                </div>
                <button class="icon-button" type="button" data-action="close-install" aria-label="关闭安装窗口">
                    <i class="fa fa-times"></i>
                </button>
            </header>
            <div class="dialog-body">
                <div id="install-app-summary" class="install-app-summary"></div>
                <fieldset class="install-options">
                    <legend>请选择应用入口位置</legend>
                    <label>
                        <input type="radio" name="install_entry" value="menu">
                        <span class="option-icon"><i class="fa fa-bars"></i></span>
                        <span><strong>添加到系统菜单</strong><small>在开始菜单的应用列表中创建入口</small></span>
                    </label>
                    <label>
                        <input type="radio" name="install_entry" value="desktop">
                        <span class="option-icon"><i class="fa fa-desktop"></i></span>
                        <span><strong>创建桌面快捷方式</strong><small>安装后将应用入口固定到桌面</small></span>
                    </label>
                    <label class="is-selected">
                        <input type="radio" name="install_entry" value="both" checked>
                        <span class="option-icon"><i class="fa fa-th-large"></i></span>
                        <span><strong>两者都创建</strong><small>同时创建系统菜单和桌面快捷入口</small></span>
                    </label>
                </fieldset>
                <section id="install-menu-parents" class="install-menu-parents" aria-live="polite">
                    <div class="install-menu-status">
                        <i class="fa fa-circle-o-notch fa-spin"></i>
                        <span>正在识别应用菜单…</span>
                    </div>
                </section>
            </div>
            <footer class="dialog-footer">
                <button class="webos-button secondary" type="button" data-action="close-install">取消</button>
                <button id="confirm-install" class="webos-button primary" type="button">
                    <i class="fa fa-download"></i>安装
                </button>
            </footer>
        </div>
    </section>

    <section id="action-dialog" class="webos-dialog" role="dialog" aria-modal="true" aria-labelledby="action-dialog-title" hidden>
        <div class="dialog-card action-dialog-card">
            <header class="dialog-header">
                <div>
                    <span class="dialog-kicker" id="action-dialog-kicker">应用操作</span>
                    <h2 id="action-dialog-title">应用操作</h2>
                </div>
                <button class="icon-button" type="button" data-action="close-action" aria-label="关闭操作窗口">
                    <i class="fa fa-times"></i>
                </button>
            </header>
            <div class="dialog-body" id="action-dialog-body"></div>
            <footer class="dialog-footer" id="action-dialog-footer"></footer>
        </div>
    </section>
    <input id="app-package-input" type="file" accept=".zip" hidden>

    <section id="lock-screen" class="lock-screen" hidden>
        <div class="lock-time" id="lock-time">00:00</div>
        <div class="lock-date" id="lock-date">2026年9月28日</div>
        <button class="webos-button light" type="button" data-action="unlock-desktop">
            <i class="fa fa-unlock-alt"></i>返回桌面
        </button>
    </section>

    <div id="webos-toast-region" class="toast-region" aria-live="polite" aria-atomic="true"></div>

    <footer class="webos-taskbar">
        <button id="start-button" class="taskbar-button brand-button" type="button" aria-label="打开开始菜单" aria-expanded="false">
            <img src="{{ asset('Images/logo-80x80.png') }}" alt="">
        </button>
        <div id="taskbar-pinned" class="taskbar-pinned" aria-label="固定应用"></div>
        <div id="taskbar-windows" class="taskbar-windows" aria-label="运行中的应用"></div>
        <div class="taskbar-spacer"></div>
        <button id="notification-button" class="taskbar-button notification-button" type="button" aria-label="打开通知中心" aria-expanded="false">
            <i class="fa fa-bell" aria-hidden="true"></i>
            <span id="notification-badge" class="notification-badge" hidden>0</span>
        </button>
        <span class="taskbar-divider" aria-hidden="true"></span>
        <button id="account-button" class="account-button" type="button" aria-label="打开账号菜单" aria-expanded="false">
            <span class="taskbar-avatar">
                @if($admin->avatar)
                    <img src="{{ $admin->avatar }}" alt="">
                @else
                    <i class="fa fa-user" aria-hidden="true"></i>
                @endif
            </span>
            <span>{{ $admin->name ?: $admin->username }}</span>
        </button>
        <span class="taskbar-divider" aria-hidden="true"></span>
        <button id="clock-button" class="clock-button" type="button" aria-label="当前日期与时间" aria-haspopup="dialog" aria-expanded="false">
            <span id="clock-date">--</span>
            <span class="clock-meta">
                <span id="clock-weekday">--</span>
                <span id="clock-time">--:--</span>
            </span>
        </button>
        <button id="show-desktop-button" class="show-desktop-button" type="button" aria-label="显示桌面"></button>
    </footer>

    <div id="loading-screen" class="loading-screen" role="status">
        <img src="{{ asset('Images/logo-120x120.png') }}" alt="CMSPRO">
        <strong>CMSPRO WebOS</strong>
        <span>正在准备管理桌面…</span>
    </div>

    <div id="viewport-warning" class="viewport-warning" hidden>
        <i class="fa fa-desktop"></i>
        <strong>建议使用更宽的窗口</strong>
        <span>WebOS 管理桌面需要至少 960px 的可用宽度。</span>
    </div>
</main>

<script>
window.CMSPRO_WEBOS = {{ Illuminate\Support\Js::from($webosRuntime) }};
</script>
<script src="{{ asset('CmsProUi/component/layui/layui.js') }}"></script>
<script src="{{ asset('CmsProUi/component/marked/marked.min.js') }}"></script>
<script src="{{ asset('apps/cmspro.webos/js/webos.js') }}"></script>
</body>
</html>
