(function () {
    'use strict';

    var root = document.getElementById('webos-desktop');
    if (!root) {
        return;
    }

    var runtime = window.CMSPRO_WEBOS || {};
    // 应用中心与 WebOS 升级提醒仅对超级管理员开放（后端注入）
    var isSuperAdmin = !!(runtime.admin && runtime.admin.is_super_admin);

    // 桌面图标三档尺寸：格子尺寸用于布局定位与拖拽落点换算（iconW/iconH 为按钮盒尺寸，与 CSS [data-icon-size] 覆盖样式一一对应）
    var DESKTOP_ICON_SIZES = {
        large: { cellW: 128, cellH: 130, iconW: 112, iconH: 122 },
        medium: { cellW: 102, cellH: 104, iconW: 88, iconH: 96 },
        small: { cellW: 86, cellH: 88, iconW: 72, iconH: 78 }
    };

    /** 当前桌面图标尺寸档位（非法值回退中图标默认档） */
    function desktopIconSize() {
        var size = state.workspace.preferences.icon_size;
        return DESKTOP_ICON_SIZES[size] || DESKTOP_ICON_SIZES.medium;
    }
    var state = {
        workspace: {
            desktop_items: [],
            taskbar_items: [],
            preferences: {
                wallpaper: 'webos-default',
                taskbar_alignment: 'left',
                taskbar_position: 'bottom',
                clock_format: '24h',
                show_seconds: false,
                motion: true,
                window_width: 78,
                window_height: 80,
                usage_stats: {}
            }
        },
        desktopSelection: new Set(),
        catalog: { menus: [], applications: [], operation_logs: [] },
        flatMenus: [],
        activeGroup: '',
        windows: new Map(),
        zIndex: 20,
        installTarget: null,
        installTerminals: [],
        installRequestId: 0,
        marketApps: new Map(),
        marketPager: { category: '', keyword: '', page: 0, lastPage: 1, total: 0, loading: false, failed: false, token: 0 },
        marketSubTab: 'home',
        updateApps: [],
        updateChecked: false,
        updateCount: null,
        appCenterTab: 'market',
        docAppId: '',
        startItems: [],
        calendarView: null,
        calendarSelected: '',
        calendarMonths: new Map(),
        calendarPending: new Set(),
        desktopSnapshot: [],
        wallpapers: null
    };

    var elements = {
        desktopIcons: document.getElementById('desktop-icons'),
        windowLayer: document.getElementById('window-layer'),
        startButton: document.getElementById('start-button'),
        startPanel: document.getElementById('start-panel'),
        startSearch: document.getElementById('start-search'),
        startCategories: document.getElementById('start-categories'),
        startGrid: document.getElementById('start-app-grid'),
        startGroupTitle: document.getElementById('start-group-title'),
        startGroupCount: document.getElementById('start-group-count'),
        taskbarPinned: document.getElementById('taskbar-pinned'),
        taskbarWindows: document.getElementById('taskbar-windows'),
        websiteButton: document.getElementById('website-button'),
        notificationButton: document.getElementById('notification-button'),
        notificationPanel: document.getElementById('notification-panel'),
        notificationBadge: document.getElementById('notification-badge'),
        notificationSummary: document.getElementById('notification-summary'),
        notificationTodos: document.getElementById('notification-todos'),
        notificationList: document.getElementById('notification-list'),
        accountButton: document.getElementById('account-button'),
        accountPanel: document.getElementById('account-panel'),
        clockButton: document.getElementById('clock-button'),
        clockDate: document.getElementById('clock-date'),
        clockWeekday: document.getElementById('clock-weekday'),
        clockTime: document.getElementById('clock-time'),
        calendarPanel: document.getElementById('calendar-panel'),
        calendarTitle: document.getElementById('calendar-title'),
        calendarSubtitle: document.getElementById('calendar-subtitle'),
        calendarGrid: document.getElementById('calendar-grid'),
        calendarToday: document.getElementById('calendar-today'),
        lockScreen: document.getElementById('lock-screen'),
        lockDate: document.getElementById('lock-date'),
        lockTime: document.getElementById('lock-time'),
        installDialog: document.getElementById('install-dialog'),
        installSummary: document.getElementById('install-app-summary'),
        installParents: document.getElementById('install-menu-parents'),
        installCreateShortcut: document.getElementById('install-create-shortcut'),
        confirmInstall: document.getElementById('confirm-install'),
        actionDialog: document.getElementById('action-dialog'),
        actionDialogKicker: document.getElementById('action-dialog-kicker'),
        actionDialogTitle: document.getElementById('action-dialog-title'),
        actionDialogBody: document.getElementById('action-dialog-body'),
        actionDialogFooter: document.getElementById('action-dialog-footer'),
        toastRegion: document.getElementById('webos-toast-region')
    };

    var modalFocusableSelector = [
        'button:not([disabled])',
        'a[href]',
        'input:not([disabled]):not([type="hidden"])',
        'select:not([disabled])',
        'textarea:not([disabled])',
        '[tabindex]:not([tabindex="-1"])'
    ].join(',');

    function modalFocusables(dialog) {
        return Array.prototype.filter.call(dialog.querySelectorAll(modalFocusableSelector), function (element) {
            return !element.hidden && element.getClientRects().length > 0;
        });
    }

    function showModalDialog(dialog) {
        dialog._webosReturnFocus = document.activeElement;
        dialog.hidden = false;
        window.requestAnimationFrame(function () {
            if (dialog.hidden) {
                return;
            }
            var focusables = modalFocusables(dialog);
            if (focusables.length) {
                focusables[0].focus();
            }
        });
    }

    function hideModalDialog(dialog) {
        if (!dialog || dialog.hidden) {
            return;
        }
        var returnFocus = dialog._webosReturnFocus;
        dialog._webosReturnFocus = null;
        dialog.hidden = true;
        if (returnFocus && returnFocus.isConnected && typeof returnFocus.focus === 'function') {
            window.requestAnimationFrame(function () { returnFocus.focus(); });
        }
    }

    function activeModalDialog() {
        if (!elements.actionDialog.hidden) {
            return elements.actionDialog;
        }
        return elements.installDialog.hidden ? null : elements.installDialog;
    }

    function trapModalFocus(event, dialog) {
        var focusables = modalFocusables(dialog);
        if (!focusables.length) {
            event.preventDefault();
            return;
        }
        var first = focusables[0];
        var last = focusables[focusables.length - 1];
        if (!dialog.contains(document.activeElement)) {
            event.preventDefault();
            first.focus();
            return;
        }
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>'"]/g, function (character) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                "'": '&#39;',
                '"': '&quot;'
            }[character];
        });
    }

    /** 图标别名映射：iconPicker 存储的短代码别名 → layui 图标类名（与框架 ConfigController::buildMenuTree 一致） */
    var ICON_ALIASES = {
        IconSetting: 'layui-icon layui-icon-set',
        IconUser: 'layui-icon layui-icon-user',
        IconShield: 'layui-icon layui-icon-vercode',
        IconMenu: 'layui-icon layui-icon-menu-fill',
        IconImage: 'layui-icon layui-icon-picture',
        IconFile: 'layui-icon layui-icon-file'
    };

    function safeIcon(icon) {
        var className = String(icon || 'fa fa-cube').replace(/[^A-Za-z0-9 _-]/g, '');
        if (ICON_ALIASES[className]) { return ICON_ALIASES[className]; }
        return className || 'fa fa-cube';
    }

    function safePath(path) {
        return typeof path === 'string' && path.charAt(0) === '/' && path.indexOf('//') !== 0;
    }

    /** _blank 用于跳转第三方系统或独立工具，因此在站内路径之外额外放行 http(s) 外链 */
    function externalPath(path) {
        return typeof path === 'string' && /^https?:\/\/[^/\s]/i.test(path);
    }

    /** 菜单路径是否可打开：站内路径一律放行，_blank 额外放行 http(s) 外链 */
    function openablePath(entry) {
        return safePath(entry.path) || (entry.open_type === '_blank' && externalPath(entry.path));
    }

    function marketBaseUrl() {
        return root.dataset.marketBaseUrl || '';
    }

    function isImageIcon(icon) {
        return typeof icon === 'string' && (/^\//.test(icon) || /^https?:\/\//.test(icon));
    }

    function marketIconUrl(icon) {
        if (!icon) {
            return '';
        }
        if (/^https?:\/\//i.test(icon)) {
            return icon;
        }
        return marketBaseUrl() + (icon.charAt(0) === '/' ? icon : '/' + icon);
    }

    function compareVersions(leftVersion, rightVersion) {
        var left = String(leftVersion || '0').split('.');
        var right = String(rightVersion || '0').split('.');
        var length = Math.max(left.length, right.length);
        for (var index = 0; index < length; index += 1) {
            var leftNumber = parseInt(left[index], 10) || 0;
            var rightNumber = parseInt(right[index], 10) || 0;
            if (leftNumber < rightNumber) {
                return -1;
            }
            if (leftNumber > rightNumber) {
                return 1;
            }
        }
        return 0;
    }

    function installedVersions() {
        var versions = {};
        state.catalog.applications.forEach(function (application) {
            versions[String(application.app_id || '').toLowerCase()] = application.version || '';
        });
        return versions;
    }

    function formatFileSize(size) {
        var bytes = Number(size) || 0;
        if (bytes <= 0) {
            return '-';
        }
        if (bytes < 1024) {
            return bytes + ' B';
        }
        if (bytes < 1024 * 1024) {
            return (bytes / 1024).toFixed(1) + ' KB';
        }
        return (bytes / 1024 / 1024).toFixed(2) + ' MB';
    }

    function api(url, options) {
        var request = options || {};
        request.headers = Object.assign({
            'Accept': 'application/json',
            'X-CSRF-TOKEN': runtime.csrfToken || ''
        }, request.headers || {});

        if (request.body && typeof request.body !== 'string' && !(request.body instanceof FormData)) {
            request.headers['Content-Type'] = 'application/json';
            request.body = JSON.stringify(request.body);
        }

        return fetch(url, request).then(function (response) {
            if (response.status === 401) {
                window.location.href = root.dataset.loginUrl || '/admin/login';
                throw new Error('登录状态已失效');
            }
            return response.json().catch(function () {
                throw new Error('服务器返回了无法识别的数据');
            }).then(function (payload) {
                if (!response.ok || payload.code !== 0) {
                    var validation = payload.errors ? Object.values(payload.errors).flat().join('；') : '';
                    throw new Error(validation || payload.message || '请求失败');
                }
                return payload.data;
            });
        });
    }

    /** _component（路由模式）需要的是 HTML 片段而非 JSON，因此单独走文本请求 */
    function fetchText(url) {
        return fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': runtime.csrfToken || ''
            }
        }).then(function (response) {
            if (response.status === 401) {
                window.location.href = root.dataset.loginUrl || '/admin/login';
                throw new Error('登录状态已失效');
            }
            if (!response.ok) {
                throw new Error('页面请求失败（HTTP ' + response.status + '）');
            }
            return response.text();
        });
    }

    function toast(message, type) {
        var item = document.createElement('div');
        item.className = 'webos-toast' + (type === 'error' ? ' is-error' : '');
        item.innerHTML = '<i class="fa ' + (type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle') + '"></i>'
            + '<span>' + escapeHtml(message) + '</span>';
        elements.toastRegion.appendChild(item);
        window.setTimeout(function () { item.remove(); }, 3400);
    }

    function flattenMenus(tree) {
        var output = [];

        /** 输出一个可打开菜单叶子（folder 入参仅在没有 app_id 时用于文件夹归属；appNodeId 为所属应用文件夹节点 id） */
        function pushLeaf(item, group, appId, folder, appNodeId) {
            output.push({
                id: 'menu-' + item.id,
                menu_id: Number(item.id || 0),
                app_id: appId,
                app_node_id: appNodeId || '',
                title: item.name || '未命名菜单',
                path: item.path,
                icon: safeIcon(item.icon),
                open_type: item.open_type || '_iframe',
                group_id: group.id,
                group_title: group.title,
                folder_id: appId ? '' : String(folder.id),
                folder_title: appId ? '' : folder.title,
                folder_icon: appId ? '' : folder.icon,
                group_icon: group.icon
            });
        }

        function walk(items, group, inheritedAppId, folder, inheritedAppNodeId) {
            (items || []).forEach(function (item) {
                var isRoot = !group;
                var nextGroup = group || {
                    id: String(item.id || item.code || item.name),
                    title: item.name || '应用',
                    icon: safeIcon(item.icon)
                };
                var nextAppId = item.app_id || inheritedAppId || '';
                // 自带 app_id 的节点即应用文件夹节点：其后代叶子记录该节点 id，
                // 拖拽换分类时按节点整体移动（子树随父节点），而非散移叶子菜单
                var nextAppNodeId = item.app_id ? String(item.id || '') : (inheritedAppNodeId || '');
                var children = Array.isArray(item.children) ? item.children : [];
                var nextFolder = folder;
                if (!nextAppId && !nextFolder && !isRoot) {
                    nextFolder = children.length ? {
                        id: String(item.id || item.code || item.name),
                        title: item.name || nextGroup.title,
                        icon: safeIcon(item.icon || 'fa fa-folder')
                    } : nextGroup;
                } else if (!nextAppId && !nextFolder && !children.length) {
                    nextFolder = nextGroup;
                }
                if (children.length) {
                    var before = output.length;
                    walk(children, nextGroup, nextAppId, nextFolder, nextAppNodeId);
                    // 子树中没有任何可打开菜单（如「用户管理」下只挂新增/编辑/删除用户等
                    // 按钮权限项，path 为空会被 openablePath 过滤）且父菜单自身 path 可打开时，
                    // 父菜单自身输出为可打开菜单项，避免整个菜单从 WebOS 中消失
                    if (output.length === before && openablePath(item)) {
                        pushLeaf(item, nextGroup, nextAppId, nextFolder, nextAppNodeId);
                    }
                    return;
                }
                if (!openablePath(item)) {
                    return;
                }
                pushLeaf(item, nextGroup, nextAppId, nextFolder, nextAppNodeId);
            });
        }

        walk(tree || [], null, '', null, '');
        return output;
    }

    function applicationCenterEntry() {
        return {
            id: 'webos-app-center',
            title: '应用中心',
            path: '/admin/cmspro/webos?app=market',
            icon: 'fa fa-shopping-bag',
            group_id: 'webos',
            group_title: 'WebOS',
            group_icon: 'fa fa-desktop',
            special: 'market'
        };
    }

    function webosSettingsEntry() {
        return {
            id: 'webos-settings',
            title: 'OS 设置',
            path: '/admin/cmspro/webos?app=settings',
            icon: 'fa fa-cog',
            group_id: 'webos',
            group_title: 'WebOS',
            group_icon: 'fa fa-desktop',
            special: 'settings'
        };
    }

    function notificationCenterEntry() {
        return {
            id: 'webos-notification-page',
            title: '通知中心',
            path: '/admin/cmspro/webos?app=notifications',
            icon: 'fa fa-bell-o',
            group_id: 'webos',
            group_title: 'WebOS',
            group_icon: 'fa fa-desktop',
            special: 'notifications'
        };
    }

    /** 官网动态窗口入口：仅超级管理员桌面自动打开并停靠桌面最右侧（数据来自官网公开接口） */
    function officialNewsEntry() {
        return {
            id: 'webos-official-news',
            title: '官网动态',
            path: '/admin/cmspro/webos?app=official-news',
            icon: 'fa fa-bullhorn',
            group_id: 'webos',
            group_title: 'WebOS',
            group_icon: 'fa fa-desktop',
            special: 'official-news'
        };
    }

    function normalizeDesktopItems(items) {
        return (items || []).filter(function (item) {
            return item && item.id && safePath(item.path);
        }).map(function (item, index) {
            return Object.assign({}, item, {
                icon: safeIcon(item.icon),
                x: Number.isInteger(item.x) ? item.x : 0,
                y: Number.isInteger(item.y) ? item.y : index
            });
        });
    }

    function hydrateDesktopAppIds() {
        var changed = false;
        state.workspace.desktop_items.forEach(function (item) {
            if (item.app_id) {
                return;
            }
            var entry = findEntry(item.id);
            if (entry && entry.app_id) {
                item.app_id = entry.app_id;
                changed = true;
            }
        });
        return changed;
    }

    function bootstrapDesktopItems() {
        state.workspace.desktop_items = normalizeDesktopItems(state.workspace.desktop_items);
        if (!Array.isArray(state.workspace.taskbar_items)) {
            state.workspace.taskbar_items = [];
        }
        if (hydrateDesktopAppIds()) {
            saveWorkspace(false).catch(function () {});
        }
        if (state.workspace.desktop_items.length) {
            return Promise.resolve();
        }

        // 初始数据：超级管理员只固定「应用中心」，非超管不预置（应用中心仅超管可用）；系统菜单由用户按需从入口管理添加
        var selected = isSuperAdmin ? [applicationCenterEntry()] : [];
        state.workspace.desktop_items = selected.map(function (item, index) {
            return Object.assign({}, item, { x: 0, y: index });
        });
        // 任务栏初始同样固定「应用中心」（两列表相互独立，仅初始默认值相同）
        if (isSuperAdmin && !state.workspace.taskbar_items.length) {
            state.workspace.taskbar_items = [Object.assign({}, applicationCenterEntry())];
        }

        return saveWorkspace(false);
    }

    /** 任务栏固定项：与桌面快捷方式相互独立的列表；旧数据无记录时回退桌面项前 4 位；按 id 去重兜底历史重复数据 */
    function taskbarItems() {
        var items = Array.isArray(state.workspace.taskbar_items)
            ? state.workspace.taskbar_items
            : state.workspace.desktop_items.slice(0, 4);
        var seen = Object.create(null);
        return items.filter(function (item) {
            if (!item || !item.id || seen[item.id]) {
                return false;
            }
            seen[item.id] = true;
            return true;
        });
    }

    function saveWorkspace(showMessage) {
        return api(root.dataset.workspaceUrl, {
            method: 'PUT',
            body: {
                desktop_items: state.workspace.desktop_items.map(function (item) {
                    return {
                        id: item.id,
                        menu_id: item.menu_id || null,
                        app_id: item.app_id || '',
                        title: item.title,
                        path: item.path,
                        icon: safeIcon(item.icon),
                        group_title: item.group_title || '应用',
                        x: Math.max(0, Math.min(99, Number(item.x) || 0)),
                        y: Math.max(0, Math.min(99, Number(item.y) || 0))
                    };
                }),
                taskbar_items: taskbarItems().map(function (item) {
                    return {
                        id: item.id,
                        menu_id: item.menu_id || null,
                        app_id: item.app_id || '',
                        title: item.title,
                        path: item.path,
                        icon: safeIcon(item.icon),
                        group_title: item.group_title || '应用'
                    };
                }),
                preferences: state.workspace.preferences
            }
        }).then(function (workspace) {
            state.workspace = workspace;
            if (!Array.isArray(state.workspace.taskbar_items)) {
                state.workspace.taskbar_items = [];
            }
            if (showMessage) {
                toast('桌面布局已保存');
            }
            return workspace;
        }).catch(function (error) {
            toast(error.message, 'error');
            throw error;
        });
    }

    function renderDesktop() {
        // 图标尺寸档位：格子尺寸驱动布局，data-icon-size 驱动 CSS 视觉（badge/字号/按钮盒）
        var size = desktopIconSize();
        root.dataset.iconSize = state.workspace.preferences.icon_size === 'large' || state.workspace.preferences.icon_size === 'small'
            ? state.workspace.preferences.icon_size
            : 'medium';
        elements.desktopIcons.innerHTML = state.workspace.desktop_items.filter(function (item) {
            // 与框架权限对齐：入口未分配（菜单/应用被收回授权）时桌面不再显示对应图标
            return entryAssigned(item.id);
        }).map(function (item) {
            var left = 4 + Math.max(0, Number(item.x) || 0) * size.cellW;
            var top = 4 + Math.max(0, Number(item.y) || 0) * size.cellH;
            // 桌面「应用中心」图标右上角显示可更新应用数量（无更新时不渲染）
            var updateBadge = item.id === 'webos-app-center' && state.updateApps.length
                ? '<span class="desktop-update-badge">' + state.updateApps.length + '</span>'
                : '';
            return '<button class="desktop-icon' + (state.desktopSelection.has(item.id) ? ' is-selected' : '') + '" type="button" data-desktop-id="' + escapeHtml(item.id) + '"'
                + ' style="left:' + left + 'px;top:' + top + 'px" title="' + escapeHtml(item.title) + '">'
                + entryIconMarkup(findEntry(item.id) || item, 'desktop-icon-badge')
                + '<span class="desktop-icon-label">' + escapeHtml(item.title) + '</span>'
                + updateBadge
                + '</button>';
        }).join('');
        renderTaskbarWindows();
    }

    /** 应用聚合键：同应用（或特殊入口）归并为一个任务栏图标 */
    function entryAppKey(entry) {
        return entry && entry.app_id ? 'app:' + entry.app_id : 'entry:' + (entry ? entry.id : '');
    }

    /** 固定在任务栏的入口聚合键集合（任务栏固定项独立于桌面） */
    function pinnedTaskbarKeys() {
        return new Set(taskbarItems().map(function (item) {
            return entryAppKey(findEntry(item.id) || item);
        }));
    }

    /** 查找入口所属应用的运行窗口 key；固定图标运行时复用该窗口（Windows 风格） */
    function runningWindowKeyByApp(entry) {
        var appKey = entryAppKey(entry);
        var found = null;
        state.windows.forEach(function (windowState, key) {
            if (!found && entryAppKey(windowState.entry) === appKey) {
                found = key;
            }
        });
        return found;
    }

    function renderPinnedApps() {
        var pinned = taskbarItems().filter(function (item) {
            // 与桌面一致：未分配的入口不在任务栏固定区显示
            return entryAssigned(item.id);
        });
        elements.taskbarPinned.innerHTML = pinned.map(function (item) {
            var entry = findEntry(item.id) || item;
            var windowKey = runningWindowKeyByApp(entry);
            var classes = 'taskbar-app-button';
            if (windowKey) {
                classes += ' is-running';
                var target = state.windows.get(windowKey);
                if (target && !target.minimized && target.element.classList.contains('is-focused')) {
                    classes += ' is-active';
                }
            }
            return '<button class="' + classes + '" type="button" data-pinned-launch-id="' + escapeHtml(item.id) + '" title="' + escapeHtml(item.title) + '">'
                + entryIconMarkup(entry, 'taskbar-app-icon') + '</button>';
        }).join('');
    }

    function menuGroups() {
        var groups = new Map();
        state.flatMenus.forEach(function (item) {
            // WebOS 自身菜单不在系统菜单显示：用户已在 WebOS 桌面内，无需从系统菜单再开 WebOS
            // （应用中心入口保留在桌面与任务栏，不受影响）
            if (item.app_id === 'cmspro.webos') {
                return;
            }
            if (!groups.has(item.group_id)) {
                groups.set(item.group_id, {
                    id: item.group_id,
                    title: item.group_title,
                    icon: item.group_icon,
                    entries: []
                });
            }
            groups.get(item.group_id).entries.push(item);
        });
        return Array.from(groups.values());
    }

    function startItemsForGroup(group) {
        var applications = new Map();
        group.entries.forEach(function (entry) {
            var key = entry.special ? 'special:' + entry.id
                : (entry.app_id ? 'app:' + entry.app_id : 'folder:' + entry.folder_id);
            if (!applications.has(key)) {
                applications.set(key, []);
            }
            applications.get(key).push(entry);
        });

        return Array.from(applications.entries()).map(function (pair) {
            var entries = pair[1];
            var representative = defaultEntryOf(entries);
            var application = representative.app_id ? state.catalog.applications.find(function (item) {
                return item.app_id === representative.app_id;
            }) : null;
            var applicationIcon = application && typeof application.icon === 'string' && application.icon.indexOf('/') < 0
                ? application.icon : representative.icon;
            // 拖拽换顶级分类：整个应用（含应用文件夹节点）整体移动——优先收应用文件夹节点 id
            //（服务端只改父节点、子树随行），直接挂在分类下的散叶子补自身 id；目录卡片收目录节点 id
            var moveIds = [];
            if (!representative.special) {
                if (representative.app_id) {
                    entries.forEach(function (entry) {
                        if (entry.app_node_id) {
                            if (moveIds.indexOf(entry.app_node_id) < 0) { moveIds.push(Number(entry.app_node_id)); }
                        } else {
                            moveIds.push(entry.menu_id);
                        }
                    });
                } else {
                    moveIds.push(Number(representative.folder_id));
                }
            }
            var item = Object.assign({}, representative, {
                start_key: pair[0],
                start_kind: representative.app_id ? 'application' : 'folder',
                start_title: application ? application.name : representative.folder_title,
                // 目录项优先用目录/分组自身设置的图标，无图标时才以文件夹图标兜底
                start_icon: application
                    ? safeIcon(applicationIcon)
                    : (representative.folder_icon || representative.group_icon || 'fa fa-folder'),
                start_subtitle: entries.length + (application ? ' 个菜单' : ' 个子菜单'),
                start_search: [application ? application.name : representative.folder_title, entries.map(function (entry) {
                    return entry.title;
                }).join(' ')].join(' '),
                move_ids: moveIds
            });
            if (representative.special) {
                item.start_kind = 'application';
                item.start_title = representative.title;
                item.start_icon = representative.icon;
                item.start_subtitle = 'WebOS 系统应用';
            }
            return item;
        });
    }

    function commonGroup(groups) {
        var statistics = state.workspace.preferences.usage_stats;
        if (!statistics || Array.isArray(statistics)) {
            statistics = {};
        }
        var items = groups.reduce(function (all, group) { return all.concat(group.items); }, []);
        items = items.filter(function (item) { return statistics[item.start_key]; });
        items.sort(function (left, right) {
            var leftStat = statistics[left.start_key];
            var rightStat = statistics[right.start_key];
            return rightStat.count - leftStat.count
                || rightStat.last_opened_at.localeCompare(leftStat.last_opened_at);
        });
        return {
            id: 'common',
            title: '常用',
            icon: 'fa fa-star',
            entries: [],
            items: items.slice(0, 6).map(function (item) {
                return Object.assign({}, item, {
                    start_subtitle: '使用 ' + statistics[item.start_key].count + ' 次'
                });
            })
        };
    }

    function groupsFromMenus() {
        var groups = menuGroups().map(function (group) {
            group.items = startItemsForGroup(group);
            return group;
        });
        return [commonGroup(groups)].concat(groups);
    }

    function isFolderStartItem(entry) {
        return entry.start_kind === 'folder';
    }

    function renderStartMenu() {
        var groups = groupsFromMenus();
        var query = elements.startSearch.value.trim().toLowerCase();
        if (!groups.some(function (group) { return group.id === state.activeGroup; })) {
            state.activeGroup = groups.length ? groups[0].id : '';
        }
        elements.startCategories.innerHTML = groups.map(function (group) {
            return '<button class="start-category-button ' + (state.activeGroup === group.id ? 'is-active' : '')
                + '" type="button" data-group-id="' + escapeHtml(group.id) + '"><i class="' + safeIcon(group.icon)
                + '"></i>' + escapeHtml(group.title) + '</button>';
        }).join('');

        var current = groups.find(function (group) { return group.id === state.activeGroup; });
        var entries = current ? current.items : [];
        if (query) {
            entries = groups.reduce(function (all, group) { return all.concat(group.items); }, []).filter(function (item) {
                return item.start_search.toLowerCase().indexOf(query) >= 0;
            });
        }
        var unique = new Map();
        entries.forEach(function (item) { unique.set(item.start_key, item); });
        entries = Array.from(unique.values());
        state.startItems = entries;

        elements.startGroupTitle.textContent = query ? '搜索结果' : (current ? current.title : '应用');
        elements.startGroupCount.textContent = entries.length + ' 个项目';
        elements.startGrid.innerHTML = entries.length ? entries.map(function (item) {
            var isPinned = state.workspace.desktop_items.some(function (entry) { return entry.id === item.id; });
            var pinButton = isFolderStartItem(item) ? '' : '<button class="pin-button' + (isPinned ? ' is-pinned' : '') + '" type="button" data-pin-id="'
                + escapeHtml(item.id) + '" title="' + (isPinned ? '从桌面移除' : '添加到桌面')
                + '"><i class="fa ' + (isPinned ? 'fa-thumb-tack' : 'fa-plus') + '"></i></button>';
            // 拖拽换顶级分类：仅超管可见（菜单结构调整影响所有管理员），WebOS 特殊项与无菜单 id 的条目不可拖；
            // 搜索结果跨分类无单一来源语义，同样不启用
            var moveIds = isSuperAdmin && !item.special && item.move_ids && item.move_ids.length
                ? item.move_ids.join(',') : '';
            return '<div class="start-app-item ' + (isFolderStartItem(item) ? 'is-folder' : '')
                + '" tabindex="0" role="button" data-menu-id="' + escapeHtml(item.id) + '" data-start-kind="'
                + escapeHtml(item.start_kind) + '"'
                + (moveIds ? ' draggable="true" data-move-ids="' + moveIds + '"' : '') + '>'
                + (isFolderStartItem(item)
                    ? '<span class="start-app-item-icon"><i class="' + safeIcon(item.start_icon) + '"></i></span>'
                    : entryIconMarkup(item, 'start-app-item-icon'))
                + '<span class="start-app-item-text"><strong>' + escapeHtml(item.start_title) + '</strong><small>'
                + escapeHtml(item.start_subtitle || '应用') + '</small></span>'
                + pinButton
                + '</div>';
        }).join('') : '<div class="panel-empty"><i class="fa '
            + (!query && current && current.id === 'common' ? 'fa-star-o' : 'fa-search') + '"></i>'
            + (!query && current && current.id === 'common' ? '打开应用后，常用入口会显示在这里' : '没有匹配的入口') + '</div>';
    }

    function findEntry(id) {
        if (id === 'webos-app-center') {
            return applicationCenterEntry();
        }
        if (id === 'webos-settings') {
            return webosSettingsEntry();
        }
        return state.flatMenus.find(function (item) { return item.id === id; })
            || state.workspace.desktop_items.find(function (item) { return item.id === id; });
    }

    function formatLocalDateTime(date) {
        function pad(value) { return String(value).padStart(2, '0'); }
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) + ' '
            + pad(date.getHours()) + ':' + pad(date.getMinutes()) + ':' + pad(date.getSeconds());
    }

    function usageKey(entry) {
        if (entry.special) {
            return 'special:' + entry.id;
        }
        if (entry.app_id) {
            return 'app:' + entry.app_id;
        }
        return entry.folder_id ? 'folder:' + entry.folder_id : 'menu:' + entry.id;
    }

    function recordEntryUsage(entry) {
        // OS 设置与官网动态窗口为内置入口且自动打开，不计入应用使用统计
        if (!entry || entry.id === 'webos-settings' || entry.id === 'webos-official-news') {
            return;
        }
        var key = usageKey(entry);
        var statistics = state.workspace.preferences.usage_stats;
        if (!statistics || Array.isArray(statistics)) {
            statistics = {};
        }
        var current = statistics[key] || { count: 0, last_opened_at: '' };
        statistics[key] = {
            count: Math.min(999999, Number(current.count || 0) + 1),
            last_opened_at: formatLocalDateTime(new Date())
        };
        state.workspace.preferences.usage_stats = statistics;
        renderStartMenu();
        saveWorkspace(false).catch(function () {});
    }

    function addDesktopEntry(entry) {
        if (!entry || state.workspace.desktop_items.some(function (item) { return item.id === entry.id; })) {
            toast('该入口已经在桌面上');
            return;
        }
        // 找空行时仅统计可见图标：停用应用不再占位，新图标可以落在其格子
        var occupied = assignedDesktopItems().map(function (item) { return Number(item.y) || 0; });
        var row = 0;
        while (occupied.indexOf(row) >= 0) {
            row += 1;
        }
        state.workspace.desktop_items.push(Object.assign({}, entry, { x: 0, y: row }));
        renderDesktop();
        renderStartMenu();
        saveWorkspace(false).then(function () { toast('已添加到桌面'); });
    }

    function removeDesktopEntry(id) {
        if (id === 'webos-app-center') {
            toast('应用中心为内置入口，不允许删除');
            return;
        }
        var before = state.workspace.desktop_items.length;
        state.workspace.desktop_items = state.workspace.desktop_items.filter(function (item) { return item.id !== id; });
        if (state.workspace.desktop_items.length === before) {
            return;
        }
        renderDesktop();
        renderStartMenu();
        saveWorkspace(false).then(function () { toast('已从桌面移除'); });
    }

    /** 固定入口到任务栏（独立于桌面快捷方式，互不影响）；同应用仅固定一个，避免重复图标 */
    function addTaskbarItem(entry) {
        if (!entry) {
            return;
        }
        var appKey = entryAppKey(entry);
        var alreadyPinned = taskbarItems().some(function (item) {
            return entryAppKey(findEntry(item.id) || item) === appKey;
        });
        if (alreadyPinned) {
            toast('该应用已固定在任务栏');
            return;
        }
        if (!Array.isArray(state.workspace.taskbar_items)) {
            state.workspace.taskbar_items = taskbarItems().slice();
        }
        state.workspace.taskbar_items.push(Object.assign({}, entry));
        // 固定运行中的应用后，运行区窗口按钮需按「已固定过滤」重绘，避免出现两个相同图标
        renderTaskbarWindows();
        saveWorkspace(false).then(function () { toast('已固定到任务栏'); });
    }

    /** 从任务栏解除固定（仅影响任务栏，不动桌面图标） */
    function removeTaskbarItem(id) {
        if (!Array.isArray(state.workspace.taskbar_items)) {
            state.workspace.taskbar_items = taskbarItems().slice();
        }
        var before = state.workspace.taskbar_items.length;
        state.workspace.taskbar_items = state.workspace.taskbar_items.filter(function (item) { return item.id !== id; });
        if (state.workspace.taskbar_items.length === before) {
            return;
        }
        // 解除固定后，运行中的窗口需回到运行区显示
        renderTaskbarWindows();
        saveWorkspace(false).then(function () { toast('已从任务栏移除'); });
    }

    /** 任务栏固定图标拖动排序：跨越相邻图标时实时换位，松开后保存新顺序 */
    function dragTaskbarIcon(button, event) {
        var items = taskbarItems();
        var fromIndex = items.findIndex(function (item) { return item.id === button.dataset.pinnedLaunchId; });
        if (fromIndex < 0 || !Array.isArray(state.workspace.taskbar_items)) {
            return;
        }
        if (!state.workspace.taskbar_items.length) {
            state.workspace.taskbar_items = items.slice();
        }
        var startX = event.clientX;
        var startY = event.clientY;
        var moved = false;

        function move(moveEvent) {
            if (!moved && Math.abs(moveEvent.clientX - startX) + Math.abs(moveEvent.clientY - startY) < 6) {
                return;
            }
            moved = true;
            var target = document.elementFromPoint(moveEvent.clientX, moveEvent.clientY);
            var over = target ? target.closest('[data-pinned-launch-id]') : null;
            if (!over || over === button) {
                return;
            }
            var toIndex = state.workspace.taskbar_items.findIndex(function (item) {
                return item.id === over.dataset.pinnedLaunchId;
            });
            if (toIndex < 0 || toIndex === fromIndex) {
                return;
            }
            var moved2 = state.workspace.taskbar_items.splice(fromIndex, 1)[0];
            state.workspace.taskbar_items.splice(toIndex, 0, moved2);
            fromIndex = toIndex;
            renderPinnedApps();
        }
        function end() {
            document.removeEventListener('pointermove', move);
            document.removeEventListener('pointerup', end);
            if (moved) {
                // 拖动松开后的 click 落点不可靠，吞掉一次避免误启动入口
                taskbarDragJustEnded = true;
                saveWorkspace(false);
            }
        }
        document.addEventListener('pointermove', move);
        document.addEventListener('pointerup', end);
    }

    function findStartItem(id) {
        return (state.startItems || []).find(function (item) { return item.id === id; });
    }

    function closeDesktopContextMenu() {
        var menu = document.getElementById('desktop-context-menu');
        if (menu) {
            menu.remove();
        }
    }

    function desktopIconContext(id) {
        var entry = findEntry(id) || findDesktopItem(id);
        var application = entry ? findApplication(entry.app_id) : null;

        return {
            entry: entry,
            application: application || null
        };
    }

    function desktopContextMenuItems(context) {
        var items = [[context.entry && context.entry.app_id ? '打开应用' : '打开', 'fa-external-link', 'open']];
        // 重命名仅修改桌面显示名称（工作区数据），不影响应用本身的名称与菜单
        items.push(['重命名', 'fa-pencil', 'rename']);
        // 应用中心为内置入口，不允许删除，右键不提供删除项
        if (!context.entry || context.entry.id !== 'webos-app-center') {
            items.push(['删除图标', 'fa-thumb-tack', 'remove']);
        }
        if (context.application && Number(context.application.is_system) !== 1) {
            items.push(['卸载应用', 'fa-times-circle', 'uninstall', 'danger']);
        }
        return items;
    }

    function openDesktopContextMenu(iconId, clientX, clientY) {
        closeDesktopContextMenu();
        closeTaskbarContextMenu();
        closeAppRowMenus();
        var items = desktopContextMenuItems(desktopIconContext(iconId));
        menuMarkupInto('#desktop-context-menu', 'desktop-context-menu', items, iconId);
        positionDesktopMenu(clientX, clientY);
    }

    /** 判断右键目标是否属于桌面空白区域：仅桌面根节点与图标层空白处，窗口、任务栏、面板、弹窗一律排除 */
    function isDesktopSurface(target) {
        if (!target || typeof target.closest !== 'function') {
            return false;
        }
        if (target === root) {
            return true;
        }
        return Boolean(target.closest('#desktop-icons')) && !target.closest('[data-desktop-id]');
    }

    /** 桌面空白处右键菜单：刷新、查看（图标大小子菜单）、设置背景、个性设置、显示桌面 */
    function openDesktopBlankContextMenu(clientX, clientY) {
        closeDesktopContextMenu();
        closeTaskbarContextMenu();
        closeAppRowMenus();
        // 查看 > 图标大小子菜单：当前档位带勾选标记（默认中图标）
        var currentSize = ['large', 'small'].indexOf(state.workspace.preferences.icon_size) >= 0
            ? state.workspace.preferences.icon_size
            : 'medium';
        var sizeChildren = [
            { label: '大图标', action: 'icon-large', checked: currentSize === 'large' },
            { label: '中图标', action: 'icon-medium', checked: currentSize === 'medium' },
            { label: '小图标', action: 'icon-small', checked: currentSize === 'small' }
        ];
        var items = [
            ['刷新', 'fa-refresh', 'refresh', '', null],
            ['查看', 'fa-th-large', '', '', sizeChildren],
            ['设置背景', 'fa-picture-o', 'wallpaper', '', null],
            ['个性设置', 'fa-sliders', 'personalize', '', null],
            ['显示桌面', 'fa-eye', 'show-desktop', '', null]
        ];
        menuMarkupInto('#desktop-context-menu', 'desktop-context-menu', items, '');
        positionDesktopMenu(clientX, clientY);
    }

    function menuMarkupInto(selector, className, items, iconId) {
        var menu = document.querySelector(selector) || document.createElement('div');
        menu.id = selector.replace('#', '');
        menu.className = className;
        menu.setAttribute('role', 'menu');
        menu.innerHTML = items.map(function (item) {
            // item[4] 为子菜单（查看 > 大/中/小图标等）：父项仅作 hover 展开，不挂 action
            var children = Array.isArray(item[4]) && item[4].length
                ? '<div class="desktop-context-submenu" role="menu">' + item[4].map(function (child) {
                    return '<button class="desktop-context-item' + (child.checked ? ' is-checked' : '') + '" type="button" role="menuitem"'
                        + ' data-desktop-action="' + child.action + '" data-desktop-id="' + escapeHtml(iconId) + '">'
                        + '<i class="fa ' + (child.checked ? 'fa-check' : 'fa-circle-o') + '"></i>' + child.label + '</button>';
                }).join('') + '</div>'
                : '';
            return '<div class="desktop-context-group">'
                + '<button class="desktop-context-item ' + (item[3] ? 'is-danger' : '') + (children ? ' has-children' : '') + '" type="button" role="menuitem"'
                + (item[2] ? ' data-desktop-action="' + item[2] + '"' : '')
                + ' data-desktop-id="' + escapeHtml(iconId) + '">'
                + '<i class="fa ' + item[1] + '"></i>' + item[0] + '</button>'
                + children
                + '</div>';
        }).join('');
        if (!menu.parentNode) {
            root.appendChild(menu);
        }
    }

    function positionDesktopMenu(clientX, clientY) {
        var menu = document.querySelector('#desktop-context-menu');
        if (!menu) {
            return;
        }
        var rect = menu.getBoundingClientRect();
        var x = typeof clientX === 'number' ? clientX : (window.innerWidth - rect.width) / 2;
        var y = typeof clientY === 'number' ? clientY : (window.innerHeight - rect.height) / 2;
        menu.style.left = Math.max(8, Math.min(x, window.innerWidth - rect.width - 8)) + 'px';
        menu.style.top = Math.max(8, Math.min(y, window.innerHeight - rect.height - 8)) + 'px';
    }

    function runDesktopContextAction(action, iconId) {
        var context = desktopIconContext(iconId);
        // 窗口选项卡右键：刷新页面 / 关闭当前 / 关闭其它 / 关闭全部（data-desktop-id 传选项卡 id）
        if (action === 'tab-reload' || action === 'tab-close' || action === 'tab-close-others' || action === 'tab-close-all') {
            runWindowTabContextAction(action, iconId);
            return;
        }
        // 查看 > 图标大小：切档后持久化偏好并按新格子尺寸重排桌面
        if (action === 'icon-large' || action === 'icon-medium' || action === 'icon-small') {
            state.workspace.preferences.icon_size = action.slice('icon-'.length);
            saveWorkspace(false);
            renderDesktop();
            return;
        }
        if (action === 'open') {
            openEntry(context.entry || findDesktopItem(iconId));
            return;
        }
        if (action === 'remove') {
            removeDesktopEntry(iconId);
            return;
        }
        if (action === 'rename') {
            renameDesktopIcon(iconId);
            return;
        }
        if (action === 'refresh') {
            refreshWorkspace();
            return;
        }
        if (action === 'icon-large' || action === 'icon-medium' || action === 'icon-small') {
            // 查看 > 图标大小：保存偏好并按新档位重排（格子尺寸随档位变化）
            state.workspace.preferences.icon_size = action.replace('icon-', '');
            saveWorkspace(false);
            renderDesktop();
            return;
        }
        if (action === 'show-desktop') {
            state.windows.forEach(function (_, key) { minimizeWindow(key); });
            closePanels();
            return;
        }
        if (action === 'wallpaper' || action === 'personalize') {
            // 右键“设置背景”直达背景设置 Tab，“个性设置”打开基本设置 Tab
            osSettingsTab = action === 'wallpaper' ? 'wallpaper' : 'general';
            openEntry(webosSettingsEntry());
            renderWebosSettings();
            return;
        }
        if (action === 'uninstall' && context.application) {
            openUninstallDialog(context.application);
        }
    }

    function closeTaskbarContextMenu() {
        var menu = document.getElementById('taskbar-context-menu');
        if (menu) {
            menu.remove();
        }
    }

    /** 窗口选项卡右键菜单：刷新页面 / 关闭当前 / 关闭其它 / 关闭全部（复用桌面右键菜单容器，data-desktop-id 传选项卡 id） */
    function openWindowTabContextMenu(tabId, clientX, clientY) {
        closeDesktopContextMenu();
        closeTaskbarContextMenu();
        closeAppRowMenus();
        menuMarkupInto('#desktop-context-menu', 'desktop-context-menu', [
            ['刷新页面', 'fa-refresh', 'tab-reload'],
            ['关闭当前', 'fa-times', 'tab-close'],
            ['关闭其它', 'fa-columns', 'tab-close-others'],
            ['关闭全部', 'fa-trash', 'tab-close-all', 'danger']
        ], tabId);
        positionDesktopMenu(clientX, clientY);
    }

    /**
     * 刷新选项卡：清空页面容器 token 强制重建（iframe 重设 src / 组件重新拉取），
     * 行为对齐框架后台标题栏刷新按钮（layui-icon-refresh-1，重新加载当前页）。
     */
    function reloadWindowTab(windowState, tab) {
        var host = windowState.element.querySelector('[data-window-page-host]');
        var page = host && host.querySelector('[data-window-page="' + tab.id + '"]');
        if (page) { page.dataset.pageToken = ''; }
        windowState.activeTabId = tab.id;
        renderWindowPage(windowState, tab);
        syncWindowTabsBar(windowState);
    }

    /** 选项卡右键动作：按选项卡 id 反查所在窗口；关闭其它后激活保留的选项卡 */
    function runWindowTabContextAction(action, tabId) {
        var found = null;
        state.windows.forEach(function (windowState) {
            if (found || !windowState.tabs) { return; }
            var tab = windowState.tabs.find(function (item) { return item.id === tabId; });
            if (tab) { found = { windowState: windowState, tab: tab }; }
        });
        if (!found) { return; }
        if (action === 'tab-reload') {
            reloadWindowTab(found.windowState, found.tab);
            return;
        }
        if (action === 'tab-close') {
            closeWindowTab(found.windowState, tabId);
            return;
        }
        var ids = found.windowState.tabs.map(function (item) { return item.id; });
        if (action === 'tab-close-others') {
            ids.forEach(function (id) {
                if (id !== tabId) { closeWindowTab(found.windowState, id); }
            });
            // 逐个关闭可能切换了激活态，确保保留的选项卡处于激活
            if (found.windowState.activeTabId !== tabId) {
                switchWindowTab(found.windowState, tabId);
            }
            return;
        }
        // 关闭全部：逐个关闭，最后一个（激活页）走既有相邻切换/空状态收尾
        ids.forEach(function (id) { closeWindowTab(found.windowState, id); });
    }

    function taskbarWindowMenuItems(key) {
        var target = state.windows.get(key);
        if (!target) {
            return [];
        }
        var items = [];
        if (target.minimized) {
            items.push({ label: '还原', icon: 'fa-window-restore', action: 'restore', key: key });
        } else {
            items.push({ label: target.maximized ? '还原' : '最大化', icon: target.maximized ? 'fa-clone' : 'fa-square-o', action: 'maximize', key: key });
            items.push({ label: '最小化', icon: 'fa-minus', action: 'minimize', key: key });
        }
        items.push({ label: '关闭', icon: 'fa-times', action: 'close', key: key, danger: true });
        var entryId = target.entry ? target.entry.id : '';
        if (entryId) {
            var pinned = taskbarItems().some(function (item) { return item.id === entryId; });
            items.push({ label: pinned ? '解除固定' : '固定到任务栏', icon: 'fa-thumb-tack', action: pinned ? 'unpin' : 'pin', id: entryId });
        }
        return items;
    }

    function taskbarPinnedMenuItems(id) {
        var entry = findEntry(id) || findDesktopItem(id);
        var application = entry ? findApplication(entry.app_id) : null;
        var items = [];
        var windowKey = runningWindowKeyByApp(entry || { id: id });
        if (windowKey) {
            // 运行中：提供窗口操作（复用运行窗口菜单，固定状态项由下方统一给出）
            items = taskbarWindowMenuItems(windowKey).filter(function (item) {
                return item.action !== 'pin' && item.action !== 'unpin';
            });
        }
        items.push({ label: entry && entry.app_id ? '打开应用' : '打开', icon: 'fa-external-link', action: 'open', id: id });
        items.push({ label: '解除固定', icon: 'fa-thumb-tack', action: 'unpin', id: id });
        if (application && Number(application.is_system) !== 1) {
            items.push({ label: '卸载应用', icon: 'fa-times-circle', action: 'uninstall', id: id, danger: true });
        }
        return items;
    }

    function openTaskbarContextMenu(items, clientX, clientY) {
        closeTaskbarContextMenu();
        closeDesktopContextMenu();
        closeAppRowMenus();
        var menu = document.createElement('div');
        menu.id = 'taskbar-context-menu';
        menu.className = 'desktop-context-menu';
        menu.setAttribute('role', 'menu');
        menu.innerHTML = items.map(function (item) {
            var attrs = ' data-taskbar-action="' + escapeHtml(item.action) + '"';
            if (item.key) {
                attrs += ' data-taskbar-key="' + escapeHtml(item.key) + '"';
            }
            if (item.id) {
                attrs += ' data-taskbar-id="' + escapeHtml(item.id) + '"';
            }
            return '<button class="desktop-context-item ' + (item.danger ? 'is-danger' : '') + '" type="button" role="menuitem"' + attrs + '>'
                + '<i class="fa ' + item.icon + '"></i>' + escapeHtml(item.label) + '</button>';
        }).join('');
        root.appendChild(menu);
        var rect = menu.getBoundingClientRect();
        menu.style.left = Math.max(8, Math.min(clientX, window.innerWidth - rect.width - 8)) + 'px';
        menu.style.top = Math.max(8, Math.min(clientY, window.innerHeight - rect.height - 8)) + 'px';
    }

    function runTaskbarContextAction(action, dataset) {
        if (action === 'maximize' || action === 'minimize' || action === 'close' || action === 'restore') {
            var key = dataset.taskbarKey;
            if (!key) {
                return;
            }
            if (action === 'maximize') { maximizeWindow(key); }
            else if (action === 'minimize') { minimizeWindow(key); }
            else if (action === 'restore') { restoreWindow(key); }
            else { closeWindow(key); }
            return;
        }
        var id = dataset.taskbarId;
        if (!id) {
            return;
        }
        if (action === 'open') {
            openEntry(findEntry(id) || findDesktopItem(id));
            return;
        }
        if (action === 'pin') {
            // 固定到任务栏：仅写任务栏固定项，不影响桌面快捷方式
            addTaskbarItem(findEntry(id) || findDesktopItem(id));
            return;
        }
        if (action === 'unpin') {
            // 解除固定：仅移除任务栏固定项，不影响桌面快捷方式
            removeTaskbarItem(id);
            return;
        }
        if (action === 'uninstall') {
            var context = desktopIconContext(id);
            if (context.application) {
                openUninstallDialog(context.application);
            }
        }
    }

    function findDesktopItem(id) {
        return state.workspace.desktop_items.find(function (item) { return item.id === id; });
    }

    /**
     * 重命名桌面图标：仅修改工作区中该桌面项的显示名称（desktop_items[].title），
     * 应用名称、系统菜单、开始菜单等应用本身数据不受影响。
     * 修改后即时重渲染桌面并静默保存工作区。
     */
    function renameDesktopIcon(iconId) {
        var item = findDesktopItem(iconId);
        if (!item) {
            return;
        }
        layuiLayer().prompt({
            title: '重命名「' + escapeHtml(item.title) + '」',
            formType: 0,
            value: item.title,
            maxlength: 60
        }, function (value, index) {
            var name = String(value || '').trim();
            if (!name) {
                layuiLayer().msg('名称不能为空', { icon: 2 });
                return;
            }
            if (name.length > 60) {
                layuiLayer().msg('名称不能超过 60 个字符', { icon: 2 });
                return;
            }
            layuiLayer().close(index);
            if (name === item.title) {
                return;
            }
            item.title = name;
            renderDesktop();
            saveWorkspace(false);
            layuiLayer().msg('已重命名', { icon: 1, time: 1200 });
        });
    }

    function desktopEntryFromStart(startItem) {
        if (!startItem) {
            return null;
        }
        var entry = Object.assign({}, startItem);
        if (startItem.start_kind === 'application' && startItem.start_title) {
            entry.title = startItem.start_title;
        }
        return entry;
    }

    function toggleDesktopEntry(id, startItem) {
        var pinned = state.workspace.desktop_items.some(function (item) { return item.id === id; });
        if (pinned) {
            removeDesktopEntry(id);
        } else {
            addDesktopEntry(desktopEntryFromStart(startItem) || findEntry(id));
        }
    }

    function windowKey(entry) {
        if (entry.id === 'webos-app-center') {
            return 'webos-app-center';
        }
        if (entry.id === 'webos-settings') {
            return 'webos-settings';
        }
        if (entry.id === 'webos-official-news') {
            return 'webos-official-news';
        }
        var identity = entry.app_id ? 'app-' + entry.app_id : 'folder-' + (entry.folder_id || entry.id || entry.path);
        return String(identity).replace(/[^A-Za-z0-9_-]/g, '-');
    }

    function findApplication(appId) {
        return appId ? state.catalog.applications.find(function (application) {
            return application.app_id === appId;
        }) : null;
    }

    function windowIdentity(entry) {
        var application = findApplication(entry.app_id);
        return {
            title: application ? application.name : (entry.folder_title || entry.group_title || entry.title),
            subtitle: entry.title,
            icon: application && typeof application.icon === 'string' && application.icon.indexOf('/') < 0
                ? application.icon : entry.icon
        };
    }

    function windowBrandIconMarkup(entry) {
        var application = findApplication(entry.app_id);
        if (!application || application.is_system) {
            return '<span class="window-brand-icon is-system"><img src="/Images/logo-80x80.png" alt=""></span>';
        }

        return applicationIconMarkup(application, 'window-brand-icon');
    }

    function windowSidebarToggleMarkup(key, collapsed) {
        var label = collapsed ? '展开左侧菜单' : '收起左侧菜单';

        return '<button class="window-control sidebar-toggle" type="button" data-window-action="toggle-sidebar"'
            + ' aria-controls="window-sidebar-' + key + '" aria-expanded="' + (!collapsed) + '"'
            + ' aria-label="' + label + '" title="' + label + '">'
            + '<i class="fa ' + (collapsed ? 'fa-indent' : 'fa-outdent') + '"></i></button>';
    }

    /** 应用前台菜单叶子缓存（appId → 叶子数组）：home 菜单树同一应用跨窗口复用，无菜单不缓存以便后续重试 */
    var homeMenusCache = new Map();

    /** 窗口标题栏前台菜单下拉框：仅在应用声明并安装了前台（home）菜单时由 loadWindowHomeMenu 注入 */
    function windowHomeMenuMarkup(leaves) {
        var items = leaves.map(function (leaf) {
            return '<button type="button" class="window-home-menu-item" data-home-url="' + escapeHtml(leaf.url || leaf.path) + '">'
                + '<span>' + escapeHtml(leaf.name) + '</span></button>';
        }).join('');

        return '<div class="window-home-menu" data-window-home-menu>'
            + '<button class="window-control home-menu-trigger" type="button" data-window-action="toggle-home-menu"'
            + ' aria-haspopup="true" aria-expanded="false" title="前台菜单">'
            + '前台 <i class="fa fa-caret-down" aria-hidden="true"></i></button>'
            + '<nav class="window-home-menu-list" hidden>' + items + '</nav></div>';
    }

    /** 递归收集应用在前台终端（home）的菜单叶子：app_id 匹配且具备访问路径的节点 */
    function collectHomeMenuLeaves(nodes, appId) {
        var leaves = [];
        (Array.isArray(nodes) ? nodes : []).forEach(function (node) {
            if (!node || typeof node !== 'object') { return; }
            var children = Array.isArray(node.children) ? node.children : [];
            if (children.length) {
                leaves = leaves.concat(collectHomeMenuLeaves(children, appId));
                return;
            }
            if (String(node.app_id || '') !== String(appId) || !node.path || !node.name) { return; }
            leaves.push({ name: node.name, path: node.path });
        });

        return leaves;
    }

    /**
     * 加载窗口对应应用的前台菜单并在「收起左侧菜单」左侧注入下拉框。
     * 数据源：系统菜单树接口 terminal_type=home（安装时 home_menus 已写入 admin_menus 表），
     * 前端按窗口应用的 app_id 筛选叶子；应用没有前台菜单时不注入任何元素。
     */
    function loadWindowHomeMenu(windowState) {
        if (!windowState || !windowState.entry) { return; }
        var entry = windowState.entry;
        var appId = entry.app_id;
        var application = appId ? findApplication(appId) : null;
        if (!appId || (application && application.is_system)) { return; }

        var inject = function (leaves) {
            if (!leaves || !leaves.length || !document.contains(windowState.element)) { return; }
            var controls = windowState.element.querySelector('.window-controls');
            if (!controls) { return; }
            var existing = controls.querySelector('.window-home-menu');
            if (existing) { existing.remove(); }
            // 前台菜单固定锚定「收起左侧菜单」按钮左侧：与选项卡条/溢出导航的注入时序无关，始终紧挨
            var anchor = controls.querySelector('.sidebar-toggle');
            if (anchor) {
                anchor.insertAdjacentHTML('beforebegin', windowHomeMenuMarkup(leaves));
                return;
            }
            controls.insertAdjacentHTML('afterbegin', windowHomeMenuMarkup(leaves));
        };

        if (homeMenusCache.has(appId)) {
            inject(homeMenusCache.get(appId));
            return;
        }
        api('/api/admin/menus/tree?terminal_type=home').then(function (payload) {
            var leaves = collectHomeMenuLeaves(Array.isArray(payload) ? payload : [], appId);
            if (!leaves.length) { return; }
            // 前台链接兼容转换：域名绑定应用的原始路径（如 /forum）在主站不可达，需换算为绑定域名；
            // 转换失败或非绑定应用回退原始路径（url 字段由应用侧 home-menu-urls 接口按 menu_path 生成）
            api('/admin/cmspro/webos/api/home-menu-urls?app_ids[]=' + encodeURIComponent(appId))
                .then(function (urls) {
                    var urlMap = {};
                    (urls && Array.isArray(urls[appId]) ? urls[appId] : []).forEach(function (item) {
                        if (item && item.path && item.url) { urlMap[item.path] = item.url; }
                    });
                    leaves.forEach(function (leaf) {
                        if (urlMap[leaf.path]) { leaf.url = urlMap[leaf.path]; }
                    });
                })
                .catch(function () {})
                .then(function () {
                    homeMenusCache.set(appId, leaves);
                    inject(leaves);
                });
        }).catch(function () {
            // 前台菜单获取失败静默：不显示下拉框，不影响窗口本身
        });
    }

    /** 已展开的侧栏菜单分组，key 取菜单自身标识，跨窗口共享不会串台 */
    var windowNavExpanded = new Set();

    function windowNavLeaf(item) {
        return {
            type: 'leaf',
            key: 'menu-' + item.id,
            title: item.name || '未命名菜单',
            icon: safeIcon(item.icon)
        };
    }

    function windowNavBranch(item) {
        return {
            type: 'branch',
            key: 'nav-' + String(item.id || item.code || item.name || ''),
            title: item.name || '未命名分组',
            icon: safeIcon(item.icon || 'fa fa-folder'),
            children: []
        };
    }

    /**
     * 从原始菜单树中提取当前窗口对应的子树，保留分组层级供侧栏展开收起。
     * 应用窗口取首个携带该 app_id 的节点，系统菜单文件夹窗口取 folder_id 对应分组。
     */
    function windowNavTree(entry) {
        var roots = [];
        var appId = entry.app_id || '';
        var folderId = appId ? '' : String(entry.folder_id || '');

        function collect(items, inheritedAppId, inside, level) {
            (items || []).forEach(function (item) {
                var children = Array.isArray(item.children) ? item.children : [];
                var itemAppId = item.app_id || inheritedAppId || '';
                var nodeId = String(item.id || item.code || item.name || '');
                var isRoot = !inside && (appId ? itemAppId === appId : (!itemAppId && nodeId === folderId));

                if (!inside && !isRoot) {
                    collect(children, itemAppId, false, level);
                    return;
                }
                if (!children.length) {
                    if (openablePath(item)) { level.push(windowNavLeaf(item)); }
                    return;
                }
                if (isRoot) {
                    collect(children, itemAppId, true, level);
                    return;
                }
                var branch = windowNavBranch(item);
                collect(children, itemAppId, true, branch.children);
                if (branch.children.length) {
                    level.push(branch);
                } else if (openablePath(item)) {
                    // 子项均为按钮权限（path 为空）不可打开时，分支自身作为叶子保留（与 flattenMenus 兜底一致）
                    level.push(windowNavLeaf(item));
                }
            });
        }

        collect(state.catalog.menus || [], '', false, roots);
        return roots;
    }

    function countNavLeaves(nodes) {
        return (nodes || []).reduce(function (total, node) {
            return total + (node.type === 'leaf' ? 1 : countNavLeaves(node.children));
        }, 0);
    }

    /** 把当前菜单所在的分组链路加入展开集合，打开窗口即可看到当前页 */
    function revealActiveNavBranch(nodes, activeKey) {
        var found = false;

        (nodes || []).forEach(function (node) {
            if (node.type === 'leaf') {
                if (node.key === activeKey) { found = true; }
                return;
            }
            if (revealActiveNavBranch(node.children, activeKey)) {
                windowNavExpanded.add(node.key);
                found = true;
            }
        });

        return found;
    }

    function windowNavNodesMarkup(nodes, activeId, depth) {
        var indent = ' style="padding-left:' + (12 + depth * 14) + 'px"';

        return (nodes || []).map(function (node) {
            if (node.type === 'leaf') {
                return '<button class="window-nav-button' + (node.key === activeId ? ' is-active' : '')
                    + '" type="button" data-window-menu-id="' + escapeHtml(node.key) + '"' + indent + '>'
                    + '<i class="' + safeIcon(node.icon) + '"></i>'
                    + '<span>' + escapeHtml(node.title) + '</span></button>';
            }
            var expanded = windowNavExpanded.has(node.key);

            return '<div class="window-nav-group' + (expanded ? ' is-open' : '') + '">'
                + '<button class="window-nav-button window-nav-toggle" type="button"'
                + ' data-window-nav-branch="' + escapeHtml(node.key) + '"'
                + ' aria-expanded="' + (expanded ? 'true' : 'false') + '"' + indent + '>'
                + '<i class="' + safeIcon(node.icon) + '"></i>'
                + '<span>' + escapeHtml(node.title) + '</span>'
                + '<i class="fa fa-angle-down window-nav-caret"></i></button>'
                + '<div class="window-nav-children">'
                + windowNavNodesMarkup(node.children, activeId, depth + 1) + '</div></div>';
        }).join('');
    }

    /** 侧栏菜单：标题 + 可展开收起的多级菜单树 */
    function windowNavMarkup(entry, tree, reveal) {
        if (reveal) { revealActiveNavBranch(tree, entry.id); }

        return '<span class="window-sidebar-title">' + (entry.app_id ? '应用菜单' : '子菜单') + '</span>'
            + '<div class="window-nav-tree">' + windowNavNodesMarkup(tree, entry.id, 0) + '</div>';
    }

    /** 应用中心窗口侧栏的固定 Tab 列表（与 renderAppCenter 的标题映射 APP_CENTER_TABS 相互独立） */
    var APP_CENTER_SIDEBAR_TABS = [
        { id: 'market', title: '应用市场', icon: 'fa fa-shopping-bag' },
        { id: 'installed', title: '已安装', icon: 'fa fa-cube' },
        { id: 'uninstalled', title: '未安装', icon: 'fa fa-download' },
        { id: 'updates', title: '应用更新', icon: 'fa fa-refresh' },
        { id: 'records', title: '安装记录', icon: 'fa fa-file-text-o' },
        { id: 'entries', title: '入口管理', icon: 'fa fa-th' }
    ];

    /** 通知中心窗口左侧固定菜单：待办 / 通知 */
    var NOTIFICATION_CENTER_TABS = [
        { id: 'todos', title: '待办', icon: 'fa fa-list-ul' },
        { id: 'notifications', title: '通知', icon: 'fa fa-bell-o' }
    ];

    /** 通知中心窗口状态：当前 Tab 与通知分页 */
    var notificationCenterState = { tab: 'todos', page: 1, todoFilter: 'all', noticeFilter: 'all' };

    /** 「应用更新」右上角的可用更新数量角标：未检查（null）或无更新时不渲染，超过 99 折叠为 99+ */
    function updateBadgeMarkup() {
        var count = Number(state.updateCount) || 0;
        if (count <= 0) {
            return '';
        }

        return '<em class="window-nav-badge">' + (count > 99 ? '99+' : count) + '</em>';
    }

    function appCenterSidebarMarkup() {
        return '<span class="window-sidebar-title">应用菜单</span>' + APP_CENTER_SIDEBAR_TABS.map(function (item) {
            return '<button class="window-nav-button ' + (item.id === state.appCenterTab ? 'is-active' : '')
                + '" type="button" data-special-tab="' + item.id + '">'
                + '<i class="' + safeIcon(item.icon) + '"></i><span>' + escapeHtml(item.title) + '</span>'
                + (item.id === 'updates' ? updateBadgeMarkup() : '') + '</button>';
        }).join('');
    }

    /** 通知中心窗口侧栏：待办 / 通知两个固定 Tab，待办带数量徽标 */
    function notificationCenterSidebarMarkup() {
        var todoTotal = (notificationPanelData.todos || []).reduce(function (sum, todo) {
            return sum + Number(todo.count || 0);
        }, 0);
        return '<span class="window-sidebar-title">通知菜单</span>' + NOTIFICATION_CENTER_TABS.map(function (item) {
            return '<button class="window-nav-button ' + (item.id === notificationCenterState.tab ? 'is-active' : '')
                + '" type="button" data-special-tab="' + item.id + '">'
                + '<i class="' + safeIcon(item.icon) + '"></i><span>' + escapeHtml(item.title) + '</span>'
                + (item.id === 'todos' && todoTotal > 0 ? '<em class="window-nav-badge">' + (todoTotal > 99 ? '99+' : todoTotal) + '</em>' : '')
                + '</button>';
        }).join('');
    }

    /** 应用中心窗口侧栏为固定 Tab，其余窗口按菜单树层级渲染 */
    function windowSidebarMarkup(entry, tree, reveal) {
        if (entry.special === 'market' || entry.id === 'webos-app-center') {
            return appCenterSidebarMarkup();
        }
        if (entry.special === 'notifications' || entry.id === 'webos-notification-page') {
            return notificationCenterSidebarMarkup();
        }

        return windowNavMarkup(entry, tree, reveal);
    }

    /** 只替换侧栏内容，避免重建窗口导致 iframe 重新加载 */
    function rerenderWindowNav(windowState, reveal) {
        if (!windowState) {
            return;
        }
        var sidebar = windowState.element.querySelector('.window-sidebar');
        if (sidebar) {
            sidebar.innerHTML = windowSidebarMarkup(windowState.entry, windowNavTree(windowState.entry), reveal);
        }
    }

    /** 重绘应用中心侧栏，使「应用更新」角标与 state.updateCount 保持一致；窗口未打开时无需处理 */
    function syncUpdateBadge() {
        rerenderWindowNav(state.windows.get(windowKey(applicationCenterEntry())));
        // 桌面「应用中心」图标右上角的可更新数量角标随检查结果同步
        renderDesktop();
    }

    /** 静默检查可用更新数量：服务端 lazy 模式 24h 内直接返回缓存，失败时不提示也不显示角标 */
    function loadUpdateCount() {
        return api('/api/admin/apps/check-updates?lazy=1', { method: 'POST' }).then(function (payload) {
            state.updateApps = extractCollection(payload);
            state.updateCount = state.updateApps.length;
            state.updateChecked = true;
            syncUpdateBadge();
        }).catch(function () {
            // 检查失败保持原状，用户仍可进入「应用更新」Tab 手动重试
        });
    }

    /** 升级成功后本地移除该项并刷新角标：服务端结果缓存 24h，重新请求拿到的仍是旧数量 */
    function dropUpdatedApp(appId) {
        state.updateApps = state.updateApps.filter(function (app) {
            return app.app_id !== appId;
        });
        state.updateCount = state.updateApps.length;
        syncUpdateBadge();
    }

    /** 进入桌面时检查 WebOS 自身是否有更高版本：有则弹提示，确认后打开应用中心并自动触发升级流程 */
    function checkWebosSelfUpdate() {
        // 升级提醒仅对超级管理员生效（非超管桌面无应用中心，升级操作属于超管职责）
        if (!isSuperAdmin) { return; }
        var ensure = state.updateChecked
            ? Promise.resolve()
            : loadUpdateCount();
        ensure.then(function () {
            var self = state.updateApps.find(function (app) { return app.app_id === 'cmspro.webos'; });
            if (!self) {
                return;
            }
            var currentVersion = self.current_version || '-';
            var latestVersion = self.latest_version || '';
            if (!latestVersion || latestVersion === currentVersion) {
                return;
            }
            var layer = layuiLayer();
            layer.confirm(
                '发现 WebOS 管理桌面新版本 <b>v' + escapeHtml(latestVersion) + '</b>（当前 v' + escapeHtml(currentVersion) + '），是否前往升级？',
                { icon: 3, title: '应用更新', btn: ['立即升级', '稍后再说'] },
                function (index) {
                    layer.close(index);
                    openEntry(applicationCenterEntry());
                    // 打开应用中心后自动进入 WebOS 自身的升级流程（启用拦截 → 版本选择弹窗）
                    upgradeApp('cmspro.webos');
                }
            );
        }).catch(function () {
            // 检查失败静默：用户仍可从应用中心手动检查更新
        });
    }

    /** 手风琴互斥：收起与当前分组同级的其他分组 */
    function collapseNavSiblings(container, activeKey) {
        if (!container) {
            return;
        }
        Array.prototype.forEach.call(container.children, function (child) {
            var toggle = child.firstElementChild;
            if (!toggle || !toggle.hasAttribute('data-window-nav-branch')
                || toggle.dataset.windowNavBranch === activeKey) {
                return;
            }
            windowNavExpanded.delete(toggle.dataset.windowNavBranch);
            child.classList.remove('is-open');
            toggle.setAttribute('aria-expanded', 'false');
        });
    }

    /** 展开或收起侧栏分组，同级只保留一个展开，与后台菜单行为一致 */
    function toggleWindowNavBranch(button) {
        var group = button.parentNode;
        var branchKey = button.dataset.windowNavBranch;
        var expanded = !windowNavExpanded.has(branchKey);

        if (expanded) {
            collapseNavSiblings(group ? group.parentNode : null, branchKey);
            windowNavExpanded.add(branchKey);
        } else {
            windowNavExpanded.delete(branchKey);
        }
        if (group) {
            group.classList.toggle('is-open', expanded);
        }
        button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }

    function windowMarkup(entry, key) {
        var isMarket = entry.special === 'market' || entry.id === 'webos-app-center';
        var isSettings = entry.special === 'settings' || entry.id === 'webos-settings';
        var isNotificationCenter = entry.special === 'notifications' || entry.id === 'webos-notification-page';
        // 官网动态窗口：无左侧菜单与收起按钮，内容区由 renderOfficialNews 渲染
        var isOfficialNews = entry.special === 'official-news' || entry.id === 'webos-official-news';
        var navTree = isMarket || isSettings || isNotificationCenter || isOfficialNews ? [] : windowNavTree(entry);
        var sidebar = isSettings || isOfficialNews ? '' : windowSidebarMarkup(entry, navTree, true);
        var content = isMarket
            ? '<div class="app-center-shell" data-app-center></div>'
            : (isSettings ? '<div class="webos-settings-shell" data-webos-settings></div>'
                : (isNotificationCenter ? '<div class="webos-notifications-shell" data-webos-notifications></div>'
                    : (isOfficialNews ? '<div class="official-news-shell" data-official-news></div>'
                        : '<div class="window-page-host" data-window-page-host></div>')));
        var identity = isMarket ? { title: '应用中心', subtitle: 'WebOS' }
            : (isSettings ? { title: 'OS 设置', subtitle: 'WebOS 系统偏好' }
                : (isNotificationCenter ? { title: '通知中心', subtitle: 'WebOS' }
                    : (isOfficialNews ? { title: '官网动态', subtitle: 'CmsPro 官网' } : windowIdentity(entry))));
        var brandIcon = windowBrandIconMarkup(entry);
        var sidebarCollapsed = !isSettings && !isMarket && !isNotificationCenter && !isOfficialNews
            && countNavLeaves(navTree) <= 1;
        var sidebarToggle = isSettings || isOfficialNews ? '' : windowSidebarToggleMarkup(key, sidebarCollapsed);
        var windowBody = isSettings || isOfficialNews
            ? '<div class="window-body"><section class="window-content">' + content + '</section></div>'
            : '<div class="window-body"><aside class="window-sidebar" id="window-sidebar-' + key + '">'
                + sidebar + '</aside><section class="window-content">' + content + '</section></div>';

        return '<article class="app-window' + (sidebarCollapsed ? ' is-sidebar-collapsed' : '')
            + '" data-window-key="' + key + '">'
            + '<header class="window-titlebar" data-window-drag>'
            + '<div class="window-brand">' + brandIcon + '<strong>'
            + escapeHtml(identity.title) + '<small>' + escapeHtml(identity.subtitle) + '</small></strong></div>'
            // 选项卡模式标题栏刷新按钮：置于品牌区右侧（标题栏直属，不随选项卡条/控制区布局变化），
            // 点击刷新当前激活选项卡内容（与选项卡右键「刷新页面」同链路）
            + (windowTabsEnabled()
                ? '<button class="window-control window-refresh" type="button" data-window-action="refresh" aria-label="刷新当前页面" title="刷新当前页面"><i class="fa fa-refresh"></i></button>'
                : '')
            + '<div class="window-controls">'
            + sidebarToggle
            + '<button class="window-control" type="button" data-window-action="minimize" aria-label="最小化"><i class="fa fa-minus"></i></button>'
            + '<button class="window-control" type="button" data-window-action="maximize" aria-label="最大化"><i class="fa fa-square-o"></i></button>'
            + '<button class="window-control close" type="button" data-window-action="close" aria-label="关闭"><i class="fa fa-times"></i></button>'
            + '</div></header>'
            + windowBody
            + ['n', 's', 'e', 'w', 'ne', 'nw', 'se', 'sw'].map(function (dir) {
                return '<span class="window-resize-handle" data-window-resize="' + dir + '"></span>';
            }).join('') + '</article>';
    }

    function settingsChoice(attribute, value, icon, label, active) {
        return '<button class="settings-choice ' + (active ? 'is-active' : '') + '" type="button" data-'
            + attribute + '="' + value + '" aria-pressed="' + (active ? 'true' : 'false') + '">'
            + '<i class="fa ' + icon + '"></i><span>' + label + '</span></button>';
    }

    function settingsRangeMarkup(name, title, value) {
        return '<label class="settings-range"><span>' + title + '<b>' + value + '%</b></span>'
            + '<input type="range" min="40" max="100" step="1" value="' + value + '"'
            + ' data-set-window-size="' + name + '" aria-label="' + title + '"></label>';
    }

    /** 当前 OS 设置窗口激活的 Tab：general=基本设置，wallpaper=背景设置，system=系统设置 */
    var osSettingsTab = 'general';

    /** 自定义壁纸库最近一次读取是否失败，用于展示重试入口 */
    var wallpaperLibraryError = false;

    function osSettingsTabBarMarkup() {
        var tabs = [
            ['general', 'fa-sliders', '基本设置'],
            ['wallpaper', 'fa-picture-o', '背景设置'],
            ['system', 'fa-cogs', '系统设置']
        ];
        return '<nav class="settings-tabs" role="tablist">' + tabs.map(function (tab) {
            return '<button class="settings-tab ' + (osSettingsTab === tab[0] ? 'is-active' : '') + '" type="button" role="tab"'
                + ' aria-selected="' + (osSettingsTab === tab[0] ? 'true' : 'false') + '" data-settings-tab="' + tab[0] + '">'
                + '<i class="fa ' + tab[1] + '"></i>' + tab[2] + '</button>';
        }).join('') + '</nav>';
    }

    /** 背景设置：壁纸风格选择、自定义壁纸上传与当前壁纸预览 */
    function wallpaperSectionMarkup(preferences) {
        return '<section class="settings-card"><div class="settings-card-title"><i class="fa fa-picture-o"></i><div>'
            + '<strong>桌面外观</strong><small>切换壁纸显示风格</small></div></div><div class="settings-choice-grid is-two">'
            + settingsChoice('set-webos-wallpaper', 'webos-default', 'fa-sun-o', '默认明亮', preferences.wallpaper === 'webos-default')
            + settingsChoice('set-webos-wallpaper', 'deep-blue', 'fa-moon-o', '深蓝沉浸', preferences.wallpaper === 'deep-blue')
            + '</div></section>'
            + '<section class="settings-card"><div class="settings-card-title"><i class="fa fa-upload"></i><div>'
            + '<strong>自定义壁纸</strong><small>支持 JPG、PNG、GIF、WEBP，不超过 5MB</small></div></div>'
            + '<div class="settings-choice-grid is-two">'
            + '<button class="settings-choice" type="button" data-upload-wallpaper><i class="fa fa-image"></i><span>上传壁纸</span></button>'
            + '<button class="settings-choice" type="button" data-reset-wallpaper><i class="fa fa-undo"></i><span>恢复默认</span></button>'
            + '</div><input type="file" accept="image/jpeg,image/png,image/gif,image/webp" hidden data-wallpaper-input aria-label="选择壁纸文件"></section>'
            + '<section class="settings-card is-wide"><div class="settings-card-title"><i class="fa fa-desktop"></i><div>'
            + '<strong>当前背景</strong><small>' + (preferences.wallpaper_url ? '正在使用自定义壁纸' : '桌面壁纸图片由系统统一提供') + '</small></div></div>'
            + '<div class="wallpaper-current">'
            + '<div class="wallpaper-preview" aria-hidden="true"></div>'
            + '<div class="wallpaper-library" data-wallpaper-library>' + wallpaperLibraryMarkup() + '</div>'
            + '</div></section>';
    }

    /** 自定义壁纸库：缩略图列表，支持点击切换与二次确认删除 */
    function wallpaperLibraryMarkup() {
        if (wallpaperLibraryError) {
            return '<div class="wallpaper-library-hint"><i class="fa fa-exclamation-circle"></i>壁纸列表读取失败'
                + '<button class="text-button" type="button" data-wallpaper-reload>重新读取</button></div>';
        }
        if (state.wallpapers === null) {
            return '<div class="wallpaper-library-hint"><i class="fa fa-circle-o-notch fa-spin"></i>正在读取壁纸列表</div>';
        }
        if (!state.wallpapers.length) {
            return '<div class="wallpaper-library-hint"><i class="fa fa-picture-o"></i>还没有自定义壁纸，上传后会显示在这里</div>';
        }

        return '<div class="wallpaper-library-head"><span>我的壁纸（' + state.wallpapers.length + '）</span>'
            + '<small>点击缩略图切换背景</small></div>'
            + '<div class="wallpaper-library-grid">'
            + state.wallpapers.map(wallpaperItemMarkup).join('')
            + '</div>';
    }

    /** 单个壁纸卡片：缩略图、大小与上传时间、删除操作（含内联二次确认） */
    function wallpaperItemMarkup(item) {
        var url = String(item.url || '');
        var attribute = escapeHtml(url);
        // 壁纸库存的是相对站点根目录的路径，缩略图需补前导斜杠避免被当前页面路径拼接
        var source = escapeHtml('/' + url.replace(/^\/+/, ''));
        var active = state.workspace.preferences.wallpaper_url === url;

        return '<div class="wallpaper-library-item' + (active ? ' is-active' : '') + '" data-wallpaper-item="' + attribute + '">'
            + '<button class="wallpaper-thumb" type="button" data-wallpaper-use="' + attribute + '"'
            + ' style="background-image:url(&quot;' + source + '&quot;)" aria-pressed="' + (active ? 'true' : 'false') + '"'
            + ' aria-label="使用该壁纸"><i class="fa fa-check"></i></button>'
            + '<div class="wallpaper-thumb-meta"><span>' + escapeHtml(formatFileSize(item.size)) + '</span>'
            + '<small>' + escapeHtml(item.uploaded_at || '') + '</small></div>'
            + '<div class="wallpaper-thumb-actions">'
            + '<button class="wallpaper-action" type="button" data-wallpaper-delete="' + attribute + '"'
            + ' title="删除壁纸" aria-label="删除该壁纸"><i class="fa fa-trash-o"></i></button>'
            + '<span class="wallpaper-confirm">'
            + '<button class="wallpaper-confirm-button danger" type="button" data-wallpaper-delete-confirm="' + attribute + '">删除</button>'
            + '<button class="wallpaper-confirm-button" type="button" data-wallpaper-delete-cancel="' + attribute + '">取消</button>'
            + '</span></div></div>';
    }

    /** 把已加载的壁纸库写入背景设置面板 */
    function renderWallpaperLibrary() {
        var container = document.querySelector('[data-wallpaper-library]');
        if (container) {
            container.innerHTML = wallpaperLibraryMarkup();
        }
    }

    /** 拉取当前管理员的自定义壁纸列表 */
    function loadWallpapers() {
        return api(root.dataset.wallpapersUrl).then(function (list) {
            state.wallpapers = Array.isArray(list) ? list : [];
            wallpaperLibraryError = false;
        }).catch(function (error) {
            state.wallpapers = [];
            wallpaperLibraryError = true;
            toast(error.message || '壁纸列表读取失败', 'error');
        }).then(function () {
            renderWallpaperLibrary();
        });
    }

    /** 删除自定义壁纸；若删除的正是当前背景则回退系统默认壁纸 */
    function deleteWallpaper(url) {
        var deleteUrl = root.dataset.workspaceUrl.replace(/\/workspace$/, '/wallpaper');
        api(deleteUrl, { method: 'DELETE', body: { url: url } }).then(function () {
            state.wallpapers = (state.wallpapers || []).filter(function (item) {
                return item.url !== url;
            });
            wallpaperLibraryError = false;
            renderWallpaperLibrary();
            toast('壁纸已删除');
            if (state.workspace.preferences.wallpaper_url === url) {
                setWebosPreference('wallpaper_url', '', '已恢复默认壁纸');
            }
        }).catch(function (error) {
            toast(error.message || '壁纸删除失败', 'error');
        });
    }

    /** 系统设置：覆盖传统后台首页、重置工作区等高级选项 */
    function systemSectionMarkup(preferences) {
        return '<section class="settings-card"><div class="settings-card-title"><i class="fa fa-sign-in"></i><div>'
            + '<strong>覆盖传统后台</strong><small>开启后访问后台首页将直接进入 WebOS 桌面；在地址后加 ?skip_webos=1 可临时打开传统首页</small></div></div>'
            + '<button class="settings-toggle" type="button" data-toggle-webos-setting="override_admin_home" aria-pressed="'
            + (preferences.override_admin_home ? 'true' : 'false') + '"><span><strong>后台首页直达 WebOS</strong>'
            + '<small>仅影响后台首页，其它后台页面不受影响</small></span><i></i></button></section>'
            + '<section class="settings-card"><div class="settings-card-title"><i class="fa fa-clone"></i><div>'
            + '<strong>应用窗口多选项卡</strong><small>开启后窗口内点击菜单以选项卡方式打开，可在标题栏切换与关闭；切换选项卡不重新加载页面</small></div></div>'
            + '<button class="settings-toggle" type="button" data-toggle-webos-setting="window_tabs" aria-pressed="'
            + (preferences.window_tabs ? 'true' : 'false') + '"><span><strong>多选项卡模式</strong>'
            + '<small>仅对开启后新打开的应用窗口生效，已打开窗口保持原模式</small></span><i></i></button></section>'
            + '<section class="settings-card"><div class="settings-card-title"><i class="fa fa-hand-pointer-o"></i><div>'
            + '<strong>点击菜单进入</strong><small>控制点击任务栏「菜单」按钮的行为</small></div></div>'
            + '<button class="settings-toggle" type="button" data-toggle-webos-setting="menu_open_launcher" aria-pressed="'
            + (preferences.menu_open_launcher ? 'true' : 'false') + '"><span><strong>直接进入全部功能</strong>'
            + '<small>开启后点击「菜单」直接打开「全部功能」启动台；关闭时打开当前系统菜单面板（默认）</small></span><i></i></button></section>'
            + '<section class="settings-card"><div class="settings-card-title"><i class="fa fa-undo"></i><div>'
            + '<strong>重置工作区</strong><small>清空桌面与任务栏图标，全部设置恢复默认；自定义壁纸文件保留</small></div></div>'
            + '<div class="settings-reset-row"><button class="webos-button danger" type="button" data-reset-workspace>恢复默认布局</button>'
            + '<span class="workspace-reset-confirm" hidden>将清空桌面/任务栏图标与全部偏好，确定重置？'
            + '<button class="wallpaper-confirm-button danger" type="button" data-reset-workspace-confirm>确认重置</button>'
            + '<button class="wallpaper-confirm-button" type="button" data-reset-workspace-cancel>取消</button>'
            + '</span></div></section>';
    }

    /** 重置工作区：恢复默认布局后同步本地状态并重渲染桌面/任务栏 */
    function resetWorkspace() {
        var resetUrl = root.dataset.workspaceUrl.replace(/\/workspace$/, '/workspace/reset');
        api(resetUrl, { method: 'POST' }).then(function (workspace) {
            state.workspace = workspace;
            state.desktopSelection.clear();
            applyWorkspacePreferences();
            renderDesktop();
            renderTaskbarWindows();
            renderWebosSettings();
            toast('工作区已恢复默认');
        }).catch(function (error) {
            toast(error.message || '重置工作区失败', 'error');
        });
    }

    function renderWebosSettings() {
        var shell = document.querySelector('[data-webos-settings]');
        if (!shell) {
            return;
        }
        var preferences = state.workspace.preferences;
        var positions = [
            ['top', 'fa-arrow-up', '顶部'], ['bottom', 'fa-arrow-down', '底部'],
            ['left', 'fa-arrow-left', '左侧'], ['right', 'fa-arrow-right', '右侧']
        ];
        var content;
        if (osSettingsTab === 'wallpaper') {
            content = '<div class="settings-grid">' + wallpaperSectionMarkup(preferences) + '</div>';
        } else if (osSettingsTab === 'system') {
            content = '<div class="settings-grid">' + systemSectionMarkup(preferences) + '</div>';
        } else {
            content = '<div class="settings-grid">'
            + '<section class="settings-card"><div class="settings-card-title"><i class="fa fa-window-maximize"></i><div>'
            + '<strong>任务栏位置</strong><small>选择状态栏停靠方向</small></div></div><div class="settings-choice-grid">'
            + positions.map(function (item) {
                return settingsChoice('set-taskbar-position', item[0], item[1], item[2], preferences.taskbar_position === item[0]);
            }).join('') + '</div></section>'
            + '<section class="settings-card"><div class="settings-card-title"><i class="fa fa-clock-o"></i><div>'
            + '<strong>时间显示</strong><small>设置时钟格式与精度</small></div></div><div class="settings-choice-grid is-two">'
            + settingsChoice('set-clock-format', '24h', 'fa-clock-o', '24 小时', preferences.clock_format === '24h')
            + settingsChoice('set-clock-format', '12h', 'fa-clock-o', '12 小时', preferences.clock_format === '12h')
            + '</div><button class="settings-toggle" type="button" data-toggle-webos-setting="show_seconds" aria-pressed="'
            + (preferences.show_seconds ? 'true' : 'false') + '"><span><strong>显示秒数</strong><small>在任务栏时钟中显示秒</small></span><i></i></button></section>'
            + '<section class="settings-card"><div class="settings-card-title"><i class="fa fa-window-restore"></i><div>'
            + '<strong>窗口默认尺寸</strong><small>仅作用于应用窗口，按可用桌面区域的百分比显示</small></div></div>'
            + settingsRangeMarkup('window_width', '默认宽度', clampWindowRatio(preferences.window_width, 78))
            + settingsRangeMarkup('window_height', '默认高度', clampWindowRatio(preferences.window_height, 80))
            + '</section>'
            + '<section class="settings-card"><div class="settings-card-title"><i class="fa fa-magic"></i><div>'
            + '<strong>交互体验</strong><small>控制窗口与面板的过渡效果</small></div></div>'
            + '<button class="settings-toggle" type="button" data-toggle-webos-setting="motion" aria-pressed="'
            + (preferences.motion ? 'true' : 'false') + '"><span><strong>界面动效</strong><small>开启柔和的窗口和菜单动画</small></span><i></i></button></section>'
            + '</div>';
        }
        shell.innerHTML = '<header class="settings-hero"><span><i class="fa fa-sliders"></i></span><div>'
            + '<h2>WebOS 个性化设置</h2><p>调整任务栏、桌面外观与时间显示，设置会自动保存到当前管理员工作区。</p></div></header>'
            + osSettingsTabBarMarkup()
            + content;

        // 背景设置首次展开时按需拉取壁纸库，已加载则直接复用缓存
        if (osSettingsTab === 'wallpaper' && state.wallpapers === null) {
            loadWallpapers();
        }
    }

    var MIN_WINDOW_WIDTH = 420;
    var MIN_WINDOW_HEIGHT = 320;

    /** WebOS 内置窗口标识，与 windowKey 的固定返回值一致；不参与“窗口默认尺寸”偏好 */
    // 内置窗口不读取管理员偏好尺寸：应用中心自适应、OS 设置固定宽度、官网动态固定 600×500
    var BUILTIN_WINDOW_KEYS = ['webos-app-center', 'webos-settings', 'webos-official-news'];

    function clampWindowRatio(value, fallback) {
        var ratio = Number(value);
        if (!isFinite(ratio)) {
            return fallback;
        }

        return Math.min(100, Math.max(40, Math.round(ratio)));
    }

    /**
     * 计算新建窗口尺寸：应用窗口按管理员偏好的百分比显示，
     * WebOS 内置窗口（应用中心、OS 设置）不受偏好影响，固定使用出厂默认百分比。
     */
    function defaultWindowSize(key) {
        var preferences = BUILTIN_WINDOW_KEYS.indexOf(key) >= 0 ? {} : (state.workspace.preferences || {});
        var rect = elements.windowLayer.getBoundingClientRect();
        var widthRatio = clampWindowRatio(preferences.window_width, 78);
        var heightRatio = clampWindowRatio(preferences.window_height, 80);

        // OS 设置窗口宽度固定 1000px（小屏收窄避免溢出），高度仍按默认比例
        if (key === 'webos-settings') {
            return {
                width: Math.max(MIN_WINDOW_WIDTH, Math.min(1000, Math.round(rect.width) - 40)),
                height: Math.max(MIN_WINDOW_HEIGHT, Math.round(rect.height * heightRatio / 100))
            };
        }

        // 官网动态窗口固定 600×500（仅超管桌面自动打开，停靠桌面最右侧）
        if (key === 'webos-official-news') {
            return {
                width: Math.min(600, Math.max(MIN_WINDOW_WIDTH, Math.round(rect.width) - 48)),
                height: Math.min(500, Math.max(MIN_WINDOW_HEIGHT, Math.round(rect.height) - 48))
            };
        }

        return {
            width: Math.max(MIN_WINDOW_WIDTH, Math.round(rect.width * widthRatio / 100)),
            height: Math.max(MIN_WINDOW_HEIGHT, Math.round(rect.height * heightRatio / 100))
        };
    }

    /** 打开应用或文件夹窗口时的默认入口：跳过不占用窗口内容区的 _blank 与 _layer */
    function defaultEntryOf(entries) {
        return entries.find(function (entry) {
            return entry.open_type !== '_blank' && entry.open_type !== '_layer';
        }) || entries[0];
    }

    /**
     * 通知/待办链接优先在 WebOS 内打开对应应用窗口（按站内路径匹配菜单入口），
     * 无匹配入口时回退浏览器新标签页。返回 true 表示已在窗口内打开。
     */
    function openLinkInWebos(link) {
        var raw = String(link || '');
        var path = raw.split('?')[0];
        if (!path || path === '#') {
            return false;
        }
        var entry = state.flatMenus.find(function (item) {
            return item.path === path || String(item.path || '').split('?')[0] === path;
        }) || null;
        if (!entry || !openablePath(entry)) {
            window.open(raw, '_blank', 'noopener');
            return false;
        }
        openEntry(entry);
        return true;
    }

    /** _layer（弹窗网页）：用 layui 弹层承载，参数与后台菜单一致；layui 未就绪时降级为新标签页 */
    function openLayerWindow(entry) {
        var layer = window.layui && window.layui.layer;
        if (!layer) {
            window.open(entry.path, '_blank', 'noopener');
            return;
        }
        layer.open({
            type: 2,
            title: entry.title,
            content: entry.path,
            area: ['80%', '80%'],
            maxmin: true
        });
    }

    /**
     * 按后台声明的 open_type 处理不占用窗口内容区的两种打开方式（_blank / _layer）。
     * 返回 true 表示已处理完毕，调用方不应再切换窗口内容或更新侧栏高亮。
     */
    function openMenuByType(entry) {
        var openType = entry.open_type || '_iframe';
        if (openType !== '_blank' && openType !== '_layer') {
            return false;
        }
        if (!openablePath(entry)) {
            toast('该菜单路径不被支持，仅可打开站内路径或 http(s) 外链', 'error');
            return true;
        }
        if (openType === '_blank') {
            window.open(entry.path, '_blank', 'noopener');
        } else {
            openLayerWindow(entry);
        }
        return true;
    }

    /**
     * 重建并执行片段中的单个 script 节点，返回执行完成的 Promise。
     * innerHTML 注入的 script 不会执行，必须换成新建节点浏览器才会求值；
     * 动态创建的 script 默认 async，外链脚本需等 load / error 后再放行下一个，
     * 以复刻后台 jQuery .html() 严格按文档顺序执行的语义。
     */
    function runScriptNode(original) {
        return new Promise(function (resolve) {
            // 串行执行期间用户可能已切走菜单，容器被重建后原节点脱离 DOM，此时跳过
            if (!original.parentNode) {
                resolve();
                return;
            }
            var script = document.createElement('script');
            Array.prototype.forEach.call(original.attributes, function (attribute) {
                script.setAttribute(attribute.name, attribute.value);
            });
            script.textContent = original.textContent;
            var isExternal = Boolean(script.src);
            if (isExternal) {
                script.addEventListener('load', resolve);
                script.addEventListener('error', resolve);
            }
            original.parentNode.replaceChild(script, original);
            if (!isExternal) {
                resolve();
            }
        });
    }

    /** 按文档顺序串行执行片段内全部脚本，Promise 完成后调用方再渲染 layui 组件 */
    function runFragmentScripts(host) {
        var nodes = Array.prototype.slice.call(host.querySelectorAll('script'));
        return nodes.reduce(function (chain, original) {
            return chain.then(function () {
                return runScriptNode(original);
            });
        }, Promise.resolve());
    }

    /** 片段注入后重新渲染 layui 组件，与后台路由模式刷新时的 element.init() 行为对齐 */
    function initLayuiComponents() {
        var layui = window.layui;
        if (!layui) {
            return;
        }
        if (layui.element && typeof layui.element.init === 'function') {
            layui.element.init();
        }
        if (layui.form && typeof layui.form.render === 'function') {
            layui.form.render();
        }
    }

    /** 拉取 _component 页面片段并注入容器，token 用于丢弃用户已切走后的过期响应 */
    function loadComponentPage(host, path, token) {
        fetchText(path).then(function (html) {
            if (host.dataset.pageToken !== token) {
                return;
            }
            host.innerHTML = html;
            runFragmentScripts(host).then(initLayuiComponents);
        }).catch(function (error) {
            if (host.dataset.pageToken !== token) {
                return;
            }
            host.innerHTML = emptyState('fa-exclamation-triangle', error.message);
        });
    }

    /** 窗口选项卡序号：选项卡为窗口内存态，不持久化到工作区 */
    var windowTabSeq = 0;

    /** 由入口创建一个窗口选项卡记录（页面类型与路径决定容器内容，复用渲染逻辑） */
    function makeWindowTab(entry) {
        windowTabSeq += 1;
        return {
            id: 'tab-' + windowTabSeq,
            title: entry.title,
            path: entry.path,
            openType: entry.open_type === '_component' ? '_component' : '_iframe'
        };
    }

    /** 多选项卡开关：OS 设置「应用窗口多选项卡」，仅对开启后新打开的窗口生效 */
    function windowTabsEnabled() {
        return state.workspace.preferences.window_tabs === true;
    }

    /** 当前激活的选项卡：无激活记录时回退最后一个 */
    function windowActiveTab(windowState) {
        if (!windowState.tabs || !windowState.tabs.length) {
            return null;
        }
        return windowState.tabs.find(function (tab) { return tab.id === windowState.activeTabId; })
            || windowState.tabs[windowState.tabs.length - 1];
    }

    /** 选项卡条溢出导航：按滚动位置显隐左右切换按钮（hidden 切换，按钮常驻 DOM） */
    function updateWindowTabsNav(controls) {
        if (!controls) {
            return;
        }
        var bar = controls.querySelector('.window-tabs');
        if (!bar) {
            return;
        }
        var maxScroll = bar.scrollWidth - bar.clientWidth;
        var navPrev = controls.querySelector('[data-window-tabs-scroll="prev"]');
        var navNext = controls.querySelector('[data-window-tabs-scroll="next"]');
        if (navPrev) { navPrev.hidden = bar.scrollLeft <= 1; }
        if (navNext) { navNext.hidden = maxScroll <= 1 || bar.scrollLeft >= maxScroll - 1; }
    }

    /** 将激活选项卡滚动到可视区（渲染后调用，保证当前选项卡始终可见） */
    function revealWindowTab(controls, tabId) {
        if (!controls) {
            return;
        }
        var bar = controls.querySelector('.window-tabs');
        var tabEl = bar && bar.querySelector('[data-window-tab="' + tabId + '"]');
        if (tabEl) {
            tabEl.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        }
        updateWindowTabsNav(controls);
    }

    /** 标题栏选项卡条：显示在 .window-controls 首位（收起左侧菜单左侧），点击切换、右上叉关闭 */
    function syncWindowTabsBar(windowState) {
        var controls = windowState.element.querySelector('.window-controls');
        if (!controls) {
            return;
        }
        var bar = controls.querySelector('.window-tabs');
        if (!windowState.tabs || !windowState.tabs.length) {
            if (bar) { bar.remove(); }
            // 连同溢出导航按钮一并移除，并恢复控制区右推（margin-left:auto），按钮贴右侧
            var stalePrev = controls.querySelector('[data-window-tabs-scroll="prev"]');
            var staleNext = controls.querySelector('[data-window-tabs-scroll="next"]');
            if (stalePrev) { stalePrev.remove(); }
            if (staleNext) { staleNext.remove(); }
            windowState.element.classList.remove('has-window-tabs');
            return;
        }
        // 选项卡模式下取消控制区右推，选项卡条从标题栏左侧展开（flex:1 撑满，按钮自然靠右）
        windowState.element.classList.add('has-window-tabs');
        if (!bar) {
            bar = document.createElement('div');
            bar.className = 'window-tabs';
            controls.insertAdjacentElement('afterbegin', bar);
            bar.addEventListener('scroll', function () { updateWindowTabsNav(controls); });
        }
        var active = windowActiveTab(windowState);
        bar.innerHTML = windowState.tabs.map(function (tab) {
            return '<button class="window-tab' + (active && tab.id === active.id ? ' is-active' : '') + '" type="button"'
                + ' data-window-tab="' + tab.id + '" title="' + escapeHtml(tab.title) + '">'
                + '<span>' + escapeHtml(tab.title) + '</span>'
                + '<i class="fa fa-times window-tab-close" data-window-tab-close="' + tab.id + '" aria-label="关闭选项卡"></i></button>';
        }).join('');
        // 溢出时显示的左右切换按钮：< 选项卡 选项卡 … >
        if (!controls.querySelector('[data-window-tabs-scroll="prev"]')) {
            var navPrev = document.createElement('button');
            navPrev.type = 'button';
            navPrev.className = 'window-tabs-nav';
            navPrev.dataset.windowTabsScroll = 'prev';
            navPrev.setAttribute('aria-label', '向前切换选项卡');
            navPrev.innerHTML = '<i class="fa fa-angle-left"></i>';
            controls.insertAdjacentElement('afterbegin', navPrev);
        }
        if (!controls.querySelector('[data-window-tabs-scroll="next"]')) {
            var navNext = document.createElement('button');
            navNext.type = 'button';
            navNext.className = 'window-tabs-nav';
            navNext.dataset.windowTabsScroll = 'next';
            navNext.setAttribute('aria-label', '向后切换选项卡');
            navNext.innerHTML = '<i class="fa fa-angle-right"></i>';
            bar.insertAdjacentElement('afterend', navNext);
        }
        // 激活选项卡滚入可视区，并按溢出状态更新左右按钮显隐
        revealWindowTab(controls, active ? active.id : '');
    }

    /** 品牌区副标题跟随当前选项卡（菜单名）：主标题应用名固定不随切换变化 */
    function syncWindowTabSubtitle(windowState, title) {
        var strong = windowState.element.querySelector('.window-brand strong');
        if (!strong) {
            return;
        }
        var small = strong.querySelector('small');
        if (small) {
            small.textContent = title;
        }
    }

    /** 切换到指定选项卡：复用既有容器不重新加载页面（品牌区主标题保持应用身份，副标题跟随菜单） */
    function switchWindowTab(windowState, tabId) {
        if (!windowState.tabs) {
            return;
        }
        var tab = windowState.tabs.find(function (item) { return item.id === tabId; });
        if (!tab) {
            return;
        }
        windowState.activeTabId = tabId;
        renderWindowPage(windowState, tab);
        syncWindowTabSubtitle(windowState, tab.title);
        syncWindowTabsBar(windowState);
    }

    /** 关闭选项卡：激活页关闭后按相邻优先（先右后左）切换；全部关闭时展示空状态引导 */
    function closeWindowTab(windowState, tabId) {
        if (!windowState.tabs) {
            return;
        }
        var index = windowState.tabs.findIndex(function (tab) { return tab.id === tabId; });
        if (index === -1) {
            return;
        }
        var wasActive = windowState.activeTabId === tabId;
        windowState.tabs.splice(index, 1);
        var host = windowState.element.querySelector('[data-window-page-host]');
        var page = host && host.querySelector('[data-window-page="' + tabId + '"]');
        if (page) {
            page.remove();
        }
        if (wasActive) {
            var next = windowState.tabs[Math.min(index, windowState.tabs.length - 1)] || null;
            windowState.activeTabId = next ? next.id : '';
            if (next) {
                renderWindowPage(windowState, next);
                syncWindowTabSubtitle(windowState, next.title);
            } else if (host) {
                host.innerHTML = emptyState('fa-clone', '选项卡已全部关闭，可从左侧菜单重新打开内容');
                // 全部关闭后副标题清空，品牌区仅保留应用名
                syncWindowTabSubtitle(windowState, '');
            }
        }
        syncWindowTabsBar(windowState);
    }

    function renderWindowPage(windowState, entry) {
        var host = windowState && windowState.element.querySelector('[data-window-page-host]');
        if (!host) {
            return;
        }
        // 多选项卡模式：每个选项卡独立容器（iframe/组件内容仅首次创建），切换只改变显示，页面状态保留
        if (windowState.tabs) {
            var tab = entry;
            // 「选项卡已全部关闭」空状态引导不是页面容器：恢复选项卡前先移除 host 下非页面子节点
            Array.prototype.forEach.call(host.children, function (node) {
                if (node.classList && !node.classList.contains('window-page')) {
                    node.remove();
                }
            });
            var page = host.querySelector('[data-window-page="' + tab.id + '"]');
            if (!page) {
                page = document.createElement('div');
                page.className = 'window-page';
                page.dataset.windowPage = tab.id;
                page.dataset.pageToken = '';
                host.appendChild(page);
            }
            var tabToken = (tab.openType === '_component' ? '_component:' : '_iframe:') + tab.path;
            if (page.dataset.pageToken !== tabToken) {
                page.dataset.pageToken = tabToken;
                if (tab.openType === '_component') {
                    page.innerHTML = emptyState('fa-circle-o-notch fa-spin', '正在加载页面…');
                    loadComponentPage(page, tab.path, tabToken);
                } else {
                    page.innerHTML = '<iframe src="' + escapeHtml(tab.path) + '" title="'
                        + escapeHtml(tab.title) + '"></iframe>';
                }
            }
            host.querySelectorAll('.window-page').forEach(function (node) {
                node.hidden = node.dataset.windowPage !== tab.id;
            });
            // iframe 重建后遮罩丢失，按当前聚焦状态补挂
            syncWindowShields();
            return;
        }
        var token = (entry.open_type === '_component' ? '_component:' : '_iframe:') + entry.path;
        if (host.dataset.pageToken === token) {
            return;
        }
        host.dataset.pageToken = token;
        if (entry.open_type === '_component') {
            host.innerHTML = emptyState('fa-circle-o-notch fa-spin', '正在加载页面…');
            loadComponentPage(host, entry.path, token);
            return;
        }
        host.innerHTML = '<iframe src="' + escapeHtml(entry.path) + '" title="'
            + escapeHtml(entry.title) + '"></iframe>';
        // iframe 重建后遮罩丢失，按当前聚焦状态补挂
        syncWindowShields();
    }

    function openEntry(entry) {
        if (!entry) {
            return;
        }
        if (openMenuByType(entry)) {
            recordEntryUsage(entry);
            closePanels();
            return;
        }
        if (!openablePath(entry)) {
            toast('仅支持打开站内应用入口', 'error');
            return;
        }

        recordEntryUsage(entry);
        closePanels();
        var key = windowKey(entry);
        if (state.windows.has(key)) {
            activateWindowEntry(state.windows.get(key), entry);
            restoreWindow(key);
            return;
        }

        var wrapper = document.createElement('div');
        wrapper.innerHTML = windowMarkup(entry, key);
        var windowElement = wrapper.firstElementChild;
        var offset = state.windows.size % 5;
        var layerRect = elements.windowLayer.getBoundingClientRect();
        var size = defaultWindowSize(key);
        // 个人设置窗口宽度固定 680px（小屏收窄避免溢出）
        if (entry.path === '/admin/account') {
            size.width = Math.max(MIN_WINDOW_WIDTH, Math.min(680, Math.round(layerRect.width) - 40));
        }
        windowElement.style.left = Math.max(24, (layerRect.width - size.width) / 2 + offset * 18) + 'px';
        windowElement.style.top = Math.max(20, (layerRect.height - size.height) / 2 + offset * 14) + 'px';
        windowElement.style.width = size.width + 'px';
        windowElement.style.height = size.height + 'px';
        // 官网动态窗口停靠桌面最右侧、垂直居中（仅超管桌面自动打开）
        if (entry.id === 'webos-official-news') {
            windowElement.style.left = Math.max(24, Math.round(layerRect.width) - size.width - 24) + 'px';
            windowElement.style.top = Math.max(20, Math.round((layerRect.height - size.height) / 2)) + 'px';
        }
        elements.windowLayer.appendChild(windowElement);
        var windowState = { entry: entry, element: windowElement, minimized: false, maximized: false, tabs: null, activeTabId: '' };
        state.windows.set(key, windowState);
        // 多选项卡开启时新窗口以首个选项卡承载入口页面（应用中心/OS 设置等特殊窗口无页面区，不启用）
        if (windowTabsEnabled() && windowElement.querySelector('[data-window-page-host]')) {
            windowState.tabs = [makeWindowTab(entry)];
            windowState.activeTabId = windowState.tabs[0].id;
        }
        renderWindowPage(windowState, windowState.tabs ? windowState.tabs[0] : entry);
        if (windowState.tabs) {
            syncWindowTabsBar(windowState);
        }
        // 应用有前台（home）菜单时在「收起左侧菜单」左侧注入前台菜单下拉框
        loadWindowHomeMenu(windowState);
        focusWindow(key);
        bindWindowGestures(key);
        renderTaskbarWindows();

        if (entry.id === 'webos-app-center') {
            renderAppCenter(state.appCenterTab);
            loadUpdateCount();
        }
        if (entry.id === 'webos-settings') {
            renderWebosSettings();
        }
        if (entry.id === 'webos-notification-page') {
            renderNotificationCenter(notificationCenterState.tab);
            loadNotifications();
        }
        if (entry.id === 'webos-official-news') {
            renderOfficialNews();
        }
    }

    function activateWindowEntry(windowState, entry) {
        if (!windowState || entry.id === 'webos-app-center' || entry.id === 'webos-settings'
            || entry.id === 'webos-notification-page' || entry.id === 'webos-official-news') {
            return;
        }
        // 多选项卡模式：同路径复用既有选项卡，否则新开并激活（页面内容按需创建，切换不重载）
        if (windowState.tabs) {
            var existing = windowState.tabs.find(function (tab) { return tab.path === entry.path; })
                || null;
            if (!existing) {
                existing = makeWindowTab(entry);
                windowState.tabs.push(existing);
            }
            windowState.activeTabId = existing.id;
            windowState.entry = entry;
            rerenderWindowNav(windowState, true);
            loadWindowHomeMenu(windowState);
            renderWindowPage(windowState, existing);
            syncWindowTabSubtitle(windowState, existing.title);
            syncWindowTabsBar(windowState);
            renderTaskbarWindows();
            return;
        }
        var identity = windowIdentity(entry);
        renderWindowPage(windowState, entry);
        windowState.entry = entry;
        rerenderWindowNav(windowState, true);
        // 切换入口后按新应用同步前台菜单下拉框（缓存命中无开销）
        loadWindowHomeMenu(windowState);
        windowState.element.querySelector('.window-brand strong').innerHTML = escapeHtml(identity.title)
            + '<small>' + escapeHtml(identity.subtitle) + '</small>';
        renderTaskbarWindows();
    }

    function focusWindow(key) {
        var target = state.windows.get(key);
        if (!target) {
            return;
        }
        state.zIndex += 1;
        state.windows.forEach(function (windowState) { windowState.element.classList.remove('is-focused'); });
        target.element.classList.add('is-focused');
        target.element.style.zIndex = state.zIndex;
        syncWindowShields();
        renderTaskbarWindows();
    }

    /**
     * 同步各窗口 iframe 上的透明遮罩：iframe 内部点击的 pointerdown 不会冒泡到父页面，
     * 未聚焦窗口的 iframe 被遮罩覆盖后，点击遮罩可冒泡聚焦窗口，第二次点击才操作 iframe 内容
     * （与 Windows「先激活再交互」一致）；聚焦窗口与 _component 窗口不遮罩。
     */
    function syncWindowShields() {
        state.windows.forEach(function (windowState) {
            var host = windowState.element.querySelector('[data-window-page-host]');
            if (!host || !host.querySelector('iframe')) {
                return;
            }
            var shield = host.querySelector('[data-window-shield]');
            if (windowState.element.classList.contains('is-focused')) {
                if (shield) {
                    shield.remove();
                }
                return;
            }
            if (!shield) {
                shield = document.createElement('div');
                shield.className = 'window-frame-shield';
                shield.dataset.windowShield = '1';
                host.appendChild(shield);
            }
        });
    }

    function restoreWindow(key) {
        var target = state.windows.get(key);
        if (!target) {
            return;
        }
        target.minimized = false;
        target.element.classList.remove('is-minimized');
        focusWindow(key);
    }

    function minimizeWindow(key) {
        var target = state.windows.get(key);
        if (!target) {
            return;
        }
        target.minimized = true;
        target.element.classList.add('is-minimized');
        renderTaskbarWindows();
    }

    function maximizeWindow(key) {
        var target = state.windows.get(key);
        if (!target) {
            return;
        }
        target.maximized = !target.maximized;
        target.element.classList.toggle('is-maximized', target.maximized);

        var maximizeButton = target.element.querySelector('[data-window-action="maximize"]');
        if (maximizeButton) {
            var maximizeIcon = maximizeButton.querySelector('i');
            maximizeButton.setAttribute('aria-label', target.maximized ? '还原' : '最大化');
            if (maximizeIcon) {
                maximizeIcon.className = 'fa ' + (target.maximized ? 'fa-clone' : 'fa-square-o');
            }
        }

        focusWindow(key);
    }

    function toggleWindowSidebar(windowElement, button) {
        var collapsed = windowElement.classList.toggle('is-sidebar-collapsed');
        var label = collapsed ? '展开左侧菜单' : '收起左侧菜单';
        var icon = button.querySelector('i');

        button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        button.setAttribute('aria-label', label);
        button.title = label;
        if (icon) {
            icon.className = 'fa ' + (collapsed ? 'fa-indent' : 'fa-outdent');
        }
    }

    function closeWindow(key) {
        var target = state.windows.get(key);
        if (!target) {
            return;
        }
        target.element.remove();
        state.windows.delete(key);
        renderTaskbarWindows();
    }

    function renderTaskbarWindows() {
        var pinnedKeys = pinnedTaskbarKeys();
        // 已固定应用的窗口复用固定图标，不在运行区重复显示（Windows 风格）
        elements.taskbarWindows.innerHTML = Array.from(state.windows.entries()).filter(function (pair) {
            return !pinnedKeys.has(entryAppKey(pair[1].entry));
        }).map(function (pair) {
            var key = pair[0];
            var windowState = pair[1];
            var identity = windowState.entry.id === 'webos-app-center'
                ? { title: '应用中心', icon: windowState.entry.icon }
                : windowIdentity(windowState.entry);
            return '<button class="taskbar-app-button is-running ' + (windowState.element.classList.contains('is-focused') && !windowState.minimized ? 'is-active' : '')
                + '" type="button" data-task-window="' + escapeHtml(key) + '" title="' + escapeHtml(identity.title) + '">'
                + entryIconMarkup(windowState.entry, 'taskbar-app-icon') + '</button>';
        }).join('');
        renderPinnedApps();
    }

    /** 将高频 pointermove 合并到浏览器绘制帧，避免同一帧重复触发布局计算 */
    function createFrameScheduler(callback) {
        var frame = 0;
        var latest = null;

        function run() {
            frame = 0;
            var value = latest;
            latest = null;
            callback(value);
        }

        function schedule(value) {
            latest = value;
            if (!frame) {
                frame = window.requestAnimationFrame(run);
            }
        }

        schedule.flush = function () {
            if (!frame) {
                return;
            }
            window.cancelAnimationFrame(frame);
            run();
        };

        return schedule;
    }

    function bindWindowGestures(key) {
        var target = state.windows.get(key);
        var element = target.element;
        var titlebar = element.querySelector('[data-window-drag]');

        element.addEventListener('pointerdown', function () { focusWindow(key); });
        titlebar.addEventListener('pointerdown', function (event) {
            if (event.target.closest('button') || target.maximized) {
                return;
            }
            var startX = event.clientX;
            var startY = event.clientY;
            var startLeft = element.offsetLeft;
            var startTop = element.offsetTop;
            var pointerId = event.pointerId;
            var scheduleMove = createFrameScheduler(function (point) {
                var left = Math.max(0, Math.min(window.innerWidth - 180, startLeft + point.x - startX));
                var top = Math.max(0, Math.min(window.innerHeight - 120, startTop + point.y - startY));
                element.style.left = left + 'px';
                element.style.top = top + 'px';
            });
            element.classList.add('is-window-gesturing');
            titlebar.setPointerCapture(event.pointerId);
            function move(moveEvent) {
                scheduleMove({ x: moveEvent.clientX, y: moveEvent.clientY });
            }
            function end() {
                scheduleMove.flush();
                element.classList.remove('is-window-gesturing');
                titlebar.removeEventListener('pointermove', move);
                titlebar.removeEventListener('pointerup', end);
                titlebar.removeEventListener('pointercancel', end);
                titlebar.removeEventListener('lostpointercapture', end);
                if (titlebar.hasPointerCapture(pointerId)) {
                    titlebar.releasePointerCapture(pointerId);
                }
            }
            titlebar.addEventListener('pointermove', move);
            titlebar.addEventListener('pointerup', end);
            titlebar.addEventListener('pointercancel', end);
            titlebar.addEventListener('lostpointercapture', end);
        });

        // 双击标题栏（品牌区/空白处）切换最大化还原（Windows 桌面惯例）；控制区按钮双击不触发
        titlebar.addEventListener('dblclick', function (event) {
            if (event.target.closest('button')) {
                return;
            }
            if (target.maximized) {
                restoreWindow(key);
            } else {
                maximizeWindow(key);
            }
        });

        element.querySelectorAll('[data-window-resize]').forEach(function (handle) {
            handle.addEventListener('pointerdown', function (event) {
                if (target.maximized) {
                    return;
                }
                event.preventDefault();
                var dir = handle.dataset.windowResize; // n/s/e/w/ne/nw/se/sw
                var startX = event.clientX;
                var startY = event.clientY;
                var startWidth = element.offsetWidth;
                var startHeight = element.offsetHeight;
                var startLeft = element.offsetLeft;
                var startTop = element.offsetTop;
                var pointerId = event.pointerId;
                var scheduleMove = createFrameScheduler(function (point) {
                    var dx = point.x - startX;
                    var dy = point.y - startY;
                    var width = startWidth;
                    var height = startHeight;
                    var left = startLeft;
                    var top = startTop;

                    if (dir.indexOf('e') >= 0) {
                        width = Math.max(MIN_WINDOW_WIDTH, Math.min(window.innerWidth - left, startWidth + dx));
                    }
                    if (dir.indexOf('s') >= 0) {
                        height = Math.max(MIN_WINDOW_HEIGHT, Math.min(window.innerHeight - 74 - top, startHeight + dy));
                    }
                    if (dir.indexOf('w') >= 0) {
                        width = Math.max(MIN_WINDOW_WIDTH, startWidth - dx);
                        left = Math.max(0, startLeft + startWidth - width);
                        width = startWidth + (startLeft - left);
                    }
                    if (dir.indexOf('n') >= 0) {
                        height = Math.max(MIN_WINDOW_HEIGHT, startHeight - dy);
                        top = Math.max(0, startTop + startHeight - height);
                        height = startHeight + (startTop - top);
                    }
                    element.style.width = width + 'px';
                    element.style.height = height + 'px';
                    element.style.left = left + 'px';
                    element.style.top = top + 'px';
                });
                element.classList.add('is-window-gesturing');
                handle.setPointerCapture(event.pointerId);
                function move(moveEvent) {
                    scheduleMove({ x: moveEvent.clientX, y: moveEvent.clientY });
                }
                function end() {
                    scheduleMove.flush();
                    element.classList.remove('is-window-gesturing');
                    handle.removeEventListener('pointermove', move);
                    handle.removeEventListener('pointerup', end);
                    handle.removeEventListener('pointercancel', end);
                    handle.removeEventListener('lostpointercapture', end);
                    if (handle.hasPointerCapture(pointerId)) {
                        handle.releasePointerCapture(pointerId);
                    }
                }
                handle.addEventListener('pointermove', move);
                handle.addEventListener('pointerup', end);
                handle.addEventListener('pointercancel', end);
                handle.addEventListener('lostpointercapture', end);
            });
        });
    }

    var APP_CENTER_TABS = {
        market: ['应用市场', '发现和安装更多应用，扩展系统能力'],
        installed: ['已安装应用', '管理应用状态与应用操作'],
        uninstalled: ['未安装应用', '查看本地已发现但尚未安装的应用'],
        updates: ['应用更新', '检查可用版本并查看更新说明'],
        records: ['安装记录', '查看应用安装、启用、升级与卸载记录'],
        entries: ['入口管理', '管理桌面快捷方式与可用系统菜单']
    };

    function renderAppCenter(tab) {
        state.appCenterTab = tab || 'market';
        if (state.appCenterTab !== 'market') {
            cancelMarketRequest();
        }
        var appWindow = state.windows.get(windowKey(applicationCenterEntry()));
        if (!appWindow) {
            return;
        }
        appWindow.element.querySelectorAll('[data-special-tab]').forEach(function (button) {
            button.classList.toggle('is-active', button.dataset.specialTab === state.appCenterTab);
        });
        var container = appWindow.element.querySelector('[data-app-center]');
        var title = APP_CENTER_TABS[state.appCenterTab] || APP_CENTER_TABS.market;
        container.innerHTML = '<div class="app-center-toolbar"><div><h1>' + title[0] + '</h1><p>' + title[1] + '</p></div>'
            + '<div class="app-center-tools">' + appCenterSearchMarkup() + appCenterExtrasMarkup(state.appCenterTab) + '</div></div>'
            + '<div class="app-center-status" data-app-status><i class="fa fa-circle-o-notch fa-spin"></i>正在读取数据</div>'
            + '<div class="app-center-content" data-app-content></div>';
        loadAppCenterTab(container, state.appCenterTab);
    }

    function appCenterSearchMarkup() {
        var placeholders = {
            market: '搜索市场应用',
            installed: '搜索已安装应用',
            uninstalled: '搜索未安装应用',
            records: '搜索应用名称或标识'
        };
        var placeholder = placeholders[state.appCenterTab] || '搜索应用';

        return '<label class="app-center-search"><i class="fa fa-search"></i>'
            + '<input type="search" data-app-search placeholder="' + placeholder + '"></label>';
    }

    function appCenterExtrasMarkup(tab) {
        // 安装记录：类型筛选与右上角搜索框并排
        if (tab === 'records') {
            return '<label class="app-center-filter"><i class="fa fa-filter"></i>'
                + '<select data-records-operation aria-label="按操作类型筛选">' + recordOperationOptions(state.recordsOperation) + '</select></label>';
        }
        if (tab !== 'installed') {
            return '';
        }

        return appStatusFilterMarkup()
            + '<button class="webos-button secondary compact" type="button" data-app-upload><i class="fa fa-upload"></i>上传安装</button>';
    }

    function appStatusFilterMarkup() {
        var options = [['', '全部状态'], ['1', '已启用'], ['2', '已禁用']];

        return '<label class="app-center-filter"><i class="fa fa-filter"></i><select data-app-status-filter>'
            + options.map(function (option) {
                return '<option value="' + option[0] + '">' + option[1] + '</option>';
            }).join('') + '</select></label>';
    }

    function extractCollection(payload) {
        if (Array.isArray(payload)) {
            return payload;
        }
        if (!payload || typeof payload !== 'object') {
            return [];
        }
        if (Array.isArray(payload.items)) {
            return payload.items;
        }
        if (payload.data && Array.isArray(payload.data)) {
            return payload.data;
        }
        if (payload.data && Array.isArray(payload.data.items)) {
            return payload.data.items;
        }
        if (Array.isArray(payload.all)) {
            return payload.all;
        }
        return [];
    }

    function loadAppCenterTab(container, tab) {
        var status = container.querySelector('[data-app-status]');
        var content = container.querySelector('[data-app-content]');
        if (tab === 'installed') {
            renderInstalledSummary(status, state.catalog.applications);
            content.innerHTML = renderApplicationCards(state.catalog.applications, 'installed');
            return;
        }
        if (tab === 'uninstalled') {
            api('/api/admin/apps/available').then(function (payload) {
                var apps = (payload && payload.uninstalled_apps) || [];
                setMarketApps(apps, 'local');
                status.innerHTML = '<i class="fa fa-download"></i>共 ' + apps.length + ' 个未安装应用';
                content.innerHTML = renderApplicationCards(apps, 'local');
            }).catch(function (error) {
                status.classList.add('is-error');
                status.innerHTML = '<i class="fa fa-exclamation-circle"></i>' + escapeHtml(error.message);
                content.innerHTML = emptyState('fa-download', '暂时无法读取未安装应用');
            });
            return;
        }
        if (tab === 'records') {
            loadRecordsTab(content, status, state.recordsOperation || '');
            return;
        }
        if (tab === 'entries') {
            status.innerHTML = '<i class="fa fa-th"></i>桌面已固定 ' + state.workspace.desktop_items.length + ' 个入口';
            content.innerHTML = renderEntryManager();
            return;
        }
        if (tab === 'updates') {
            // 服务端检查结果缓存 24h，重新请求仍会返回刚升级的应用；
            // 已检查过（updateChecked）时优先用本地 state.updateApps 渲染
            // （升级成功后 dropUpdatedApp 已移除对应项，刷新列表即不再显示）
            if (state.updateChecked) {
                renderUpdatesStatus(status);
                content.innerHTML = renderApplicationCards(state.updateApps, 'updates');
                return;
            }
            api('/api/admin/apps/check-updates?lazy=1', { method: 'POST' }).then(function (payload) {
                state.updateApps = extractCollection(payload);
                state.updateCount = state.updateApps.length;
                state.updateChecked = true;
                syncUpdateBadge();
                renderUpdatesStatus(status);
                content.innerHTML = renderApplicationCards(state.updateApps, 'updates');
            }).catch(function (error) {
                status.classList.add('is-error');
                status.innerHTML = '<i class="fa fa-exclamation-circle"></i>' + escapeHtml(error.message);
                content.innerHTML = emptyState('fa-refresh', '暂时无法检查更新');
            });
            return;
        }

        if (tab === 'market') {
            loadMarketTab(content, status);
            return;
        }
    }

    /** 应用更新状态栏：刷新按钮（点击重新检查更新）+ 数量文案 */
    function renderUpdatesStatus(status) {
        status.innerHTML = '<button type="button" class="updates-refresh-button" data-updates-refresh title="重新检查更新" aria-label="重新检查更新"><i class="fa fa-refresh"></i></button>'
            + (state.updateApps.length
                ? '发现 ' + state.updateApps.length + ' 个可用更新'
                : '暂无可用更新，应用已是最新');
    }

    /** 点击刷新：强制全量检查更新（不带 lazy 缓存口径）并重渲染更新列表 */
    function refreshAppUpdates(center) {
        var status = center.querySelector('[data-app-status]');
        var content = center.querySelector('[data-app-content]');
        if (!status || !content) {
            return;
        }
        status.classList.remove('is-error');
        status.innerHTML = '<i class="fa fa-circle-o-notch fa-spin"></i>正在检查更新';
        api('/api/admin/apps/check-updates', { method: 'POST' }).then(function (payload) {
            state.updateApps = extractCollection(payload);
            state.updateCount = state.updateApps.length;
            state.updateChecked = true;
            syncUpdateBadge();
            renderUpdatesStatus(status);
            content.innerHTML = renderApplicationCards(state.updateApps, 'updates');
            toast('检查更新完成');
        }).catch(function (error) {
            status.classList.add('is-error');
            status.innerHTML = '<i class="fa fa-exclamation-circle"></i>' + escapeHtml(error.message);
        });
    }

    /** 应用市场 Tab：顶部子Tab（首页/分类），首页为推荐区块，分类为原分类条+应用列表 */
    function loadMarketTab(content, status) {
        if (!state.marketSubTab) {
            state.marketSubTab = 'home';
        }
        status.hidden = true;
        content.innerHTML = '<nav class="market-sub-tabs" data-market-sub-tabs>'
            + MARKET_SUB_TABS.map(function (tab) {
                return '<button class="market-sub-tab' + (tab.id === state.marketSubTab ? ' is-active' : '')
                    + '" type="button" data-market-sub-tab="' + tab.id + '">'
                    + '<i class="fa ' + safeIcon(tab.icon) + '"></i>' + tab.title + '</button>';
            }).join('')
            + '</nav>'
            + '<div data-market-subbody></div>';
        renderMarketSubTab(content, status);
    }

    /** 市场内部子Tab 定义 */
    var MARKET_SUB_TABS = [
        { id: 'home', title: '首页', icon: 'fa fa-home' },
        { id: 'category', title: '分类', icon: 'fa fa-tags' }
    ];

    /** 切换市场内部子Tab：更新按钮态并重渲染主体 */
    function switchMarketSubTab(tab, content) {
        if (state.marketSubTab === tab) {
            return;
        }
        state.marketSubTab = tab;
        content.querySelectorAll('[data-market-sub-tab]').forEach(function (button) {
            button.classList.toggle('is-active', button.dataset.marketSubTab === tab);
        });
        var status = content.querySelector('[data-app-status]');
        renderMarketSubTab(content, status);
    }

    /** 按当前子Tab渲染市场主体 */
    function renderMarketSubTab(content, status) {
        var body = content.querySelector('[data-market-subbody]');
        if (!body) {
            return;
        }
        if (state.marketSubTab === 'category') {
            renderMarketCategoryView(content, status, body);
            return;
        }
        renderMarketHome(content, status, body);
    }

    /** 分类视图：分类 Tab 条 + 应用列表滚动分页加载（远程不可用时降级本地可安装应用） */
    function renderMarketCategoryView(content, status, body) {
        resetMarketPager('', '');
        body.innerHTML = '<nav class="market-tabs" data-market-tabs hidden></nav>'
            + '<div class="app-grid" data-market-grid></div>'
            + '<div class="market-sentinel" data-market-sentinel><i class="fa fa-circle-o-notch fa-spin"></i>正在读取应用市场…</div>';
        bindMarketScroll(content, status);
        api('/api/admin/market/categories').then(function (payload) {
            renderMarketCategoryTabs(content, extractCollection(payload));
            return null;
        }).catch(function () { return null; });
        fetchMarketApps(content).catch(function () {
            loadLocalMarketApps(content, status);
        });
    }

    /** 首页区块翻页：dir=1 下一页 / -1 上一页，仅重渲染该区块内容区并同步按钮可用态 */
    function switchMarketHomePage(pagerBtn, dir) {
        var key = pagerBtn.dataset.homeKey;
        var section = marketHomeSections[key];
        if (!section) {
            return;
        }
        var maxPage = Math.ceil(section.apps.length / section.pageSize) - 1;
        var page = section.page + dir;
        if (page < 0 || page > maxPage) {
            return;
        }
        section.page = page;
        // 从点击按钮就近定位所属区块，避免多窗口时命中其他窗口的区块
        var scope = pagerBtn.closest('.market-home-column, .market-home-section');
        var body = scope ? scope.querySelector('[data-home-body]') : null;
        if (!body) {
            return;
        }
        body.innerHTML = section.type === 'list'
            ? marketHomePageApps(key).map(marketHomeListItemMarkup).join('')
            : marketHomePageApps(key).map(marketHomeCardMarkup).join('');
        var prevBtn = scope.querySelector('[data-home-pager="prev"]');
        var nextBtn = scope.querySelector('[data-home-pager="next"]');
        if (prevBtn) {
            prevBtn.disabled = section.page === 0;
        }
        if (nextBtn) {
            nextBtn.disabled = section.page >= maxPage;
        }
    }

    /** 首页视图：随机推荐/推荐应用/推荐位竖列/辅助推荐，数据来自市场 home 接口 */
    function renderMarketHome(content, status, body) {
        cancelMarketRequest();
        body.innerHTML = '<div class="market-sentinel"><i class="fa fa-circle-o-notch fa-spin"></i>正在读取推荐内容…</div>';
        api('/api/admin/market/home').then(function (payload) {
            var data = payload || {};
            var random = Array.isArray(data.random) ? data.random : [];
            var featured = Array.isArray(data.featured) ? data.featured : [];
            var positions = Array.isArray(data.positions) ? data.positions : [];
            cacheMarketApps(random.concat(featured));
            positions.forEach(function (position) {
                cacheMarketApps(position.apps || []);
            });

            // 推荐位拆分：常规推荐位竖列展示（每列每页5个），辅助推荐(aux)横排网格（每页12个）
            var columnPositions = positions.filter(function (position) {
                return position.code !== 'aux' && (position.apps || []).length;
            });
            var auxPositions = positions.filter(function (position) {
                return position.code === 'aux' && (position.apps || []).length;
            });

            // 区块分页状态：应用数超过每页数量时标题右侧显示 < > 翻页（重新进入首页时重建）
            marketHomeSections = {
                random: { type: 'grid', apps: random, pageSize: 12, page: 0 },
                featured: { type: 'grid', apps: featured, pageSize: 12, page: 0 }
            };
            auxPositions.forEach(function (position) {
                marketHomeSections['aux-' + position.code] = { type: 'grid', apps: position.apps || [], pageSize: 12, page: 0 };
            });
            columnPositions.forEach(function (position) {
                marketHomeSections['pos-' + position.code] = { type: 'list', apps: position.apps || [], pageSize: 5, page: 0 };
            });

            var html = '<div class="market-home">'
                + marketHomeGridSection('random', '不可错过的应用')
                + marketHomeGridSection('featured', '推荐应用')
                + marketHomeColumnsSection(columnPositions)
                + auxPositions.map(function (position) {
                    return marketHomeGridSection('aux-' + position.code, position.name);
                }).join('')
                + '</div>';
            body.innerHTML = html;
        }).catch(function (error) {
            body.innerHTML = emptyState('fa-shopping-bag', '暂时无法读取市场首页：' + (error.message || '网络异常'));
        });
    }

    /** 首页应用写入市场缓存（供详情层读取），不清空已有缓存 */
    function cacheMarketApps(apps) {
        (apps || []).forEach(function (app) {
            if (app && app.app_id) {
                app._source = 'market';
                state.marketApps.set(app.app_id, app);
            }
        });
    }

    /** 首页区块分页状态：key -> { type, apps, pageSize, page }，renderMarketHome 每次重建 */
    var marketHomeSections = {};

    /** 当前页应用切片 */
    function marketHomePageApps(key) {
        var section = marketHomeSections[key];
        return section.apps.slice(section.page * section.pageSize, (section.page + 1) * section.pageSize);
    }

    /** 区块标题行：标题 + （超过一页时）右侧 < > 翻页按钮 */
    function marketHomeTitleRow(key, title) {
        var section = marketHomeSections[key];
        var maxPage = Math.ceil(section.apps.length / section.pageSize) - 1;
        var pager = '';
        if (maxPage > 0) {
            pager = '<span class="market-home-pager">'
                + '<button type="button" class="market-home-pager-btn" data-home-pager="prev" data-home-key="' + escapeHtml(key) + '"'
                + (section.page === 0 ? ' disabled' : '') + '><i class="fa fa-angle-left"></i></button>'
                + '<button type="button" class="market-home-pager-btn" data-home-pager="next" data-home-key="' + escapeHtml(key) + '"'
                + (section.page >= maxPage ? ' disabled' : '') + '><i class="fa fa-angle-right"></i></button>'
                + '</span>';
        }
        return '<div class="market-home-title-row"><h3 class="market-home-title">' + escapeHtml(title) + '</h3>' + pager + '</div>';
    }

    /** 首页横排网格区块：每行6个，图标+名称（超过一页时标题行带翻页按钮） */
    function marketHomeGridSection(key, title) {
        var section = marketHomeSections[key];
        if (!section || !section.apps.length) {
            return '';
        }
        return '<section class="market-home-section">' + marketHomeTitleRow(key, title)
            + '<div class="market-home-grid" data-home-body="' + escapeHtml(key) + '">'
            + marketHomePageApps(key).map(marketHomeCardMarkup).join('') + '</div></section>';
    }

    /** 首页紧凑卡片：图标+名称，整卡点击进入市场详情 */
    function marketHomeCardMarkup(app) {
        var id = app.app_id || '';
        return '<article class="market-home-card" data-market-detail="' + escapeHtml(id)
            + '" data-app-name="' + escapeHtml((app.name || id).toLowerCase()) + '">'
            + cardIconMarkup(app, 'market-home-icon')
            + '<span class="market-home-name">' + escapeHtml(app.name || id) + '</span></article>';
    }

    /** 首页推荐位竖列区块：每个推荐位一列，列表形式分页展示（每列每页5个） */
    function marketHomeColumnsSection(positions) {
        if (!positions || !positions.length) {
            return '';
        }
        return '<div class="market-home-columns">' + positions.map(function (position) {
            var key = 'pos-' + position.code;
            return '<div class="market-home-column">' + marketHomeTitleRow(key, position.name || position.code)
                + '<div class="market-home-list" data-home-body="' + escapeHtml(key) + '">'
                + marketHomePageApps(key).map(marketHomeListItemMarkup).join('') + '</div></div>';
        }).join('') + '</div>';
    }

    /** 推荐位列表条目：图标+应用名，换行小字显示部分应用描述 */
    function marketHomeListItemMarkup(app) {
        var id = app.app_id || '';
        return '<article class="market-home-list-item" data-market-detail="' + escapeHtml(id)
            + '" data-app-name="' + escapeHtml((app.name || id).toLowerCase()) + '">'
            + cardIconMarkup(app, 'market-home-list-icon')
            + '<div class="market-home-list-info"><strong>' + escapeHtml(app.name || id) + '</strong>'
            + '<span>' + escapeHtml(app.description || '暂无应用说明') + '</span></div></article>';
    }

    /** 拉取当前分类/关键词的下一页市场应用并追加渲染（带请求序号防竞态） */
    function fetchMarketApps(content) {
        var pager = state.marketPager;
        if (pager.loading || pager.failed || pager.page >= pager.lastPage) {
            return Promise.resolve();
        }
        pager.loading = true;
        var page = pager.page + 1;
        var query = '?per_page=20&page=' + page
            + (pager.category ? '&category=' + encodeURIComponent(pager.category) : '')
            + (pager.keyword ? '&keyword=' + encodeURIComponent(pager.keyword) : '');
        var requestId = ++marketRequestSequence;
        marketRequestController = typeof window.AbortController === 'function' ? new window.AbortController() : null;
        var requestController = marketRequestController;
        pager.token = requestId;
        return api('/api/admin/market/apps' + query,
            requestController ? { signal: requestController.signal } : {}).then(function (payload) {
            if (requestId !== marketRequestSequence || state.marketPager !== pager) {
                return []; // 请求已被更新（切换分类/搜索）取代，丢弃过期结果
            }
            var apps = extractCollection(payload);
            // api() 已解包响应 data 层，分页对象直接位于 payload.pagination
            var pagination = (payload && payload.pagination) || {};
            pager.page = Number(pagination.current_page) || page;
            pager.lastPage = Number(pagination.last_page) || pager.page;
            pager.total = Number(pagination.total) || 0;
            apps.forEach(function (app) {
                app._source = 'market';
                state.marketApps.set(app.app_id, app);
            });
            var grid = content.querySelector('[data-market-grid]');
            if (grid) {
                if (page === 1) {
                    grid.innerHTML = '';
                }
                grid.insertAdjacentHTML('beforeend', appCardsMarkup(apps, 'market'));
                if (!grid.children.length) {
                    grid.innerHTML = emptyState('fa-shopping-bag', '没有找到符合条件的应用');
                }
            }
            pager.loading = false;
            updateMarketSentinel(content);
            return apps;
        }).catch(function (error) {
            pager.loading = false;
            if (error && error.name === 'AbortError') {
                return [];
            }
            throw error;
        }).finally(function () {
            if (marketRequestController === requestController) {
                marketRequestController = null;
            }
        });
    }

    /** 远程市场不可用时降级：展示本地可安装应用 */
    function loadLocalMarketApps(content, status) {
        state.marketPager.failed = true;
        status.hidden = false;
        return api('/api/admin/apps/available').then(function (payload) {
            var apps = (payload && payload.uninstalled_apps) || [];
            setMarketApps(apps, 'local');
            status.innerHTML = '<i class="fa fa-hdd-o"></i>远程市场暂不可用，正在显示本地可安装应用';
            content.innerHTML = renderApplicationCards(apps, 'local');
        }).catch(function (error) {
            status.classList.add('is-error');
            status.innerHTML = '<i class="fa fa-exclamation-circle"></i>' + escapeHtml(error.message);
            content.innerHTML = emptyState('fa-shopping-bag', '暂时无法读取应用市场');
        });
    }

    /** 渲染市场分类 Tab 条（“全部” + 各分类） */
    function renderMarketCategoryTabs(content, categories) {
        var nav = content.querySelector('[data-market-tabs]');
        if (!nav || !categories || !categories.length) {
            return;
        }
        nav.hidden = false;
        nav.innerHTML = '<button class="market-tab is-active" type="button" data-market-category="">全部</button>'
            + categories.map(function (category) {
                var code = category.code || '';
                return '<button class="market-tab" type="button" data-market-category="' + escapeHtml(code) + '">'
                    + (category.icon ? '<i class="fa ' + safeIcon(category.icon) + '"></i>' : '')
                    + escapeHtml(category.name || code) + '</button>';
            }).join('');
    }

    /** 切换分类：重置分页并按分类重新加载第一页（清空旧卡片，加载提示统一由下方哨兵显示） */
    function switchMarketCategory(code, content) {
        if (state.marketPager.category === code) {
            return;
        }
        resetMarketPager(code, '');
        content.querySelectorAll('[data-market-category]').forEach(function (tab) {
            tab.classList.toggle('is-active', tab.dataset.marketCategory === code);
        });
        var grid = content.querySelector('[data-market-grid]');
        if (grid) {
            grid.innerHTML = '';
        }
        var sentinel = content.querySelector('[data-market-sentinel]');
        if (sentinel) {
            sentinel.hidden = false;
        }
        fetchMarketApps(content).catch(function () {
            toast('读取该分类应用失败', 'error');
        });
    }

    /** 市场全量搜索：带 keyword 重新 Ajax 拉取（远程市场搜索参数为 keyword），保留当前分类；首页态先切到分类视图 */
    function searchMarketApps(content, keyword) {
        if (state.marketSubTab === 'home') {
            switchMarketSubTab('category', content);
        }
        resetMarketPager(state.marketPager.category, keyword);
        var grid = content.querySelector('[data-market-grid]');
        if (grid) {
            grid.innerHTML = '<div class="panel-empty"><i class="fa fa-circle-o-notch fa-spin"></i>正在搜索应用…</div>';
        }
        var sentinel = content.querySelector('[data-market-sentinel]');
        if (sentinel) {
            sentinel.hidden = false;
        }
        fetchMarketApps(content).catch(function () {
            toast('搜索应用失败', 'error');
        });
    }

    /** 全部分页加载完成后隐藏加载哨兵 */
    function updateMarketSentinel(content) {
        var sentinel = content.querySelector('[data-market-sentinel]');
        if (sentinel) {
            sentinel.hidden = state.marketPager.page >= state.marketPager.lastPage;
        }
    }

    /** 列表滚动到底部时自动加载下一页 */
    function bindMarketScroll(content) {
        content.addEventListener('scroll', function () {
            var pager = state.marketPager;
            if (pager.loading || pager.failed || pager.page >= pager.lastPage) {
                return;
            }
            if (content.scrollTop + content.clientHeight < content.scrollHeight - 60) {
                return;
            }
            fetchMarketApps(content).catch(function () {
                toast('加载更多应用失败', 'error');
            });
        });
    }

    function setMarketApps(apps, source) {
        state.marketApps.clear();
        (apps || []).forEach(function (app) {
            app._source = source;
            state.marketApps.set(app.app_id, app);
        });
    }

    function appIconFallbackMarkup(app) {
        var fallback = app.icon;
        if (isImageIcon(fallback)) {
            return '<img data-app-icon-fallback hidden data-src="' + escapeHtml(fallback) + '" decoding="async" alt="">'
                + '<i data-app-icon-final hidden class="fa fa-cube"></i>';
        }
        return '<i data-app-icon-fallback hidden class="' + safeIcon(fallback || 'fa fa-cube') + '"></i>';
    }

    function applicationIconMarkup(app, className) {
        if (isImageIcon(app.icon_url)) {
            // is-app-icon 统一在源头声明：所有调用位的图片图标共享等比缩放约束，避免新渲染位遗漏
            return '<span class="' + className + ' is-app-icon"><img data-app-icon-primary src="'
                + escapeHtml(app.icon_url) + '" decoding="async" alt="">'
                + appIconFallbackMarkup(app) + '</span>';
        }
        return '<span class="' + className + '"><i class="'
            + safeIcon(app.icon || 'fa fa-cube') + '"></i></span>';
    }

    function marketIconMarkup(app, className) {
        var url = marketIconUrl(app.icon);
        if (!url) {
            return '<span class="' + className + '"><i class="fa fa-puzzle-piece"></i></span>';
        }

        return '<span class="' + className + '"><img data-market-icon src="' + escapeHtml(url)
            + '" loading="lazy" decoding="async" alt="">'
            + '<i data-market-icon-fallback class="fa fa-puzzle-piece" hidden></i></span>';
    }

    function cardIconMarkup(app, className) {
        // 更新列表等不含图标数据的记录：合并本地目录应用的图标——icon_url 由服务端
        // 按全局规则 icon.svg → icon.png 解析，catalog.icon 为 manifest.json 的 icon 兜底
        if (!app.icon_url && !app.icon) {
            var catalogApp = findCatalogApp(app.app_id);
            if (catalogApp) {
                app = Object.assign({}, catalogApp, app);
            }
        }
        if (app.icon_url) {
            return applicationIconMarkup(app, className);
        }
        if (app._source === 'market' && app.icon) {
            return marketIconMarkup(app, className);
        }
        return applicationIconMarkup(app, className);
    }

    function marketState(app) {
        var currentVersion = installedVersions()[String(app.app_id || '').toLowerCase()];
        var latestVersion = app.latest_version || app.version || '';
        var installed = typeof currentVersion === 'string' && currentVersion !== '';

        return {
            installed: installed,
            current_version: currentVersion || '',
            latest_version: latestVersion,
            has_update: installed && compareVersions(currentVersion, latestVersion) < 0
        };
    }

    function entryIconMarkup(entry, className) {
        var application = findApplication(entry.app_id);
        if (application) {
            return applicationIconMarkup(application, className);
        }

        return '<span class="' + className + '"><i class="' + safeIcon(entry.icon) + '"></i></span>';
    }

    function renderApplicationCards(apps, mode) {
        if (mode === 'installed') {
            return renderInstalledList(apps);
        }
        if (!apps || !apps.length) {
            return emptyState(mode === 'updates' ? 'fa-check-circle' : 'fa-cubes', mode === 'updates' ? '当前应用均为最新版本' : '暂无应用数据');
        }
        return '<div class="app-grid">' + appCardsMarkup(apps, mode) + '</div>';
    }

    /** 应用卡片 HTML（market/local/updates 共用），供整列渲染与市场滚动追加复用 */
    function appCardsMarkup(apps, mode) {
        return apps.map(function (app) {
            var id = app.app_id || '';
            var version = app.version || app.current_version || app.latest_version || '-';
            var description = app.description || app.changelog || '暂无应用说明';
            var actions = '';
            var market = mode === 'market' ? marketState(app) : null;
            if (market) {
                actions = marketCardActions(app, market);
                version = market.latest_version || version;
                if (market.installed) {
                    version += ' · 本地 ' + market.current_version;
                }
            } else if (mode === 'updates') {
                actions = '<button class="small-action primary" type="button" data-upgrade-id="' + escapeHtml(id) + '">更新</button>';
            } else {
                // 与已安装行菜单对等：未安装应用同样提供导出；右上角 × 物理删除应用文件
                actions = '<button class="small-action primary" type="button" data-install-id="' + escapeHtml(id)
                    + '" data-install-source="local">安装</button>'
                    + '<button class="small-action" type="button" data-export-id="' + escapeHtml(id)
                    + '" data-export-source="local">导出</button>';
            }
            // 远程市场应用整卡可点击进入详情页；本地/更新列表点击应用名称进入本地详情
            var detail = app._source === 'market' && id ? ' data-market-detail="' + escapeHtml(id) + '"' : '';
            var remove = mode === 'local'
                ? '<button class="app-card-remove" type="button" data-delete-app-id="' + escapeHtml(id)
                    + '" data-app-name="' + escapeHtml(app.name || id) + '" title="删除应用文件" aria-label="删除应用文件">'
                    + '<i class="fa fa-times"></i></button>'
                : '';
            var title = mode === 'market' ? '' : ' class="app-card-title" data-local-detail="'
                + escapeHtml(id) + '" data-local-source="' + (mode === 'updates' ? 'updates' : 'local') + '"';
            return '<article class="app-card' + (mode === 'local' ? ' has-remove' : '') + '"' + detail + ' data-app-name="' + escapeHtml((app.name || id).toLowerCase()) + '">'
                + remove + cardIconMarkup(app, 'app-icon') + '<div class="app-card-info"><strong' + title + '>' + escapeHtml(app.name || id) + '</strong><span>'
                + escapeHtml(description) + '</span><small>版本 ' + escapeHtml(version) + (app.author ? ' · ' + escapeHtml(app.author) : '') + '</small></div>'
                + '<div class="app-card-actions">' + actions + '</div></article>';
        }).join('');
    }

    function marketCardActions(app, market) {
        if (market.has_update) {
            return '<button class="small-action primary" type="button" data-upgrade-id="' + escapeHtml(app.app_id) + '">更新</button>';
        }
        if (market.installed) {
            return '<button class="small-action" type="button" disabled>已安装</button>';
        }

        return '<button class="small-action primary" type="button" data-install-id="' + escapeHtml(app.app_id) + '" data-install-source="market">安装</button>';
    }

    /** 打开市场应用详情：在应用中心面板上覆盖详情层，先用列表缓存渲染，再 Ajax 拉取完整详情 */
    function openMarketDetail(shell, appId) {
        if (!shell || !appId) {
            return;
        }
        closeMarketDetail(shell);
        var cached = Object.assign({ app_id: appId, _source: 'market' }, state.marketApps.get(appId) || {});
        var panel = document.createElement('section');
        panel.className = 'market-detail';
        panel.innerHTML = marketDetailMarkup(cached, marketDetailNotice('fa-circle-o-notch fa-spin', '正在读取应用详情…'));
        shell.appendChild(panel);
        syncMarketShots(panel);

        api('/api/admin/market/apps/' + encodeURIComponent(appId)).then(function (detail) {
            var app = Object.assign({}, cached, detail || {});
            app.app_id = appId;
            app._source = 'market';
            state.marketApps.set(appId, app);
            renderMarketDetail(shell, app, '');
        }).catch(function (error) {
            var message = error.message || '读取应用详情失败';
            renderMarketDetail(shell, cached, marketDetailNotice('fa-exclamation-circle', message, true));
            toast(message, 'error');
        });
    }

    /** 将详情内容写入覆盖层（面板已随 Tab 切换销毁时静默跳过） */
    function renderMarketDetail(shell, app, notice) {
        var panel = shell.querySelector('.market-detail');
        if (panel) {
            panel.innerHTML = marketDetailMarkup(app, notice);
            syncMarketShots(panel);
        }
    }

    /** 关闭详情覆盖层：列表 DOM、分页与滚动状态原样保留，并收起已打开的截图预览 */
    function closeMarketDetail(shell) {
        closeShotViewer();
        var panel = shell ? shell.querySelector('.market-detail') : null;
        if (panel) {
            panel.remove();
        }
    }

    /** 详情层提示条（加载中 / 读取失败） */
    function marketDetailNotice(icon, text, isError) {
        return '<div class="market-detail-notice' + (isError ? ' is-error' : '') + '"><i class="fa '
            + safeIcon(icon) + '"></i>' + escapeHtml(text) + '</div>';
    }

    /** 详情分区通用外壳 */
    function marketDetailSectionMarkup(icon, title, body) {
        return '<section class="market-detail-section"><h3><i class="fa ' + safeIcon(icon) + '"></i>'
            + escapeHtml(title) + '</h3>' + body + '</section>';
    }

    /** 详情页结构：返回条 + 头部 + 基本信息 + 应用介绍 + 应用截图 + 版本记录 */
    function marketDetailMarkup(app, notice) {
        var market = marketState(app);
        return '<header class="market-detail-bar">'
            + '<button class="market-detail-back" type="button" data-market-detail-close>'
            + '<i class="fa fa-arrow-left"></i>返回列表</button><span>应用详情</span></header>'
            + '<div class="market-detail-body">' + (notice || '')
            + marketDetailHeaderMarkup(app, market)
            + marketDetailMetaMarkup(app, market)
            + marketDetailDescMarkup(app)
            + marketDetailShotsMarkup(app)
            + marketDetailVersionsMarkup(app)
            + '</div>';
    }

    /** 详情头部：图标、名称、推荐标记、作者与文档链接、操作按钮 */
    function marketDetailHeaderMarkup(app, market) {
        var links = [];
        if (app.forum_url) {
            links.push('<a href="' + escapeHtml(app.forum_url) + '" target="_blank" rel="noopener noreferrer">应用文档</a>');
        }
        if (app.author) {
            links.push('作者 ' + (app.author_url
                ? '<a href="' + escapeHtml(app.author_url) + '" target="_blank" rel="noopener noreferrer">'
                    + escapeHtml(app.author) + '</a>'
                : escapeHtml(app.author)));
        }

        return '<div class="market-detail-header">' + marketIconMarkup(app, 'market-detail-icon')
            + '<div class="market-detail-identity"><h2>' + escapeHtml(app.name || app.app_id || '应用详情')
            + (Number(app.is_featured) === 1 ? '<em class="market-detail-badge">推荐</em>' : '') + '</h2>'
            + (links.length ? '<p>' + links.join('<i>·</i>') + '</p>' : '') + '</div>'
            + '<div class="market-detail-actions">' + marketDetailActionsMarkup(app, market) + '</div></div>';
    }

    /** 基本信息：分类、最新版本、价格、评分、下载量、访问量、系统要求与本地版本 */
    function marketDetailMetaMarkup(app, market) {
        var items = [];
        if (app.category && app.category.name) {
            items.push(['分类', escapeHtml(app.category.name)]);
        }
        items.push(['最新版本', 'v' + escapeHtml(market.latest_version || '-')]);
        items.push(['价格', Number(app.price) > 0
            ? '<span class="is-paid">¥' + Number(app.price).toFixed(2) + '</span>'
            : '<span class="is-free">免费</span>']);
        items.push(['评分', app.rating === null || app.rating === undefined || app.rating === ''
            ? '-' : '<span class="is-rating">★ ' + Number(app.rating).toFixed(1) + '</span>']);
        items.push(['下载量', String(Number(app.downloads) || 0)]);
        items.push(['访问量', String(Number(app.view_count) || 0)]);
        if (app.cmspro_require) {
            items.push(['系统要求', 'CmsPro ' + escapeHtml(app.cmspro_require)]);
        }
        if (market.installed) {
            items.push(['本地版本', '<span class="is-installed">v' + escapeHtml(market.current_version)
                + (market.has_update ? '（可更新）' : '（最新）') + '</span>']);
        }

        return marketDetailSectionMarkup('fa-info-circle', '基本信息', '<dl class="market-detail-meta">'
            + items.map(function (item) {
                return '<div><dt>' + item[0] + '</dt><dd>' + item[1] + '</dd></div>';
            }).join('') + '</dl>');
    }

    /** 应用介绍：远程描述为纯文本，转义后保留换行 */
    function marketDetailDescMarkup(app) {
        if (!app.description) {
            return '';
        }
        return marketDetailSectionMarkup('fa-file-text-o', '应用介绍', '<div class="market-detail-desc">'
            + escapeHtml(app.description).replace(/\n/g, '<br>') + '</div>');
    }

    /** 截图轨道单步滚动距离：一张缩略图宽度（220px）加间距（12px） */
    var SHOTS_STEP = 232;

    /** 全屏截图预览层状态，null 表示未打开：{ urls, index, element } */
    var shotViewer = null;

    /** 应用截图：优先应用级截图，缺失时回溯最近一个带截图的版本；单行展示，点击缩略图进入全屏预览 */
    function marketDetailShotsMarkup(app) {
        var urls = normalizeScreenshots(app.screenshots_groups || app.screenshots);
        if (!urls.length) {
            urls = versionScreenshots(app);
        }
        if (!urls.length) {
            return '';
        }

        var shots = urls.map(function (url, index) {
            return '<button class="market-detail-shot" type="button" data-shot-url="' + escapeHtml(url) + '"'
                + ' aria-label="查看第 ' + (index + 1) + ' 张截图">'
                + '<img data-market-shot src="' + escapeHtml(url) + '" alt="应用截图 ' + (index + 1) + '"'
                + ' loading="lazy"></button>';
        }).join('');

        return marketDetailSectionMarkup('fa-picture-o', '应用截图（' + urls.length + '）',
            '<div class="market-shots">' + marketShotsNavMarkup('prev', 'fa-angle-left', '上一张截图')
            + '<div class="market-detail-shots" data-shots-track>' + shots + '</div>'
            + marketShotsNavMarkup('next', 'fa-angle-right', '下一张截图') + '</div>');
    }

    /** 截图轨道左右切换按钮：初始禁用，由 syncMarketShots 依据实际溢出情况刷新 */
    function marketShotsNavMarkup(direction, icon, label) {
        return '<button class="market-shots-nav" type="button" data-shots-' + direction + ' aria-label="' + label
            + '" disabled><i class="fa ' + icon + '"></i></button>';
    }

    /** 绑定截图轨道滚动监听并初始化箭头可用态（详情层每次重写 innerHTML 后调用） */
    function syncMarketShots(panel) {
        var track = panel ? panel.querySelector('[data-shots-track]') : null;
        if (!track) {
            return;
        }
        track.addEventListener('scroll', function () {
            updateMarketShotsNav(track);
        });
        updateMarketShotsNav(track);
    }

    /** 按方向滚动截图轨道一步（平滑滚动结束后由 scroll 监听刷新箭头） */
    function scrollMarketShots(track, direction) {
        if (!track) {
            return;
        }
        track.scrollLeft += direction * SHOTS_STEP;
        updateMarketShotsNav(track);
    }

    /** 刷新截图轨道左右箭头：滚动到边界或内容未溢出时禁用对应方向 */
    function updateMarketShotsNav(track) {
        var wrap = track.parentNode;
        var prev = wrap.querySelector('[data-shots-prev]');
        var next = wrap.querySelector('[data-shots-next]');
        var maxScroll = track.scrollWidth - track.clientWidth;
        if (prev) {
            prev.disabled = track.scrollLeft <= 1;
        }
        if (next) {
            next.disabled = track.scrollLeft >= maxScroll - 1;
        }
    }

    /** 从缩略图打开全屏预览：取当前轨道内的有效截图（加载失败项已被移除）并定位到点击项 */
    function openShotViewerFrom(button) {
        var track = button.closest('[data-shots-track]');
        if (!track) {
            return;
        }
        var items = Array.prototype.slice.call(track.querySelectorAll('[data-shot-url]'));
        openShotViewer(items.map(function (item) {
            return item.dataset.shotUrl;
        }), items.indexOf(button));
    }

    /** 打开全屏截图预览层：挂载到 body，避免受详情层与窗口的层叠上下文限制 */
    function openShotViewer(urls, index) {
        closeShotViewer();
        if (!Array.isArray(urls) || !urls.length) {
            return;
        }
        var element = document.createElement('div');
        element.className = 'shot-viewer';
        element.setAttribute('data-shot-viewer', '');
        element.innerHTML = shotViewerMarkup(urls);
        document.body.appendChild(element);
        shotViewer = { urls: urls, index: Math.max(0, Math.min(index || 0, urls.length - 1)), element: element };
        renderShotViewer();
    }

    /** 预览层骨架：遮罩、关闭按钮、左右切换按钮与图片舞台（舞台内容由 renderShotViewer 填充） */
    function shotViewerMarkup(urls) {
        var single = urls.length < 2 ? ' disabled' : '';
        return '<div class="shot-viewer-mask" data-shot-viewer-close></div>'
            + '<button class="shot-viewer-close" type="button" data-shot-viewer-close aria-label="关闭预览">'
            + '<i class="fa fa-times"></i></button>'
            + '<button class="shot-viewer-nav" type="button" data-shot-viewer-prev aria-label="上一张"' + single
            + '><i class="fa fa-angle-left"></i></button>'
            + '<figure class="shot-viewer-stage" data-shot-viewer-stage></figure>'
            + '<button class="shot-viewer-nav" type="button" data-shot-viewer-next aria-label="下一张"' + single
            + '><i class="fa fa-angle-right"></i></button>';
    }

    /** 渲染当前截图：整块重写舞台，使加载失败占位在下次切换时自动消失 */
    function renderShotViewer() {
        var stage = shotViewer ? shotViewer.element.querySelector('[data-shot-viewer-stage]') : null;
        if (!stage) {
            return;
        }
        stage.innerHTML = '<img data-shot-viewer-image src="' + escapeHtml(shotViewer.urls[shotViewer.index]) + '"'
            + ' alt="应用截图预览"><figcaption>' + (shotViewer.index + 1) + ' / ' + shotViewer.urls.length
            + '</figcaption>';
    }

    /** 循环切换到上一张 / 下一张截图 */
    function stepShotViewer(step) {
        if (!shotViewer) {
            return;
        }
        var total = shotViewer.urls.length;
        shotViewer.index = (shotViewer.index + step + total) % total;
        renderShotViewer();
    }

    /** 关闭全屏截图预览层 */
    function closeShotViewer() {
        if (shotViewer) {
            shotViewer.element.remove();
            shotViewer = null;
        }
    }

    /** 归一化截图数据：兼容纯 URL 数组与 [{ name, images: [] }] 分组结构 */
    function normalizeScreenshots(source) {
        if (!Array.isArray(source) || !source.length) {
            return [];
        }
        var urls = [];
        source.forEach(function (item) {
            if (typeof item === 'string') {
                urls.push(marketIconUrl(item));
                return;
            }
            ((item && Array.isArray(item.images)) ? item.images : []).forEach(function (url) {
                urls.push(marketIconUrl(url));
            });
        });
        return urls;
    }

    /** 按发布时间从新到旧回溯，返回首个带截图版本的截图列表 */
    function versionScreenshots(app) {
        var versions = Array.isArray(app.versions) ? app.versions.slice() : [];
        versions.sort(function (left, right) {
            return String(right.create_time || '').localeCompare(String(left.create_time || ''));
        });
        for (var index = 0; index < versions.length; index += 1) {
            var urls = normalizeScreenshots(versions[index].screenshots_groups || versions[index].screenshots);
            if (urls.length) {
                return urls;
            }
        }
        return [];
    }

    /** 版本记录表格：版本号、更新说明、包大小、发布时间 */
    function marketDetailVersionsMarkup(app) {
        var versions = Array.isArray(app.versions) ? app.versions : [];
        if (!versions.length) {
            return '';
        }

        return marketDetailSectionMarkup('fa-history', '版本记录', '<table class="market-detail-versions">'
            + '<thead><tr><th>版本</th><th>更新说明</th><th>大小</th><th>发布时间</th></tr></thead><tbody>'
            + versions.map(function (item) {
                return '<tr><td>v' + escapeHtml(item.version || '-') + '</td><td>' + escapeHtml(item.changelog || '-')
                    + '</td><td>' + formatFileSize(item.package_size) + '</td><td>'
                    + escapeHtml(item.create_time || '-') + '</td></tr>';
            }).join('') + '</tbody></table>');
    }

    /** 详情操作按钮：沿用列表卡片的 data-upgrade-id / data-install-id 点击委托 */
    function marketDetailActionsMarkup(app, market) {
        var id = escapeHtml(app.app_id || '');
        if (market.has_update) {
            return '<button class="webos-button primary" type="button" data-upgrade-id="' + id + '">更新</button>';
        }
        if (market.installed) {
            return '<button class="webos-button secondary" type="button" disabled>已安装</button>';
        }
        return '<button class="webos-button primary" type="button" data-install-id="' + id
            + '" data-install-source="market">安装</button>';
    }

    function findCatalogApp(appId) {
        return state.catalog.applications.find(function (app) {
            return app.app_id === appId;
        }) || null;
    }

    /** 打开本地应用详情（已安装/未安装/应用更新列表点击应用名称）：复用市场详情布局与返回按钮 */
    function openLocalDetail(shell, appId, source) {
        if (!shell || !appId) {
            return;
        }
        var app = findLocalApp(appId, source);
        if (!app) {
            return;
        }
        closeMarketDetail(shell);
        var panel = document.createElement('section');
        panel.className = 'market-detail';
        panel.innerHTML = localDetailMarkup(app, source || 'installed');
        shell.appendChild(panel);
    }

    /** 查找本地应用数据：按打开来源取对应列表，取不到时回退已安装目录 */
    function findLocalApp(appId, source) {
        var lower = String(appId || '').toLowerCase();
        var match = function (app) { return String(app.app_id || '').toLowerCase() === lower; };
        if (source === 'local') {
            return state.marketApps.get(appId) || findCatalogApp(appId);
        }
        if (source === 'updates') {
            return (state.updateApps || []).find(match) || findCatalogApp(appId);
        }
        return findCatalogApp(appId);
    }

    /** 本地应用详情主体：基本信息仅展示本地字段（版本/作者/状态/系统应用），无市场指标与截图 */
    function localDetailMarkup(app, source) {
        return '<header class="market-detail-bar">'
            + '<button class="market-detail-back" type="button" data-market-detail-close>'
            + '<i class="fa fa-arrow-left"></i>返回列表</button><span>应用详情</span></header>'
            + '<div class="market-detail-body">'
            + localDetailHeaderMarkup(app, source)
            + localDetailMetaMarkup(app, source)
            + marketDetailDescMarkup(app)
            + '</div>';
    }

    /** 本地详情头部：图标走全局应用图标链（icon_url → icon 字段），操作按钮复用安装/更新点击委托 */
    function localDetailHeaderMarkup(app, source) {
        var id = escapeHtml(app.app_id || '');
        var links = [];
        if (app.forum_url) {
            links.push('<a href="' + escapeHtml(app.forum_url) + '" target="_blank" rel="noopener noreferrer">应用文档</a>');
        }
        if (app.author) {
            links.push('作者 ' + escapeHtml(app.author));
        }

        return '<div class="market-detail-header">' + applicationIconMarkup(app, 'market-detail-icon')
            + '<div class="market-detail-identity"><h2>' + escapeHtml(app.name || app.app_id || '应用详情') + '</h2>'
            + (links.length ? '<p>' + links.join('<i>·</i>') + '</p>' : '') + '</div>'
            + '<div class="market-detail-actions">' + localDetailActionMarkup(app, source) + '</div></div>';
    }

    /** 本地详情操作按钮：未安装提供安装、更新列表提供更新、已安装仅展示状态 */
    function localDetailActionMarkup(app, source) {
        var id = escapeHtml(app.app_id || '');
        if (source === 'local') {
            return '<button class="webos-button primary" type="button" data-install-id="' + id
                + '" data-install-source="local">安装</button>';
        }
        if (source === 'updates') {
            return '<button class="webos-button primary" type="button" data-upgrade-id="' + id + '">更新</button>';
        }
        return '<button class="webos-button secondary" type="button" disabled>已安装</button>';
    }

    /** 本地详情基本信息：未安装应用不显示状态行，已安装应用显示启用状态 */
    function localDetailMetaMarkup(app, source) {
        var items = [['当前版本', 'v' + escapeHtml(app.version || '-')]];
        if (app.author) {
            items.push(['作者', escapeHtml(app.author)]);
        }
        if (source !== 'local') {
            items.push(['应用状态', Number(app.status) === 1
                ? '<span class="is-installed">已启用</span>'
                : '已禁用']);
        }
        if (app.is_system !== undefined && app.is_system !== null) {
            items.push(['系统应用', app.is_system ? '是' : '否']);
        }

        return marketDetailSectionMarkup('fa-info-circle', '基本信息', '<dl class="market-detail-meta">'
            + items.map(function (item) {
                return '<div><dt>' + item[0] + '</dt><dd>' + item[1] + '</dd></div>';
            }).join('') + '</dl>');
    }

    function renderInstalledSummary(status, apps) {
        var enabled = (apps || []).filter(function (app) { return Number(app.status) === 1; }).length;
        status.innerHTML = '<i class="fa fa-check-circle"></i>共 ' + (apps || []).length + ' 个应用 · ' + enabled + ' 个已启用';
    }

    function appHasMenu(appId) {
        return state.flatMenus.some(function (entry) { return entry.app_id === appId; });
    }

    function installedRowMarkup(app) {
        var appId = app.app_id || '';
        var appName = app.name || appId;
        var description = app.description || '暂无应用说明';
        var enabled = Number(app.status) === 1;
        var hasMenu = appHasMenu(appId);
        var actions = '<button class="small-action primary" type="button" data-open-app-id="' + escapeHtml(appId) + '"'
            + (hasMenu ? '' : ' disabled') + '>打开</button>'
            + '<button class="small-action" type="button" data-app-action="manual-upgrade" data-app-id="'
            + escapeHtml(appId) + '">手动升级</button>'
            + '<button class="small-action more" type="button" data-app-more="' + escapeHtml(appId)
            + '" aria-expanded="false" aria-label="更多操作" title="更多操作"><i class="fa fa-ellipsis-h"></i></button>';

        return '<article class="install-row" data-app-id="' + escapeHtml(appId) + '" data-app-state="' + escapeHtml(String(app.status))
            + '" data-app-name="' + escapeHtml((app.name || appId).toLowerCase()) + '">'
            + '<div class="install-row-app">' + cardIconMarkup(app, 'app-icon') + '<div class="app-card-info">'
            + '<strong class="app-card-title" data-local-detail="' + escapeHtml(appId) + '" data-local-source="installed" title="'
            + escapeHtml(appName) + '">' + escapeHtml(appName) + '</strong><span title="'
            + escapeHtml(description) + '">' + escapeHtml(description) + '</span><small>版本 '
            + escapeHtml(app.version || '-') + (app.is_system ? ' · 系统应用' : '') + '</small></div></div>'
            + '<div class="install-row-cell">' + statusSwitchMarkup(appId, enabled) + '</div>'
            + '<div class="install-row-actions">' + actions + '</div></article>';
    }

    function statusSwitchMarkup(appId, enabled) {
        var text = enabled ? '已启用' : '已禁用';

        return '<button class="status-switch" type="button" data-toggle-app-status="' + escapeHtml(appId)
            + '" aria-pressed="' + (enabled ? 'true' : 'false') + '" aria-label="' + escapeHtml(text)
            + '" title="' + escapeHtml(text) + '"><i></i></button>';
    }

    function setStatusToggleBusy(button, busy) {
        if (!button) {
            return;
        }
        if (!button.dataset.idleTitle) {
            button.dataset.idleTitle = button.title || '';
        }
        button.disabled = busy;
        button.setAttribute('aria-busy', busy ? 'true' : 'false');
        button.title = busy ? '正在保存应用状态…' : button.dataset.idleTitle;
    }

    // 取应用参与排序的时间：安装时间与更新时间中较新者（与后台应用管理默认排序一致）
    function installedAppSortTime(app) {
        var installTime = app.install_time || '';
        var updateTime = app.update_time || '';
        return installTime > updateTime ? installTime : updateTime;
    }

    // 已安装列表按安装/更新时间降序（最新在前），无时间记录排最后
    function sortInstalledApps(apps) {
        return (apps || []).slice().sort(function (left, right) {
            var leftTime = installedAppSortTime(left);
            var rightTime = installedAppSortTime(right);
            if (leftTime === rightTime) {
                return 0;
            }
            if (!leftTime) {
                return 1;
            }
            if (!rightTime) {
                return -1;
            }
            return leftTime < rightTime ? 1 : -1;
        });
    }

    function renderInstalledList(apps) {
        apps = sortInstalledApps(apps);
        if (!apps || !apps.length) {
            return emptyState('fa-cubes', '暂无已安装应用');
        }

        return '<div class="app-install-list">'
            + '<div class="install-row install-row-head"><span>应用信息</span><span>状态</span><span>操作</span></div>'
            + apps.map(installedRowMarkup).join('') + '</div>';
    }

    function closeAppRowMenus() {
        var focusTarget = null;
        document.querySelectorAll('.row-menu').forEach(function (menu) {
            if (menu.contains(document.activeElement)) {
                var row = menu.closest('.install-row');
                focusTarget = row ? row.querySelector('[data-app-more]') : null;
            }
            menu.remove();
        });
        document.querySelectorAll('[data-app-more]').forEach(function (button) { button.setAttribute('aria-expanded', 'false'); });
        if (focusTarget && focusTarget.isConnected) {
            focusTarget.focus();
        }
    }

    function appRowMenuItems(app) {
        var items = [];
        if (appHasMenu(app.app_id)) {
            items.push(['manage-entry', 'fa-th', '管理入口']);
        }
        items.push(['export', 'fa-download', '导出'], ['backup', 'fa-archive', '备份'], ['docs', 'fa-book', '文档']);
        // 与系统应用管理一致：manifest 声明了 config_groups 才提供「设置」入口
        if (app.manifest && app.manifest.config_groups && app.manifest.config_groups.length) {
            items.push(['settings', 'fa-cog', '设置']);
        }
        if (Number(app.is_system) !== 1) {
            items.push(['uninstall', 'fa-trash-o', '卸载', 'danger']);
        }
        return items;
    }

    function toggleAppRowMenu(button) {
        var row = button.closest('.install-row');
        var opened = row ? row.querySelector('.row-menu') : null;
        closeAppRowMenus();
        if (opened || !row) {
            return;
        }
        var app = findCatalogApp(button.dataset.appMore);
        if (!app) {
            return;
        }
        var menu = document.createElement('div');
        menu.className = 'row-menu';
        menu.innerHTML = appRowMenuItems(app).map(function (item) {
            return '<button class="row-menu-item ' + (item[3] ? 'is-danger' : '') + '" type="button" data-app-action="'
                + item[0] + '" data-app-id="' + escapeHtml(app.app_id) + '"><i class="fa ' + item[1] + '"></i>'
                + item[2] + '</button>';
        }).join('');
        row.appendChild(menu);
        // 行位于应用中心底部时菜单向下展开会被窗口裁剪，改为向上弹出
        var buttonRect = button.getBoundingClientRect();
        var menuRect = menu.getBoundingClientRect();
        if (buttonRect.bottom + menuRect.height + 8 > window.innerHeight - 12) {
            menu.classList.add('is-above');
        }
        button.setAttribute('aria-expanded', 'true');
    }

    /** 安装记录当前选中的日志序号（右侧操作详情） */
    var recordsSelectedIndex = 0;

    /** 操作类型文案与徽标样式映射（result=1 成功时展示 done 文案） */
    var RECORD_OPERATION_META = {
        install: { label: '安装应用', done: '安装成功', icon: 'fa-download' },
        upgrade: { label: '更新应用', done: '更新成功', icon: 'fa-refresh' },
        uninstall: { label: '卸载应用', done: '已卸载', icon: 'fa-trash-o' },
        enable: { label: '启用应用', done: '已启用', icon: 'fa-check-circle-o' },
        disable: { label: '禁用应用', done: '已禁用', icon: 'fa-minus-circle' },
        export: { label: '导出应用', done: '导出成功', icon: 'fa-share-square-o' }
    };

    /**
     * 安装记录 Tab：顶部工具栏（搜索 + 类型筛选）+ 日期分组列表 + 右侧操作详情
     */
    function loadRecordsTab(content, status, operation) {
        state.recordsOperation = operation || '';
        status.innerHTML = '<i class="fa fa-history"></i>正在读取安装记录';
        content.innerHTML = emptyState('fa-history', '正在读取应用操作记录');
        var query = '/api/admin/app-logs?per_page=30' + (state.recordsOperation ? '&operation=' + encodeURIComponent(state.recordsOperation) : '');
        api(query).then(function (payload) {
            state.recordsLogs = extractCollection(payload);
            recordsSelectedIndex = 0;
            state.recordsKeyword = '';
            // 搜索已移至右上角全局搜索框：重置关键字时同步清空，保持显示与过滤一致
            var searchInput = content.closest('[data-app-center]');
            searchInput = searchInput ? searchInput.querySelector('[data-app-search]') : null;
            if (searchInput) {
                searchInput.value = '';
            }
            status.innerHTML = '<i class="fa fa-history"></i>显示最近 ' + state.recordsLogs.length + ' 条应用操作'
                + (state.recordsOperation ? '（已按类型筛选）' : '');
            content.innerHTML = renderRecordsLayout(state.recordsLogs);
            fillRecordAppInfo();
        }).catch(function (error) {
            status.classList.add('is-error');
            status.innerHTML = '<i class="fa fa-exclamation-circle"></i>' + escapeHtml(error.message);
            content.innerHTML = emptyState('fa-history', '暂时无法读取应用操作记录');
        });
    }

    /** 日志记录的操作元信息（未知类型兜底） */
    function recordMeta(log) {
        return RECORD_OPERATION_META[log.operation] || { label: log.operation, done: '操作成功', icon: 'fa-cube' };
    }

    /** 日志记录的应用名称：优先应用中心目录，其次市场缓存（含未安装/已卸载应用），最后回退应用标识 */
    function recordAppName(appId) {
        var app = findCatalogApp(appId) || state.marketApps.get(appId) || null;
        return (app && (app.name || app.title)) || appId;
    }

    /**
     * 日志记录的应用图标：按全局优先级 icon.svg → icon.png → manifest.json(icon) → 通用占位。
     * 主图直连服务端图标接口（/api/app/{id}/icon，服务端按 icon.svg → icon.png 顺序返回），
     * 不依赖前端目录加载状态；失败时按全局兜底机制降级到 manifest 的 icon 字段，最终回退通用占位。
     */
    function recordIcon(appId) {
        var app = findCatalogApp(appId) || state.marketApps.get(appId) || null;
        var icon = (app && app.icon) || '';
        // 市场来源的 icon 为市场相对路径时补全为完整 URL（本地应用 manifest 的 icon 无需处理）
        if (app && app._source === 'market' && icon && !/^https?:\/\//i.test(icon)) {
            icon = marketIconUrl(icon);
        }
        var inner = '<img data-app-icon-primary src="/api/app/' + encodeURIComponent(appId) + '/icon" decoding="async" alt="">';
        if (icon && isImageIcon(icon)) {
            inner += '<img data-app-icon-fallback hidden data-src="' + escapeHtml(icon) + '" decoding="async" alt="">'
                + '<i data-app-icon-final hidden class="fa fa-cube"></i>';
        } else {
            inner += '<i data-app-icon-fallback hidden class="' + safeIcon(icon || 'fa fa-cube') + '"></i>';
        }
        return '<span class="record-row-icon-inner">' + inner + '</span>';
    }

    /** 日志版本展示：版本变化 from → to，仅单版本时直接显示 */
    function recordVersion(log) {
        var from = String(log.version_from || '');
        var to = String(log.version_to || '');
        if (from && to && from !== to) {
            return from + ' → ' + to;
        }
        return to || from || '-';
    }

    /** 渲染安装记录双栏布局（左侧分组列表 + 右侧详情） */
    function renderRecordsLayout(logs) {
        if (!logs || !logs.length) {
            return emptyState('fa-file-text-o', '暂无应用操作记录');
        }
        return '<div class="records-layout">'
            + '<div class="records-side">'
            + '<div class="records-groups" data-records-groups>' + renderRecordGroups(logs) + '</div>'
            + '</div>'
            + '<aside class="records-detail" data-records-detail>' + renderRecordDetail(logs[recordsSelectedIndex] || null) + '</aside>'
            + '</div>';
    }

    /** 操作类型筛选下拉选项 */
    function recordOperationOptions(selected) {
        var options = [['', '全部结果']].concat(Object.keys(RECORD_OPERATION_META).map(function (key) {
            return [key, RECORD_OPERATION_META[key].label];
        }));
        return options.map(function (pair) {
            return '<option value="' + pair[0] + '"' + (pair[0] === (selected || '') ? ' selected' : '') + '>' + escapeHtml(pair[1]) + '</option>';
        }).join('');
    }

    /** 安装记录日期分组列表（今天 / 昨天 / 更早），按关键字过滤应用名称/标识 */
    function renderRecordGroups(logs) {
        var keyword = String(state.recordsKeyword || '').toLowerCase();
        var filtered = logs.map(function (log, index) { return { log: log, index: index }; }).filter(function (entry) {
            if (!keyword) {
                return true;
            }
            // 同时匹配应用名称与应用标识（名称走目录/市场缓存兜底链）
            return String(entry.log.app_id || '').toLowerCase().indexOf(keyword) >= 0
                || String(recordAppName(entry.log.app_id) || '').toLowerCase().indexOf(keyword) >= 0;
        });
        if (!filtered.length) {
            return emptyState('fa-search', '没有匹配的操作记录');
        }
        var today = new Date();
        var yesterday = new Date(today.getTime() - 86400000);
        var pad = function (n) { return (n < 10 ? '0' : '') + n; };
        var todayStamp = pad(today.getMonth() + 1) + '-' + pad(today.getDate());
        var yesterdayStamp = pad(yesterday.getMonth() + 1) + '-' + pad(yesterday.getDate());
        var groups = [];
        filtered.forEach(function (entry) {
            var match = String(entry.log.create_time || '').match(/^\d{4}-(\d{2}-\d{2}) (\d{2}:\d{2})/);
            var label = '更早';
            if (match) {
                label = match[1] === todayStamp ? '今天' : (match[1] === yesterdayStamp ? '昨天' : '更早');
                entry.time = match[2];
            } else {
                entry.time = '';
            }
            var last = groups[groups.length - 1];
            if (last && last.label === label) {
                last.items.push(entry);
            } else {
                groups.push({ label: label, items: [entry] });
            }
        });
        return groups.map(function (group) {
            return '<div class="records-group"><h5>' + group.label + '</h5>'
                + group.items.map(function (entry) {
                    var log = entry.log;
                    var meta = recordMeta(log);
                    var failed = Number(log.result) !== 1;
                    var operator = log.operator ? (log.operator.name || log.operator.username) : '-';
                    return '<button type="button" class="record-row' + (entry.index === recordsSelectedIndex ? ' is-selected' : '') + '" data-record-index="' + entry.index + '">'
                        + '<span class="record-row-icon">' + recordIcon(log.app_id) + '</span>'
                        + '<span class="record-row-body"><strong>' + escapeHtml(recordAppName(log.app_id)) + '</strong>'
                        + '<small>' + escapeHtml(meta.label) + ' · ' + escapeHtml(recordVersion(log)) + '</small></span>'
                        + '<span class="record-pill ' + (failed ? 'record-pill--failed' : 'record-pill--done') + '">'
                        + '<i class="fa ' + (failed ? 'fa-times-circle' : 'fa-check-circle') + '"></i>' + (failed ? '失败' : meta.done) + '</span>'
                        + '<span class="record-row-operator">' + escapeHtml(operator) + '</span>'
                        + '<time>' + escapeHtml(entry.time || '-') + '</time></button>';
                }).join('') + '</div>';
        }).join('');
    }

    /** 右侧操作详情：应用概要 + 版本变化 / 操作人 / 开始时间 / 操作结果，失败时展示错误信息 */
    function renderRecordDetail(log) {
        if (!log) {
            return emptyState('fa-file-text-o', '选择左侧记录查看操作详情');
        }
        var meta = recordMeta(log);
        var failed = Number(log.result) !== 1;
        var operator = log.operator ? (log.operator.name || log.operator.username) : '-';
        return '<div class="record-detail-head"><span class="record-row-icon">' + recordIcon(log.app_id) + '</span>'
            + '<div><strong>' + escapeHtml(recordAppName(log.app_id)) + '</strong><small>' + escapeHtml(log.app_id) + ' · ' + escapeHtml(meta.label) + '</small></div></div>'
            + '<dl class="record-detail-fields">'
            + '<div><dt>目标版本</dt><dd>' + escapeHtml(log.version_to || '-') + '</dd></div>'
            + '<div><dt>版本变化</dt><dd>' + escapeHtml(recordVersion(log)) + '</dd></div>'
            + '<div><dt>操作人</dt><dd>' + escapeHtml(operator) + '</dd></div>'
            + '<div><dt>开始时间</dt><dd>' + escapeHtml(log.create_time || '-') + '</dd></div>'
            + '<div><dt>操作结果</dt><dd class="' + (failed ? 'is-failed' : 'is-success') + '">' + (failed ? '失败' : meta.done) + '</dd></div>'
            + '</dl>'
            + (failed && log.error_message ? '<p class="record-detail-error"><i class="fa fa-exclamation-circle"></i>' + escapeHtml(log.error_message) + '</p>' : '');
    }

    /** 重绘安装记录列表与详情（搜索过滤 / 选中变化时局部更新，保留工具栏焦点） */
    function refreshRecordsPanels(center) {
        var groups = center.querySelector('[data-records-groups]');
        var detail = center.querySelector('[data-records-detail]');
        if (!groups || !detail) {
            return;
        }
        groups.innerHTML = renderRecordGroups(state.recordsLogs || []);
        detail.innerHTML = renderRecordDetail((state.recordsLogs || [])[recordsSelectedIndex] || null);
    }

    /**
     * 补齐安装记录中目录缺失的应用信息（名称/图标）：
     * 已卸载应用不在应用中心目录（apps 表）中，名称会回退显示应用标识。
     * 第一层用未安装应用接口（本地文件还在的应用）补市场缓存，
     * 第二层对仍缺失的逐个拉取市场详情（远程上架应用），
     * 补齐后局部重渲染记录列表与详情（面板已关闭时静默跳过）。
     */
    function fillRecordAppInfo() {
        var missing = [];
        var seen = {};
        (state.recordsLogs || []).forEach(function (log) {
            var appId = String(log.app_id || '');
            if (!appId || seen[appId]) {
                return;
            }
            seen[appId] = true;
            if (!findCatalogApp(appId) && !state.marketApps.get(appId)) {
                missing.push(appId);
            }
        });
        if (!missing.length) {
            return;
        }
        api('/api/admin/apps/available').then(function (payload) {
            ((payload && payload.all) || []).forEach(function (app) {
                if (app && app.app_id && !findCatalogApp(app.app_id) && !state.marketApps.get(app.app_id)) {
                    app._source = 'local';
                    state.marketApps.set(app.app_id, app);
                }
            });
            var remote = missing.filter(function (appId) { return !state.marketApps.get(appId); });
            return Promise.all(remote.map(function (appId) {
                return api('/api/admin/market/apps/' + encodeURIComponent(appId)).then(function (detail) {
                    if (detail) {
                        detail.app_id = appId;
                        detail._source = 'market';
                        state.marketApps.set(appId, detail);
                    }
                }).catch(function () {});
            }));
        }).catch(function () {}).then(function () {
            var groups = document.querySelector('[data-records-groups]');
            var center = groups ? groups.closest('[data-app-center]') : null;
            if (center) {
                refreshRecordsPanels(center);
            }
        });
    }

    /** 入口管理树中已展开的节点 key；默认全部收起 */
    var entryTreeExpanded = new Set();

    /** 应用市场搜索防抖计时器 */
    var marketSearchTimer = 0;
    /** 任务栏图标拖动刚结束标志：吞掉松开后的一次 click，避免误启动入口 */
    var taskbarDragJustEnded = false;
    var marketRequestSequence = 0;
    var marketRequestController = null;

    /** 取消当前市场请求并递增序号，使不支持 AbortController 的环境也会丢弃迟到响应 */
    function cancelMarketRequest() {
        marketRequestSequence += 1;
        if (marketRequestController) {
            marketRequestController.abort();
            marketRequestController = null;
        }
    }

    /** 重置市场查询并立即取消旧请求，单调序号用于无 AbortController 环境的竞态兜底 */
    function resetMarketPager(category, keyword) {
        cancelMarketRequest();
        state.marketPager = {
            category: category || '',
            keyword: keyword || '',
            page: 0,
            lastPage: 1,
            total: 0,
            loading: false,
            failed: false,
            token: marketRequestSequence
        };
    }

    function entryLeafOf(item, pinnedIds) {
        return {
            type: 'leaf',
            key: 'menu-' + item.id,
            title: item.name || '未命名菜单',
            icon: safeIcon(item.icon),
            path: typeof item.path === 'string' ? item.path : '',
            pinned: pinnedIds.has('menu-' + item.id)
        };
    }

    /**
     * 构建入口管理“可用菜单”树：
     * 应用菜单按 app_id 聚合为应用节点（文件夹图标），系统菜单保留原生层级分支；叶子显示菜单自身图标。
     */
    function buildEntryTree() {
        var pinnedIds = new Set(state.workspace.desktop_items.map(function (item) { return item.id; }));
        var appIndex = new Map();
        var appNodes = [];
        var plainRoots = [];

        function appNodeOf(appId) {
            if (appIndex.has(appId)) {
                return appIndex.get(appId);
            }
            var application = findApplication(appId);
            var node = {
                type: 'app',
                key: 'app:' + appId,
                title: application ? application.name : appId,
                application: application,
                icon: 'fa fa-folder',
                children: []
            };
            appIndex.set(appId, node);
            appNodes.push(node);
            return node;
        }

        function walk(items, inheritedAppId) {
            (items || []).forEach(function (item) {
                var children = Array.isArray(item.children) ? item.children : [];
                var appId = item.app_id || inheritedAppId || '';
                if (appId) {
                    // 应用节点首次创建时其根菜单子项直接铺开，避免多出一层与应用同名的分组
                    var fresh = !appIndex.has(appId);
                    collectAppMenu(appNodeOf(appId), item, children, !fresh);
                    return;
                }
                if (!children.length) {
                    if (safePath(item.path)) {
                        plainRoots.push(entryLeafOf(item, pinnedIds));
                    }
                    return;
                }
                var branch = { type: 'branch', key: 'branch:' + String(item.id || item.name), title: item.name || '未命名分组', icon: safeIcon(item.icon || 'fa fa-folder'), children: [] };
                collectBranch(children, branch, pinnedIds);
                if (branch.children.length) {
                    plainRoots.push(branch);
                }
            });
        }

        function collectBranch(items, branch, pinnedIds) {
            (items || []).forEach(function (item) {
                var children = Array.isArray(item.children) ? item.children : [];
                var appId = item.app_id || '';
                if (appId) {
                    walk([item], appId);
                    return;
                }
                if (!children.length) {
                    if (safePath(item.path)) {
                        branch.children.push(entryLeafOf(item, pinnedIds));
                    }
                    return;
                }
                var sub = { type: 'branch', key: 'branch:' + String(item.id || item.name), title: item.name || '未命名分组', icon: safeIcon(item.icon || 'fa fa-folder'), children: [] };
                collectBranch(children, sub, pinnedIds);
                if (sub.children.length) {
                    branch.children.push(sub);
                }
            });
        }

        /**
         * 收集应用菜单：叶子直接挂到应用节点下，应用内部的多级分组保留原有层级。
         */
        function collectAppMenu(parent, item, children, nested) {
            if (!children.length) {
                if (safePath(item.path)) {
                    parent.children.push(entryLeafOf(item, pinnedIds));
                }
                return;
            }
            if (!nested) {
                collectBranch(children, parent, pinnedIds);
                return;
            }
            var sub = { type: 'branch', key: 'branch:' + String(item.id || item.name), title: item.name || '未命名分组', icon: safeIcon(item.icon || 'fa fa-folder'), children: [] };
            collectBranch(children, sub, pinnedIds);
            if (sub.children.length) {
                parent.children.push(sub);
            }
        }

        walk(state.catalog.menus || [], '');
        return appNodes.concat(plainRoots);
    }

    function entryTreeNodeMarkup(node, depth) {
        var indent = ' style="margin-left:' + (depth * 20) + 'px"';
        if (node.type === 'leaf') {
            return '<div class="entry-row is-tree-leaf"' + indent + ' data-entry-name="' + escapeHtml(node.title.toLowerCase()) + '">'
                + entryIconMarkup(node, 'entry-row-icon')
                + '<div class="entry-row-info"><strong>' + escapeHtml(node.title) + '</strong><small>' + escapeHtml(node.path || '') + '</small></div>'
                + '<div class="entry-row-actions">'
                + (node.pinned ? '<span class="entry-pinned-tag">已固定</span>' : '<button class="small-action" type="button" data-add-entry-id="' + escapeHtml(node.key) + '">添加到桌面</button>')
                + '</div></div>';
        }
        var expanded = entryTreeExpanded.has(node.key);
        var nodeIcon = node.type === 'app' && node.application
            ? applicationIconMarkup(node.application, 'entry-tree-app-icon')
            : '<span class="start-app-item-icon"><i class="' + node.icon + '"></i></span>';
        return '<button class="entry-tree-toggle"' + indent + ' type="button" data-entry-branch="' + escapeHtml(node.key) + '" aria-expanded="' + (expanded ? 'true' : 'false') + '">'
            + '<i class="fa ' + (expanded ? 'fa-chevron-down' : 'fa-chevron-right') + '"></i>'
            + nodeIcon
            + '<strong>' + escapeHtml(node.title) + '</strong>'
            + '<small>' + node.children.length + ' 项</small></button>'
            + (expanded ? node.children.map(function (child) { return entryTreeNodeMarkup(child, depth + 1); }).join('') : '');
    }

    function renderAvailableMenusTree() {
        var tree = buildEntryTree();
        if (!tree.length) {
            return emptyState('fa-check-circle', '所有菜单都已固定');
        }
        return tree.map(function (node) { return entryTreeNodeMarkup(node, 0); }).join('');
    }

    function renderEntryManager() {
        var desktop = state.workspace.desktop_items.map(function (item) {
            var entryPath = typeof item.path === 'string' ? item.path : '';
            return '<div class="entry-row" data-entry-name="' + escapeHtml(item.title.toLowerCase()) + '">'
                + entryIconMarkup(findEntry(item.id) || item, 'entry-row-icon')
                + '<div class="entry-row-info"><strong>' + escapeHtml(item.title) + '</strong><small>'
                + escapeHtml(entryPath) + '</small></div><div class="entry-row-actions"><button class="small-action" type="button" data-launch-id="'
                + escapeHtml(item.id) + '">打开</button><button class="small-action" type="button" data-remove-entry-id="' + escapeHtml(item.id) + '">移除</button></div></div>';
        });
        return '<div class="entry-list"><h3>桌面入口</h3>' + (desktop.join('') || emptyState('fa-desktop', '桌面暂无快捷方式'))
            + '<h3>可用菜单</h3>' + renderAvailableMenusTree() + '</div>';
    }

    function emptyState(icon, text) {
        return '<div class="panel-empty"><i class="fa ' + safeIcon(icon) + '"></i>' + escapeHtml(text) + '</div>';
    }

    var INSTALL_TERMINALS = ['admin', 'user', 'home'];
    var INSTALL_TERMINAL_LABELS = { admin: '后台', user: '用户端', home: '前端' };
    var INSTALL_TERMINAL_ICONS = { admin: 'fa-cogs', user: 'fa-user-circle-o', home: 'fa-home' };

    function renderInstallMenuStatus(icon, message, isError) {
        elements.installParents.innerHTML = '<div class="install-menu-status ' + (isError ? 'is-error' : '') + '">'
            + '<i class="fa ' + safeIcon(icon) + '"></i><span>' + escapeHtml(message) + '</span></div>';
    }

    function renderInstallMenuFields(terminals, menuTrees) {
        state.installTerminals = terminals;
        if (!terminals.length) {
            renderInstallMenuStatus('fa-info-circle', '此应用未声明后台、用户端或前端菜单，将直接安装应用文件。', false);
            return;
        }

        elements.installParents.innerHTML = terminals.map(function (terminal) {
            var label = INSTALL_TERMINAL_LABELS[terminal] || terminal;
            var options = '<option value="">安装为独立顶级菜单</option>' + (menuTrees[terminal] || []).map(function (menu) {
                return '<option value="' + Number(menu.id || 0) + '">' + escapeHtml(menu.name || '未命名菜单') + '</option>';
            }).join('');
            return '<label class="menu-parent-field"><span><i class="fa '
                + safeIcon(INSTALL_TERMINAL_ICONS[terminal]) + '"></i>' + escapeHtml(label) + '菜单挂载位置</span>'
                + '<select data-install-terminal="' + escapeHtml(terminal) + '">' + options + '</select></label>';
        }).join('') + '<p class="install-menu-hint">不选择则安装为独立顶级菜单，仅显示应用清单中实际声明的终端。</p>';
    }

    function loadInstallMenuFields(target, source, requestId) {
        var terminalUrl = source === 'local'
            ? '/api/admin/apps/' + encodeURIComponent(target.app_id) + '/menu-terminals'
            : '/api/admin/market/install/' + encodeURIComponent(target.app_id) + '/prepare';
        var terminalMethod = source === 'local' ? 'GET' : 'POST';

        return api(terminalUrl, { method: terminalMethod }).then(function (available) {
            return INSTALL_TERMINALS.filter(function (terminal) { return available && available[terminal]; });
        }).catch(function () {
            toast('菜单终端识别失败，已按后台菜单继续', 'error');
            return ['admin'];
        }).then(function (terminals) {
            var requests = terminals.map(function (terminal) {
                return api('/api/admin/menus/tree?terminal_type=' + encodeURIComponent(terminal)).catch(function () { return []; });
            });
            return Promise.all(requests).then(function (trees) {
                if (requestId !== state.installRequestId) {
                    return;
                }
                var menuTrees = {};
                terminals.forEach(function (terminal, index) { menuTrees[terminal] = trees[index] || []; });
                renderInstallMenuFields(terminals, menuTrees);
                elements.confirmInstall.disabled = false;
            });
        });
    }

    function openInstallDialog(app, source) {
        state.installTarget = Object.assign({}, app, { _source: source });
        state.installTerminals = [];
        state.installRequestId += 1;
        var requestId = state.installRequestId;
        elements.installCreateShortcut.checked = false;
        elements.installSummary.innerHTML = cardIconMarkup(app, 'app-icon') + '<div><strong>' + escapeHtml(app.name || app.app_id) + '</strong><span>'
            + escapeHtml(app.description || '安装后可在 WebOS 中打开此应用') + '</span><span>版本 ' + escapeHtml(app.version || app.latest_version || '-') + '</span></div>';
        renderInstallMenuStatus('fa-circle-o-notch fa-spin', '正在识别应用菜单…', false);
        elements.confirmInstall.disabled = true;
        showModalDialog(elements.installDialog);
        loadInstallMenuFields(state.installTarget, source, requestId);
    }

    function confirmInstall() {
        var target = state.installTarget;
        if (!target) {
            return;
        }
        // 菜单挂载保持默认（安装后自动加入系统菜单）；桌面快捷方式为可选操作
        var createShortcut = elements.installCreateShortcut.checked;
        var source = target._source;
        var url = source === 'local'
            ? '/api/admin/apps/' + encodeURIComponent(target.app_id) + '/local-install'
            : '/api/admin/market/install/' + encodeURIComponent(target.app_id);
        elements.confirmInstall.disabled = true;
        elements.confirmInstall.innerHTML = '<i class="fa fa-circle-o-notch fa-spin"></i>安装中';
        var parentMenuIds = {};
        state.installTerminals.forEach(function (terminal) {
            var select = elements.installParents.querySelector('[data-install-terminal="' + terminal + '"]');
            if (select && select.value) {
                parentMenuIds[terminal] = Number(select.value);
            }
        });
        var body = { parent_menu_ids: parentMenuIds };
        api(url, { method: 'POST', body: body }).then(function () {
            hideModalDialog(elements.installDialog);
            return refreshCatalog();
        }).then(function () {
            if (createShortcut) {
                var entry = state.flatMenus.find(function (item) { return item.app_id === target.app_id; });
                if (entry) {
                    // 桌面快捷方式显示应用名称，而非菜单名称
                    addDesktopEntry(Object.assign({}, entry, { title: target.name || entry.title }));
                } else {
                    toast('应用已安装，但未声明可用的后台菜单入口');
                }
            }
            renderAppCenter('installed');
            // 与传统后台应用管理一致：安装成功后询问是否立即启用
            promptEnableApp(target.app_id, target.name);
        }).catch(function (error) {
            // 与传统后台一致：依赖检测等长错误信息用弹窗展示（依赖应用未安装/未启用时附市场引导），不再一闪而过
            showErrorDialog(error.message);
        }).finally(function () {
            elements.confirmInstall.disabled = false;
            elements.confirmInstall.innerHTML = '<i class="fa fa-download"></i>安装';
        });
    }

    /**
     * 应用升级入口：完整复刻传统后台 admin/app/index.blade.php 的升级交互。
     * 流程：启用中的应用先禁用（确认后自动继续）→ 获取可用版本列表 →
     * layui 弹窗选择升级版本（单步/跳跃/直接最新/一键升级）→ 下载升级包安装 →
     * 成功后清除待升级记录并询问是否启用。
     */
    function upgradeApp(appId) {
        var layer = layuiLayer();

        // 升级前检查应用状态：启用中的应用需先禁用（与卸载拦截一致），禁用成功后自动继续升级
        var cachedApp = findCatalogApp(appId);
        if (cachedApp && Number(cachedApp.status) === 1) {
            // WebOS 自身升级的特殊说明：禁用只影响服务端接口，当前页面内存中的桌面照常可用（其它应用保持通用文案）
            var disableNotice = '「' + escapeHtml(appDisplayName(cachedApp)) + '」当前仍处于启用状态，请先禁用应用再进行升级操作。';
            if (appId === 'cmspro.webos') {
                disableNotice += '<br><span style="color:#999;font-size:12px;">WebOS 说明：升级期间当前桌面与已打开的窗口可继续正常操作，不受影响；升级完成并启用后服务端功能恢复。</span>';
            }
            layer.confirm(disableNotice, {
                icon: 0,
                title: '操作提示',
                btn: ['禁用并继续升级', '取消']
            }, function (index) {
                layer.close(index);
                var loadIndex = layer.load(2);
                legacyAjax({
                    url: '/api/admin/apps/' + encodeURIComponent(appId) + '/disable',
                    type: 'POST',
                    success: function (res) {
                        layer.close(loadIndex);
                        if (res.code === 0) {
                            reloadAppCenterCurrent();
                            layer.msg('已禁用应用，正在继续升级', { icon: 1, time: 1200 }, function () {
                                upgradeApp(appId);
                            });
                        } else {
                            layer.msg(res.message || '禁用失败', { icon: 2 });
                        }
                    },
                    error: function () {
                        layer.close(loadIndex);
                        layer.msg('禁用失败', { icon: 2 });
                    }
                });
            });
            return;
        }

        // 优先使用已加载的待升级记录；未包含该应用时全量检查一次（与后台一致）
        var updateInfo = state.updateApps.find(function (item) { return item.app_id === appId; });
        if (updateInfo && Array.isArray(updateInfo.available_versions) && updateInfo.available_versions.length) {
            openUpgradeDialog(appId, updateInfo.available_versions);
            return;
        }

        var loadIndex = layer.load(2);
        legacyAjax({
            url: '/api/admin/apps/check-updates',
            type: 'POST',
            success: function (res) {
                layer.close(loadIndex);
                if (res.code !== 0) {
                    layer.msg(res.message || '检查更新失败', { icon: 2 });
                    return;
                }
                // 全量检查结果重建待升级记录（与后台 pendingUpdates 行为一致）
                state.updateApps = Array.isArray(res.data) ? res.data : [];
                state.updateCount = state.updateApps.length;
                state.updateChecked = true;
                syncUpdateBadge();
                var info = state.updateApps.find(function (item) { return item.app_id === appId; });
                if (!info) {
                    layer.msg('没有可用的更新', { icon: 1 });
                    return;
                }
                openUpgradeDialog(appId, info.available_versions || []);
            },
            error: function () {
                layer.close(loadIndex);
                layer.msg('获取更新信息失败', { icon: 2 });
            }
        });
    }

    /** 升级弹窗：当前版本 + 备份提醒 + layui radio 选择升级版本（含一键升级到最新版） */
    function openUpgradeDialog(appId, availableVersions) {
        var layer = layuiLayer();
        var app = findCatalogApp(appId);
        var currentVersion = (app && app.version) ? app.version : '?';

        var radioHtml = '<div style="padding:15px;">'
            + '<div style="margin-bottom:10px;color:#666;">当前版本：<strong style="color:#333;">v' + escapeHtml(String(currentVersion)) + '</strong></div>'
            + '<div style="margin-bottom:12px;padding:8px 12px;background:#fff8e1;border-left:3px solid #ff9800;color:#e65100;font-size:13px;">'
            + '<i class="fa fa-exclamation-triangle"></i> 升级前请先使用「备份」功能备份应用，以防升级失败可恢复'
            + '</div>'
            + '<form class="layui-form" id="upgradeVersionForm">';

        availableVersions.forEach(function (version, index) {
            var checked = index === 0 ? 'checked' : '';
            var tip;
            if (index === 0) {
                tip = '（单步升级，推荐）';
            } else if (index === availableVersions.length - 1) {
                tip = '（直接最新）';
            } else {
                tip = '（跳跃升级）';
            }
            radioHtml += '<div style="padding:8px 0;border-bottom:1px solid #f0f0f0;">'
                + '<input type="radio" name="upgrade_version" value="' + escapeHtml(version.version) + '" title="v' + escapeHtml(version.version) + ' ' + tip + '" ' + checked + '>'
                + '<div style="padding-left:24px;color:#999;font-size:12px;margin-top:4px;">' + (version.changelog || '无更新日志') + '</div>'
                + '</div>';
        });

        // 追加「一键升级到最新版」单选选项（逐级自动升级）
        radioHtml += '<div style="padding:8px 0;border-bottom:1px solid #f0f0f0;">'
            + '<input type="radio" name="upgrade_version" value="__auto__" title="一键升级到最新版">'
            + '<i class="fa fa-question-circle" id="autoUpgradeTip" style="color:#999;cursor:pointer;margin-left:4px;"></i>'
            + '</div>';

        radioHtml += '</form></div>';

        layer.open({
            type: 1,
            title: '升级「' + escapeHtml(appDisplayName(app)) + '」',
            area: ['500px', '450px'],
            content: radioHtml,
            btn: ['我已备份，确认升级', '先去备份', '取消'],
            success: function (layero) {
                window.layui.form.render('radio');
                // 「一键升级到最新版」问号图标：鼠标悬停显示 layui tips 说明
                layero.find('#autoUpgradeTip').off('mouseenter').on('mouseenter', function () {
                    layer.tips('按版本自动升级到最新（逐级升级，任一步失败即停止）！', this, { tips: [1, '#666'] });
                });
            },
            btn2: function () {
                // 打开备份对话框，不关闭当前对话框
                openBackupDialog(app || { app_id: appId, name: '应用' });
                return false;
            },
            btn1: function (index) {
                var targetVersion = layuiJquery()('#upgradeVersionForm input[name="upgrade_version"]:checked').val();
                if (!targetVersion) {
                    layer.msg('请选择升级版本', { icon: 2 });
                    return;
                }
                layer.close(index);
                if (targetVersion === '__auto__') {
                    startAutoUpgrade(appId, availableVersions);
                } else {
                    doUpgrade(appId, targetVersion);
                }
            }
        });
    }

    /** 执行升级（下载升级包并安装指定版本） */
    function doUpgrade(appId, targetVersion) {
        var layer = layuiLayer();
        var loadIndex = layer.load(2);
        legacyAjax({
            url: '/api/admin/apps/' + encodeURIComponent(appId) + '/upgrade?version=' + encodeURIComponent(targetVersion),
            type: 'POST',
            success: function (res) {
                layer.close(loadIndex);
                if (res.code === 0) {
                    dropUpdatedApp(appId);
                    layer.msg('升级成功，当前版本：v' + targetVersion, { icon: 1 }, function () {
                        reloadAppCenterCurrent();
                        promptEnableAfterUpgrade(appId);
                    });
                } else {
                    layer.msg(res.message || '升级失败', { icon: 2 });
                }
            },
            error: function () {
                layer.close(loadIndex);
                layer.msg('升级失败，请检查网络连接', { icon: 2 });
            }
        });
    }

    /** 一键升级到最新版：从当前版本逐级升级，任一步失败即停止并报错 */
    function startAutoUpgrade(appId, versions) {
        var list = (versions || []).slice();
        if (!list.length) {
            layuiLayer().msg('没有可用的升级版本', { icon: 2 });
            return;
        }
        runAutoUpgradeStep(appId, list, 0);
    }

    function runAutoUpgradeStep(appId, versions, step) {
        var layer = layuiLayer();
        if (step >= versions.length) {
            dropUpdatedApp(appId);
            layer.msg('已升级到最新版本', { icon: 1 }, function () {
                reloadAppCenterCurrent();
                promptEnableAfterUpgrade(appId);
            });
            return;
        }
        var targetVersion = versions[step].version;
        var loadIndex = layer.load(2);
        legacyAjax({
            url: '/api/admin/apps/' + encodeURIComponent(appId) + '/upgrade?version=' + encodeURIComponent(targetVersion),
            type: 'POST',
            success: function (res) {
                layer.close(loadIndex);
                if (res.code === 0) {
                    // 本步成功，继续下一步
                    runAutoUpgradeStep(appId, versions, step + 1);
                } else if (res.code === 50010 && (res.message || '').indexOf('没有可用的更新') !== -1) {
                    // 已升级到最新版，后端提示无可用更新，视为升级完成
                    dropUpdatedApp(appId);
                    layer.msg('已升级到最新版本', { icon: 1 }, function () {
                        reloadAppCenterCurrent();
                        promptEnableAfterUpgrade(appId);
                    });
                } else {
                    // 任一步失败即停止并报错
                    layer.msg('自动升级在第 ' + (step + 1) + ' 步（v' + targetVersion + '）失败：' + (res.message || '未知错误'), { icon: 2 }, function () {
                        reloadAppCenterCurrent();
                    });
                }
            },
            error: function () {
                layer.close(loadIndex);
                layer.msg('自动升级在第 ' + (step + 1) + ' 步（v' + targetVersion + '）失败：网络错误', { icon: 2 }, function () {
                    reloadAppCenterCurrent();
                });
            }
        });
    }

    function openActionDialog(options) {
        // plain: layui 精简风格（纯色遮罩、无毛玻璃），用于卸载/删除等确认对话框
        elements.actionDialog.classList.toggle('webos-dialog--plain', options.plain === true);
        elements.actionDialogKicker.textContent = options.kicker || '应用操作';
        elements.actionDialogTitle.textContent = options.title || '应用操作';
        elements.actionDialogBody.innerHTML = options.body || '';
        elements.actionDialogFooter.innerHTML = options.footer
            || '<button class="webos-button secondary" type="button" data-action="close-action">关闭</button>';
        // 支持自定义弹窗宽度（如删除/卸载对话 660px），未指定时回退 CSS 默认宽度
        var actionCard = elements.actionDialog.querySelector('.action-dialog-card');
        if (actionCard) {
            actionCard.style.width = options.width || '';
        }
        showModalDialog(elements.actionDialog);
    }

    function closeActionDialog() {
        hideModalDialog(elements.actionDialog);
        elements.actionDialogBody.innerHTML = '';
        elements.actionDialogFooter.innerHTML = '';
    }

    function actionDialogCancelButton() {
        return '<button class="webos-button secondary" type="button" data-action="close-action">取消</button>';
    }

    function openPasswordDialog() {
        // 与系统弹窗一致：layui 风格白底弹窗（plain），不使用毛玻璃效果
        openActionDialog({
            plain: true,
            width: '480px',
            kicker: '账号安全',
            title: '修改密码',
            body: '<form class="password-form" novalidate>'
                + '<p class="dialog-hint">新密码需为 6-20 位，且不能与原密码相同；下次登录请使用新密码。</p>'
                + '<label class="dialog-field"><span>原密码</span><input type="password" data-password-field="old"'
                + ' autocomplete="current-password" placeholder="请输入当前密码"></label>'
                + '<label class="dialog-field"><span>新密码</span><input type="password" data-password-field="new"'
                + ' autocomplete="new-password" placeholder="6-20 位新密码"></label>'
                + '<label class="dialog-field"><span>确认新密码</span><input type="password" data-password-field="confirm"'
                + ' autocomplete="new-password" placeholder="请再次输入新密码"></label></form>',
            footer: actionDialogCancelButton()
                + '<button class="webos-button primary" type="button" data-password-submit><i class="fa fa-key"></i>确认修改</button>'
        });
        var first = elements.actionDialogBody.querySelector('[data-password-field="old"]');
        if (first) {
            window.setTimeout(function () { first.focus(); }, 30);
        }
    }

    function passwordDialogValues() {
        var values = {};
        elements.actionDialogBody.querySelectorAll('[data-password-field]').forEach(function (input) {
            values[input.dataset.passwordField] = input.value;
        });
        return values;
    }

    function validatePasswordValues(values) {
        if (!values.old) {
            return '请输入原密码';
        }
        if (!values.new || values.new.length < 6 || values.new.length > 20) {
            return '新密码长度需为 6-20 位';
        }
        if (values.new === values.old) {
            return '新密码不能与原密码相同';
        }
        if (values.new !== values.confirm) {
            return '两次输入的新密码不一致';
        }
        return '';
    }

    function submitPasswordChange() {
        var values = passwordDialogValues();
        var message = validatePasswordValues(values);
        if (message) {
            toast(message, 'error');
            return;
        }
        var submit = elements.actionDialogFooter.querySelector('[data-password-submit]');
        if (submit) {
            submit.disabled = true;
        }
        api('/api/admin/auth/password', {
            method: 'PUT',
            body: {
                old_password: values.old,
                new_password: values.new,
                confirm_password: values.confirm
            }
        }).then(function () {
            closeActionDialog();
            toast('密码修改成功');
        }).catch(function (error) {
            toast(error.message, 'error');
        }).finally(function () {
            if (submit) {
                submit.disabled = false;
            }
        });
    }

    function appDisplayName(app) {
        return app ? (app.name || app.app_id) : '应用';
    }

    function reloadInstalledAppCenter() {
        return refreshCatalog().then(function () {
            renderAppCenter('installed');
            renderDesktop();
        });
    }

    /** 升级链路刷新：停留在当前 Tab 重渲染（不强制切到已安装），升级成功后更新列表即时移除已升级项 */
    function reloadAppCenterCurrent() {
        return refreshCatalog().then(function () {
            renderAppCenter(state.appCenterTab || 'installed');
            renderDesktop();
        });
    }

    /** afterSuccess：状态保存成功后的回调；trigger：发起操作的状态按钮，可选 */
    function toggleAppStatus(appId, enable, afterSuccess, trigger) {
        setStatusToggleBusy(trigger, true);
        return api('/api/admin/apps/' + encodeURIComponent(appId) + (enable ? '/enable' : '/disable'), { method: 'POST' })
            .then(function () {
                toast(enable ? '应用已启用' : '应用已禁用');
                return reloadInstalledAppCenter();
            })
            .then(function () { if (afterSuccess) { afterSuccess(); } })
            .catch(function (error) { toast(error.message, 'error'); })
            .finally(function () { setStatusToggleBusy(trigger, false); });
    }

    function openDisableFirstDialog(app, message) {
        openActionDialog({
            plain: true,
            width: 'min(660px, calc(100vw - 60px))',
            kicker: '操作提示',
            title: '请先禁用应用',
            body: '<p class="dialog-hint">' + escapeHtml(message || '请先禁用应用后再执行该操作。') + '</p>',
            footer: actionDialogCancelButton() + (app
                ? '<button class="webos-button primary" type="button" data-disable-first data-app-id="'
                    + escapeHtml(app.app_id) + '" data-app-name="' + escapeHtml(appDisplayName(app)) + '">禁用应用</button>' : '')
        });
    }

    /* ======================================================================
     * 应用中心「已安装」的导出 / 手动升级 / 备份 / 文档四项操作，
     * 照抄传统后台 resources/views/admin/app/index.blade.php 的实现，
     * 弹层与 DOM 操作复用桌面页已预热的 layui.layer / layui.jquery。
     * ==================================================================== */
    function layuiLayer() {
        return (window.layui && window.layui.layer) || null;
    }

    function layuiJquery() {
        return (window.layui && window.layui.jquery) || null;
    }

    /** 等价于后台 $.ajaxSetup：统一注入 X-CSRF-TOKEN */
    function legacyAjax(settings) {
        settings.headers = Object.assign({ 'X-CSRF-TOKEN': runtime.csrfToken || '' }, settings.headers || {});
        return layuiJquery().ajax(settings);
    }

    /** 与后台 formatSize 一致（formatFileSize 对 0 返回 '-'，备份大小需显示 0 B） */
    function formatSize(bytes) {
        if (bytes < 1024) {
            return bytes + ' B';
        }
        if (bytes < 1048576) {
            return (bytes / 1024).toFixed(1) + ' KB';
        }
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    /** 错误信息弹窗：替代 layer.msg，长错误信息（如路由冲突明细）不会一闪而过 */
    function showErrorDialog(message) {
        var content = String(message || '操作失败').replace(/\n/g, '<br>');
        var depMatch = String(message || '').match(/依赖应用「(.+?)」未(安装|启用)/);
        if (depMatch) {
            content += '<br><span style="color:#ff9800;">请到应用市场搜索「' + depMatch[1] + '」应用安装并启用。</span>';
        }
        layuiLayer().open({
            type: 1,
            title: '操作失败',
            area: ['560px', 'auto'],
            btn: ['确定'],
            content: '<div style="padding:20px;max-height:400px;overflow-y:auto;font-size:13px;color:#333;'
                + 'line-height:1.9;word-break:break-all;">' + content + '</div>'
        });
    }

    /** 应用安装/升级成功后，询问是否现在启用当前应用（点击「是」则启用，否则不做任何操作）；
     *  keepTab 为 true 时刷新停留在当前 Tab（升级链路），否则回已安装 Tab（安装链路） */
    function promptEnableApp(appId, appName, actionText, keepTab) {
        var layer = layuiLayer();
        layer.confirm('「' + appName + '」' + (actionText || '安装成功') + '，是否现在启用当前应用？', {
            icon: 0,
            title: '启用应用',
            btn: ['是', '取消']
        }, function (index) {
            layer.close(index);
            var loadIndex = layer.load(2);
            legacyAjax({
                url: '/api/admin/apps/' + encodeURIComponent(appId) + '/enable',
                type: 'POST',
                success: function (res) {
                    layer.close(loadIndex);
                    if (res.code === 0) {
                        if (appId === 'cmspro.webos') {
                            // WebOS 自身升级完成：当前页面内存中仍是旧版 JS，引导刷新加载新版本桌面
                            layer.confirm('WebOS 管理桌面已升级并启用至新版本，刷新页面后加载新版桌面。', {
                                icon: 1,
                                title: '升级完成',
                                btn: ['立即刷新', '稍后手动刷新']
                            }, function (confirmIndex) {
                                layer.close(confirmIndex);
                                window.location.reload();
                            });
                        } else {
                            layer.msg('应用已启用', { icon: 1 });
                        }
                    } else {
                        layer.msg(res.message || '启用失败', { icon: 2 });
                    }
                    if (keepTab) {
                        reloadAppCenterCurrent();
                    } else {
                        reloadInstalledAppCenter();
                    }
                }
            });
        });
    }

    /** 上传安装成功后，定位本次新安装的应用（已安装未启用 status===0）并询问是否启用 */
    function promptEnableFirstInstalledApp() {
        legacyAjax({
            url: '/api/admin/apps',
            type: 'GET',
            success: function (res) {
                if (res.code !== 0) {
                    return;
                }
                var fresh = (res.data || []).filter(function (item) { return item.status === 0; });
                if (fresh.length === 1) {
                    promptEnableApp(fresh[0].app_id, fresh[0].name);
                }
            }
        });
    }

    /** 应用升级成功后询问是否启用：先查询最新状态，若应用已处于启用状态则不再询问 */
    function promptEnableAfterUpgrade(appId) {
        legacyAjax({
            url: '/api/admin/apps',
            type: 'GET',
            success: function (res) {
                if (res.code !== 0) {
                    return;
                }
                var app = (res.data || []).find(function (item) { return item.app_id === appId; });
                if (!app || app.status === 1) {
                    return;
                }
                promptEnableApp(appId, app.name, '升级成功，当前版本 v' + app.version, true);
            }
        });
    }

    function openAppUploadDialog() {
        openUploadDialog('上传安装应用', '/api/admin/apps/upload', function (res) {
            return res.message || '操作成功';
        });
    }

    function openManualUpgradeDialog(app) {
        openUploadDialog('手动升级「' + appDisplayName(app) + '」', '/api/admin/apps/upload', function (res) {
            return res.message || '操作成功';
        }, { app_id: app.app_id });
    }

    /** 上传安装与手动升级共用的弹层：拖拽区 + 文件回显 + 提交按钮禁用态 */
    function openUploadDialog(title, submitUrl, onSuccess, extraData) {
        var layer = layuiLayer();
        var fileInput = layuiJquery()(
            '<input type="file" accept=".zip" style="position:absolute;top:0;left:0;width:0;height:0;opacity:0;">'
        );
        layuiJquery()('body').append(fileInput);

        var context = {
            layer: layer,
            file: null,
            fileInput: fileInput,
            submitUrl: submitUrl,
            onSuccess: onSuccess,
            extraData: extraData,
            layero: null,
            dialogIndex: 0
        };
        context.dialogIndex = layer.open({
            type: 1,
            title: title || '上传安装应用',
            area: ['480px', '400px'],
            content: uploadZoneMarkup(title),
            end: function () { fileInput.remove(); },
            success: function (layero) { bindUploadPicker(context, layero); }
        });
    }

    function uploadZoneMarkup(title) {
        return '<div style="padding:20px;">'
            + '<div class="app-upload-zone" id="dlgUploadZone">'
            + '<div class="app-upload-icon"><i class="fa fa-cloud-upload"></i></div>'
            + '<div class="app-upload-text">拖拽 .zip 文件到此处</div>'
            + '<div class="app-upload-or">或</div>'
            + '<button type="button" class="layui-btn layui-btn-sm layui-btn-normal" id="dlgBtnSelectFile">'
            + '<i class="fa fa-folder-open"></i> 选择文件</button>'
            + '<div class="app-upload-hint">仅支持 .zip 格式的应用包</div>'
            + '</div>'
            + '<div class="app-upload-file" id="dlgUploadFileInfo" style="display:none;">'
            + '<div class="app-upload-file-info">'
            + '<i class="fa fa-file-archive-o" style="font-size:28px;color:#ff9800;"></i>'
            + '<div class="app-upload-file-detail">'
            + '<div class="app-upload-file-name" id="dlgUploadFileName"></div>'
            + '<div class="app-upload-file-size" id="dlgUploadFileSize"></div>'
            + '</div>'
            + '<button type="button" class="app-upload-file-remove" id="dlgBtnRemoveFile">'
            + '<i class="fa fa-times"></i></button>'
            + '</div></div>'
            + '<div style="text-align:center;margin-top:15px;">'
            + '<button type="button" class="layui-btn layui-btn-disabled" id="dlgBtnSubmit" disabled>'
            + '<i class="fa fa-download"></i> ' + (title || '开始安装') + '</button>'
            + '</div></div>';
    }

    function bindUploadPicker(context, layero) {
        var $ = layuiJquery();
        var uploadZone = layero.find('#dlgUploadZone');
        context.layero = layero;

        layero.find('#dlgBtnSelectFile').on('click', function (event) {
            event.stopPropagation();
            context.fileInput.trigger('click');
        });
        uploadZone.on('click', function () { context.fileInput.trigger('click'); });
        context.fileInput.on('change', function () {
            if (this.files && this.files.length) {
                setUploadFile(context, this.files[0]);
            }
        });
        uploadZone.on('dragover', function (event) {
            event.preventDefault();
            event.stopPropagation();
            $(this).addClass('dragover');
        }).on('dragleave drop', function (event) {
            event.preventDefault();
            event.stopPropagation();
            $(this).removeClass('dragover');
        }).on('drop', function (event) {
            var files = event.originalEvent.dataTransfer.files;
            if (files.length) {
                setUploadFile(context, files[0]);
            }
        });
        layero.find('#dlgBtnRemoveFile').on('click', function () { clearUploadFile(context); });
        layero.find('#dlgBtnSubmit').on('click', function () { submitUploadPackage(context); });
    }

    function setUploadFile(context, file) {
        if (!file || !file.name.endsWith('.zip')) {
            context.layer.msg('请选择 .zip 格式的应用包', { icon: 2 });
            return;
        }
        context.file = file;
        context.layero.find('#dlgUploadFileName').text(file.name);
        context.layero.find('#dlgUploadFileSize').text(formatSize(file.size));
        context.layero.find('#dlgUploadZone').hide();
        context.layero.find('#dlgUploadFileInfo').show();
        context.layero.find('#dlgBtnSubmit').prop('disabled', false).removeClass('layui-btn-disabled');
    }

    function clearUploadFile(context) {
        context.file = null;
        context.fileInput.val('');
        context.layero.find('#dlgUploadZone').show();
        context.layero.find('#dlgUploadFileInfo').hide();
        context.layero.find('#dlgBtnSubmit').prop('disabled', true).addClass('layui-btn-disabled');
    }

    function submitUploadPackage(context) {
        if (!context.file) {
            return;
        }
        var form = new FormData();
        form.append('package', context.file);
        Object.keys(context.extraData || {}).forEach(function (key) {
            form.append(key, context.extraData[key]);
        });
        // 执行时仅显示加载特效，不带文字提示
        var loadIndex = context.layer.load(2);
        legacyAjax({
            url: context.submitUrl,
            type: 'POST',
            data: form,
            processData: false,
            contentType: false,
            timeout: 600000,
            dataType: 'json',
            success: function (res) {
                context.layer.close(loadIndex);
                handleUploadResult(context, res);
            },
            error: function (xhr) {
                context.layer.close(loadIndex);
                handleUploadError(context, xhr);
            }
        });
    }

    function handleUploadResult(context, res) {
        if (res.code !== 0) {
            showErrorDialog(res.message || '操作失败');
            return;
        }
        var appId = context.extraData ? context.extraData.app_id : '';
        if (appId) {
            dropUpdatedApp(appId);
        }
        var message = context.onSuccess ? context.onSuccess(res) : '操作成功';
        context.layer.msg(message, { icon: 1, time: 1500 }, function () {
            context.layer.close(context.dialogIndex);
            reloadInstalledAppCenter();
            if (appId) {
                promptEnableAfterUpgrade(appId);
            } else {
                promptEnableFirstInstalledApp();
            }
        });
    }

    function handleUploadError(context, xhr) {
        if (xhr.status === 401) {
            return;
        }
        // 后端可能已成功但连接异常断开，先尝试解析响应体
        try {
            var res = JSON.parse(xhr.responseText);
            if (res && res.code === 0) {
                handleUploadResult(context, res);
                return;
            }
            if (res && res.message) {
                showErrorDialog(res.message);
                return;
            }
        } catch (error) {
            // 响应体不是 JSON，按网络错误处理
        }
        showErrorDialog('操作失败，请稍后重试 (HTTP ' + (xhr.status || 'unknown') + ')');
    }

    function exportAppPackage(app) {
        var layer = layuiLayer();
        layer.confirm('确定要导出「' + appDisplayName(app) + '」应用包吗？', { icon: 3 }, function (index) {
            layer.close(index);
            runExport(app.app_id);
        });
    }

    function runExport(appId) {
        var layer = layuiLayer();
        var loadIndex = layer.load(2);
        var xhr = new XMLHttpRequest();
        xhr.open('GET', '/api/admin/apps/' + encodeURIComponent(appId) + '/export', true);
        xhr.setRequestHeader('X-CSRF-TOKEN', runtime.csrfToken || '');
        xhr.responseType = 'blob';
        xhr.onload = function () {
            layer.close(loadIndex);
            handleExportLoaded(xhr, appId);
        };
        xhr.onerror = function () {
            layer.close(loadIndex);
            layer.msg('导出失败', { icon: 2 });
        };
        xhr.send();
    }

    function handleExportLoaded(xhr, appId) {
        var layer = layuiLayer();
        if (xhr.status === 401) {
            layer.msg('登录已过期，请重新登录', { icon: 2, time: 1500 }, function () {
                window.location.href = root.dataset.loginUrl || '/admin/login';
            });
            return;
        }
        if (xhr.status !== 200) {
            layer.msg('导出失败', { icon: 2 });
            return;
        }
        // 应用不存在 / 目录缺失时后端返回 JSON 错误体，不能当成 zip 下载
        if (String(xhr.response.type).indexOf('json') >= 0) {
            readBlobError(xhr.response, '导出失败');
            return;
        }
        saveBlobAsFile(xhr.response, resolveBlobFileName(xhr, appId + '.zip'));
        layer.msg('导出成功', { icon: 1 });
    }

    /** 解析 Content-Disposition 里的文件名 */
    function resolveBlobFileName(xhr, fallback) {
        var disposition = xhr.getResponseHeader('Content-Disposition');
        if (!disposition) {
            return fallback;
        }
        var matches = disposition.match(/filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/);
        return matches && matches[1] ? matches[1].replace(/['"]/g, '') : fallback;
    }

    function saveBlobAsFile(blob, fileName) {
        var url = window.URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = fileName;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        window.URL.revokeObjectURL(url);
    }

    /** 后端以 JSON 返回错误时读出真实提示，而不是下载一个损坏的压缩包 */
    function readBlobError(blob, fallback) {
        var layer = layuiLayer();
        var reader = new FileReader();
        reader.onload = function () {
            try {
                var res = JSON.parse(reader.result);
                layer.msg(res.message || fallback, { icon: 2 });
            } catch (error) {
                layer.msg(fallback, { icon: 2 });
            }
        };
        reader.readAsText(blob);
    }

    function openUninstallDialog(app) {
        // 与传统后台一致：启用中的应用需先禁用，再进行卸载
        if (Number(app.status) === 1) {
            openDisableFirstDialog(app, '「' + appDisplayName(app) + '」当前为启用状态，请先禁用后再卸载。');
            return;
        }
        var name = appDisplayName(app);
        openActionDialog({
            plain: true,
            width: 'min(660px, calc(100vw - 60px))',
            kicker: '应用卸载',
            title: '确认卸载「' + name + '」',
            body: '<div class="dialog-warning"><i class="fa fa-exclamation-triangle"></i>'
                + '<p>将删除数据库表、菜单项、配置项、权限项和静态资源；应用文件保留，可重新安装。</p></div>'
                + '<label class="dialog-field"><span>请输入应用名称「' + escapeHtml(name) + '」确认卸载</span>'
                + '<input type="text" data-uninstall-input placeholder="请输入应用名称"></label>',
            footer: actionDialogCancelButton()
                + '<button class="webos-button danger" type="button" data-uninstall-submit data-app-id="'
                + escapeHtml(app.app_id) + '" data-app-name="' + escapeHtml(name) + '" disabled>确认卸载</button>'
        });
    }

    /** 卸载应用后清理其桌面快捷方式与任务栏固定图标并持久化 */
    function pruneDesktopItemsByAppId(appId) {
        var remaining = state.workspace.desktop_items.filter(function (item) { return item.app_id !== appId; });
        var remainingPinned = taskbarItems().filter(function (item) { return item.app_id !== appId; });
        if (remaining.length === state.workspace.desktop_items.length && remainingPinned.length === taskbarItems().length) {
            return;
        }
        state.workspace.desktop_items = remaining;
        state.workspace.taskbar_items = remainingPinned;
        renderTaskbarWindows();
        saveWorkspace(false).catch(function () {});
    }

    function runUninstall(appId, appName) {
        // 卸载耗时较长，立即显示加载图标反馈进度（仅图标，无文字提示）
        var layer = layuiLayer();
        var loadIndex = layer.load(2, { time: 0 });
        api('/api/admin/apps/' + encodeURIComponent(appId) + '/uninstall', { method: 'POST' }).then(function () {
            layer.close(loadIndex);
            closeActionDialog();
            toast('应用已卸载');
            pruneDesktopItemsByAppId(appId);
            return reloadInstalledAppCenter();
        }).catch(function (error) {
            layer.close(loadIndex);
            if (String(error.message).indexOf('请先禁用应用') >= 0) {
                openDisableFirstDialog(findCatalogApp(appId) || { app_id: appId, name: appName }, error.message);
                return;
            }
            toast(error.message, 'error');
        });
    }

    /** 未安装应用物理删除：对标传统后台 deleteAppFiles，输入应用名确认后删除应用全部文件 */
    function openDeleteFilesDialog(appId, appName) {
        openActionDialog({
            plain: true,
            width: 'min(660px, calc(100vw - 60px))',
            kicker: '删除应用',
            title: '确认删除「' + appName + '」',
            body: '<div class="dialog-warning"><i class="fa fa-exclamation-triangle"></i>'
                + '<p>此操作将永久删除应用的所有文件，此操作不可恢复！</p></div>'
                + '<label class="dialog-field"><span>请输入应用名称「' + escapeHtml(appName) + '」确认删除</span>'
                + '<input type="text" data-delete-input placeholder="请输入应用名称"></label>',
            footer: actionDialogCancelButton()
                + '<button class="webos-button danger" type="button" data-delete-submit data-app-id="'
                + escapeHtml(appId) + '" data-app-name="' + escapeHtml(appName) + '" disabled>确认删除</button>'
        });
    }

    function runDeleteFiles(appId, appName) {
        // 删除应用文件耗时较长，立即显示加载层反馈进度
        var layer = layuiLayer();
        var loadIndex = layer.load(2);
        api('/api/admin/apps/' + encodeURIComponent(appId) + '/delete', { method: 'POST' }).then(function () {
            layer.close(loadIndex);
            closeActionDialog();
            toast('应用文件已删除');
            // 重新拉取未安装列表（应用文件删除后不再出现）
            return renderAppCenter('uninstalled');
        }).catch(function (error) {
            layer.close(loadIndex);
            toast(error.message, 'error');
        });
    }

    /** 恢复单表失败时的最大重试次数，与后台一致 */
    var maxRetries = 3;

    /**
     * 备份流程会叠加多层弹层（列表 → 确认 → 进度），这里记录自己打开的索引。
     * WebOS 的应用窗口同样用 layer.open 承载，因此不能用 layer.closeAll() 全部关闭。
     */
    var backupLayerStack = [];

    function pushBackupLayer(index) {
        backupLayerStack.push(index);
        return index;
    }

    function closeBackupLayers() {
        var layer = layuiLayer();
        while (backupLayerStack.length) {
            layer.close(backupLayerStack.pop());
        }
    }

    function openBackupDialog(app) {
        var layer = layuiLayer();
        var loadIndex = layer.load(2);
        legacyAjax({
            url: '/api/admin/apps/' + encodeURIComponent(app.app_id) + '/backups',
            type: 'GET',
            success: function (res) {
                layer.close(loadIndex);
                renderBackupDialog(app, res);
            },
            error: function () {
                layer.close(loadIndex);
                layer.msg('加载备份记录失败', { icon: 2 });
            }
        });
    }

    function renderBackupDialog(app, res) {
        var layer = layuiLayer();
        if (res.code !== 0) {
            layer.msg(res.message || '加载备份记录失败', { icon: 2 });
            return;
        }
        var backups = Array.isArray(res.data) ? res.data : [];
        pushBackupLayer(layer.open({
            type: 1,
            title: '「' + appDisplayName(app) + '」备份管理',
            area: ['90%', '90%'],
            content: '<div style="padding:15px;">' + backupToolbarMarkup(backups.length, app.app_id, appDisplayName(app))
                + '<div style="overflow-y:auto;max-height:390px;">' + renderBackupList(backups) + '</div></div>',
            success: function (layero) {
                bindBackupDialogEvents(layero, app);
            }
        }));
    }

    function renderBackupList(backups) {
        if (!backups.length) {
            return '<div style="text-align:center;padding:40px 0;color:#999;">'
                + '<i class="fa fa-database" style="font-size:36px;display:block;margin-bottom:10px;"></i>'
                + '暂无备份记录</div>';
        }

        return '<table class="layui-table" style="margin:0;"><colgroup>'
            + '<col width="168"><col width="120"><col width="140"><col></colgroup>'
            + '<thead><tr><th>备份时间</th><th>版本</th><th>大小</th>'
            + '<th style="text-align:right;">操作</th></tr></thead><tbody>'
            + backups.map(function (backup) {
                return backupRowMarkup(backup);
            }).join('') + '</tbody></table>';
    }

    /** 备份失败的记录不提供「导出」「恢复」，只能删除 */
    function backupRowMarkup(backup) {
        var id = escapeHtml(String(backup.id));
        var buttons = '';
        if (Number(backup.status) === 1) {
            buttons += '<button type="button" class="layui-btn layui-btn-xs layui-btn-warm" data-backup-download="'
                + id + '"><i class="fa fa-download"></i> 导出</button>';
            buttons += '<button type="button" class="layui-btn layui-btn-xs" data-backup-restore="' + id
                + '"><i class="fa fa-undo"></i> 恢复</button>';
        }
        buttons += '<button type="button" class="layui-btn layui-btn-xs layui-btn-danger" data-backup-delete="'
            + id + '"><i class="fa fa-trash"></i> 删除</button>';

        return '<tr><td>' + escapeHtml(backup.create_time || '-') + '</td>'
            + '<td>v' + escapeHtml(backup.app_version || '-') + '</td>'
            + '<td>' + formatSize(backup.file_size || 0) + '</td>'
            + '<td style="text-align:right;white-space:nowrap;">' + buttons + '</td></tr>';
    }

    /** 顶部工具栏照抄后台：左「共 N 条 + 导入恢复」，右「立即备份」 */
    function backupToolbarMarkup(count, appId, appName) {
        return '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">'
            + '<div style="display:flex;gap:8px;align-items:center;">'
            + '<span style="font-size:14px;color:#666;">共 ' + count + ' 条备份记录</span>'
            + '<button type="button" class="layui-btn layui-btn-sm layui-btn-warm" id="dlgBtnImportBackup" data-app-id="'
            + escapeHtml(appId) + '" data-app-name="' + escapeHtml(appName) + '" style="margin-left:8px;">'
            + '<i class="fa fa-upload"></i> 导入恢复</button>'
            + '</div>'
            + '<div style="display:flex;gap:8px;">'
            + '<button type="button" class="layui-btn layui-btn-sm layui-btn-normal" id="dlgBtnCreateBackup">'
            + '<i class="fa fa-plus"></i> 立即备份</button></div>'
            + '</div>';
    }

    function bindBackupDialogEvents(layero, app) {
        var $ = layuiJquery();
        var scope = $(layero);
        scope.find('#dlgBtnCreateBackup').on('click', function () {
            openCreateBackupDialog(app.app_id, appDisplayName(app));
        });
        scope.find('#dlgBtnImportBackup').on('click', function () {
            openImportDialog(app.app_id, appDisplayName(app));
        });
        scope.find('[data-backup-download]').on('click', function () {
            downloadAppBackup($(this).data('backup-download'));
        });
        scope.find('[data-backup-restore]').on('click', function () {
            restoreAppBackup($(this).data('backup-restore'), app.app_id, appDisplayName(app));
        });
        scope.find('[data-backup-delete]').on('click', function () {
            deleteAppBackup($(this).data('backup-delete'), app);
        });
    }

    function openCreateBackupDialog(appId, appName) {
        pushBackupLayer(layuiLayer().open({
            type: 1,
            title: '确认备份「' + appName + '」',
            area: ['460px', 'auto'],
            content: createBackupMarkup(),
            success: function (layero) {
                bindCreateBackupEvents(layero, appId, appName);
            }
        }));
    }

    function createBackupMarkup() {
        return '<div style="padding:20px;">'
            + '<div style="margin-bottom:16px;font-size:13px;color:#666;">备份将包含应用数据表和程序文件</div>'
            + '<div class="layui-form-item" style="margin-bottom:16px;">'
            + '<label style="font-size:13px;color:#333;display:block;margin-bottom:6px;">分卷大小（MB）</label>'
            + '<input type="number" id="dlgVolumeSize" class="layui-input" value="2" min="1" max="100" '
            + 'style="width:120px;">'
            + '<div style="font-size:12px;color:#999;margin-top:6px;line-height:1.6;">'
            + '分卷越小单次操作越稳定，但卷数越多；分卷越大效率越高，但服务器性能不足时容易超时。'
            + '<br>建议值：虚拟主机 1-5MB，云服务器 10-50MB。</div></div>'
            + '<div style="text-align:right;padding-top:10px;border-top:1px solid #f0f0f0;">'
            + '<button type="button" class="layui-btn layui-btn-primary" id="dlgBtnCancelBackup">取消</button>'
            + '<button type="button" class="layui-btn" id="dlgBtnConfirmBackup">'
            + '<i class="fa fa-database"></i> 确认备份</button></div></div>';
    }

    function bindCreateBackupEvents(layero, appId, appName) {
        var scope = layuiJquery()(layero);
        scope.find('#dlgBtnCancelBackup').on('click', function () {
            closeBackupLayers();
        });
        scope.find('#dlgBtnConfirmBackup').on('click', function () {
            var volumeSize = parseInt(scope.find('#dlgVolumeSize').val(), 10) || 2;
            volumeSize = Math.min(100, Math.max(1, volumeSize));
            closeBackupLayers();
            doBackupWithProgress(appId, appName, volumeSize);
        });
    }

    /** 备份与恢复共用的进度弹层内容，prefix 用于区分两套 DOM id */
    function taskProgressMarkup(prefix, barStyle) {
        return '<div style="padding:24px;">'
            + '<div style="margin-bottom:12px;"><span id="' + prefix + 'Status">正在准备...</span></div>'
            + '<div class="layui-progress layui-progress-big" lay-showpercent="true">'
            + '<div class="layui-progress-bar" id="' + prefix + 'ProgressBar" lay-percent="0%"'
            + (barStyle ? ' style="' + barStyle + '"' : '') + '></div></div>'
            + '<div style="margin-top:12px;font-size:12px;color:#999;" id="' + prefix + 'Detail"></div></div>';
    }

    /** 更新进度弹层：detail 为空时保留上一次文本，与后台 updateProgress 一致 */
    function updateTaskProgress(ctx, progress) {
        var scope = layuiJquery()(ctx.layero);
        var percent = Math.min(100, Math.round(progress.current / progress.total * 100));
        var bar = scope.find('#' + ctx.prefix + 'ProgressBar');
        bar.css('width', percent + '%').attr('lay-percent', percent + '%');
        bar.text(percent + '%');
        scope.find('#' + ctx.prefix + 'Status').text(progress.status);
        if (progress.detail) {
            scope.find('#' + ctx.prefix + 'Detail').text(progress.detail);
        }
    }

    function doBackupWithProgress(appId, appName, volumeSize) {
        var ctx = {
            appId: appId, appName: appName, volumeSize: volumeSize, prefix: 'bk',
            sessionId: '', totalTables: 0, tables: [], layero: null
        };
        pushBackupLayer(layuiLayer().open({
            type: 1,
            title: '正在备份「' + appName + '」',
            area: ['480px', '280px'],
            shadeClose: false,
            content: taskProgressMarkup('bk', ''),
            success: function (layero) {
                ctx.layero = layero;
                prepareBackup(ctx);
            }
        }));
    }

    function prepareBackup(ctx) {
        legacyAjax({
            url: '/api/admin/apps/' + encodeURIComponent(ctx.appId) + '/backups/prepare',
            type: 'POST',
            data: { volume_size: ctx.volumeSize },
            timeout: 30000,
            success: function (res) {
                if (res.code !== 0) {
                    closeBackupLayers();
                    layuiLayer().msg(res.message || '准备失败', { icon: 2 });
                    return;
                }
                ctx.sessionId = res.data.session_id;
                ctx.tables = res.data.tables || [];
                ctx.totalTables = res.data.total_tables || ctx.tables.length;
                updateTaskProgress(ctx, {
                    current: 0, total: ctx.totalTables,
                    status: '准备完成，共 ' + ctx.totalTables + ' 张表', detail: ''
                });
                backupNextTable(ctx, 0);
            },
            error: function () {
                closeBackupLayers();
                layuiLayer().msg('备份请求失败', { icon: 2 });
            }
        });
    }

    function backupNextTable(ctx, index) {
        if (index >= ctx.tables.length) {
            finishBackupSession(ctx);
            return;
        }
        backupTablePage(ctx, index, null);
    }

    /** 单表分页备份：后端按 last_id 游标分卷，未结束时带 last_id 递归请求下一页 */
    function backupTablePage(ctx, index, lastId) {
        var table = ctx.tables[index];
        var data = { session_id: ctx.sessionId, table: table, volume_size: ctx.volumeSize };
        if (lastId !== null) {
            data.last_id = lastId;
        }
        updateTaskProgress(ctx, {
            current: index, total: ctx.tables.length,
            status: '正在备份表 ' + (index + 1) + '/' + ctx.tables.length,
            detail: lastId === null ? table : table + ' | 正在分页处理...'
        });
        legacyAjax({
            url: '/api/admin/apps/' + encodeURIComponent(ctx.appId) + '/backups/table',
            type: 'POST',
            data: data,
            timeout: 300000,
            success: function (res) {
                handleBackupTableResult(ctx, index, table, res);
            },
            error: function () {
                closeBackupLayers();
                layuiLayer().msg('备份请求失败：表 ' + table, { icon: 2 });
            }
        });
    }

    function handleBackupTableResult(ctx, index, table, res) {
        if (res.code !== 0) {
            closeBackupLayers();
            layuiLayer().msg(res.message || '表备份失败', { icon: 2 });
            return;
        }
        var data = res.data || {};
        if (data.has_more) {
            var percent = Math.round(data.last_id / data.total_rows * 100);
            updateTaskProgress(ctx, {
                current: index, total: ctx.tables.length,
                status: '正在备份表 ' + (index + 1) + '/' + ctx.tables.length,
                detail: '第 ' + data.page + ' 页，已处理 ' + data.last_id + '/' + data.total_rows + ' 行 (' + percent + '%)'
            });
            backupTablePage(ctx, index, data.last_id);
            return;
        }
        updateTaskProgress(ctx, {
            current: index + 1, total: ctx.tables.length,
            status: '表 ' + table + ' 备份完成', detail: '已完成 ' + (data.total_rows || 0) + ' 行'
        });
        backupNextTable(ctx, index + 1);
    }

    function finishBackupSession(ctx) {
        var layer = layuiLayer();
        updateTaskProgress(ctx, {
            current: ctx.totalTables, total: ctx.totalTables, status: '正在打包...', detail: ''
        });
        legacyAjax({
            url: '/api/admin/apps/' + encodeURIComponent(ctx.appId) + '/backups/finish',
            type: 'POST',
            data: { session_id: ctx.sessionId },
            timeout: 120000,
            success: function (res) {
                closeBackupLayers();
                if (res.code !== 0) {
                    layer.msg(res.message || '打包失败', { icon: 2 });
                    return;
                }
                var size = formatSize((res.data || {}).file_size || 0);
                layer.msg('备份成功！文件大小：' + size, { icon: 1 }, function () {
                    openBackupDialog({ app_id: ctx.appId, name: ctx.appName });
                });
            },
            error: function () {
                closeBackupLayers();
                layer.msg('打包请求失败', { icon: 2 });
            }
        });
    }

    function restoreAppBackup(backupId, appId, appName) {
        var layer = layuiLayer();
        layer.confirm('确定要从备份恢复「' + appName + '」吗？<br><br>'
            + '<span style="color:#ff5722;font-size:13px;font-weight:600;">'
            + '警告：恢复操作将覆盖当前应用的数据表和程序文件，此操作不可逆！</span>',
        { icon: 0, title: '确认恢复' }, function (index) {
            layer.close(index);
            closeBackupLayers();
            doRestoreWithProgress(appId, { backup_id: backupId }, appName);
        });
    }

    function deleteAppBackup(backupId, app) {
        var layer = layuiLayer();
        layer.confirm('确定要删除此备份记录吗？<br>'
            + '<span style="color:#999;font-size:12px;">备份文件将一并删除，此操作不可恢复</span>',
        { icon: 3, title: '确认删除' }, function (index) {
            layer.close(index);
            var loadIndex = layer.load(2);
            legacyAjax({
                url: '/api/admin/apps/backups/' + backupId,
                type: 'DELETE',
                success: function (res) {
                    layer.close(loadIndex);
                    if (res.code !== 0) {
                        layer.msg(res.message || '删除失败', { icon: 2 });
                        return;
                    }
                    layer.msg('删除成功', { icon: 1 }, function () {
                        closeBackupLayers();
                        openBackupDialog(app);
                    });
                },
                error: function () {
                    layer.close(loadIndex);
                    layer.msg('删除请求失败', { icon: 2 });
                }
            });
        });
    }

    function downloadAppBackup(backupId) {
        var layer = layuiLayer();
        var loadIndex = layer.load(2);
        legacyAjax({
            url: '/api/admin/apps/backups/' + backupId + '/download',
            type: 'GET',
            xhrFields: { responseType: 'blob' },
            success: function (data, status, xhr) {
                layer.close(loadIndex);
                handleBackupDownload(data, xhr, backupId);
            },
            error: function () {
                layer.close(loadIndex);
                layer.msg('下载请求失败', { icon: 2 });
            }
        });
    }

    /** 备份文件缺失时后端返回 JSON 错误体，需读出真实提示而不是下载损坏的压缩包 */
    function handleBackupDownload(blob, xhr, backupId) {
        var layer = layuiLayer();
        if (xhr.status === 200 && blob && blob.size > 0) {
            var fileName = resolveBlobFileName(xhr, 'backup_' + backupId + '.zip');
            saveBlobAsFile(new Blob([blob], { type: 'application/zip' }), fileName);
            layer.msg('下载成功', { icon: 1 });
            return;
        }
        readBlobError(blob, '下载失败');
    }

    // ===== 导入恢复（照抄后台：上传文件 + 从服务器选择两个 Tab）=====

    function openImportDialog(appId, appName) {
        pushBackupLayer(layuiLayer().open({
            type: 1,
            title: '导入恢复「' + appName + '」',
            area: ['580px', '480px'],
            content: importDialogMarkup(),
            success: function (layero) {
                bindImportDialogEvents(layero, appId, appName);
            }
        }));
    }

    function importDialogMarkup() {
        return '<div style="padding:20px;">'
            + '<div style="display:flex;border-bottom:1px solid #e6e6e6;margin-bottom:16px;">'
            + '<button type="button" class="import-tab active" data-tab="upload" style="padding:8px 20px;border:none;'
            + 'background:none;cursor:pointer;font-size:14px;font-weight:500;color:#1e9fff;'
            + 'border-bottom:2px solid #1e9fff;">上传文件</button>'
            + '<button type="button" class="import-tab" data-tab="server" style="padding:8px 20px;border:none;'
            + 'background:none;cursor:pointer;font-size:14px;font-weight:500;color:#999;'
            + 'border-bottom:2px solid transparent;">从服务器选择</button>'
            + '</div>'
            // Tab 1: 上传文件
            + '<div id="importTabUpload">'
            + '<div style="background:#fff3e0;padding:12px 15px;border-radius:6px;margin-bottom:16px;font-size:13px;color:#e65100;">'
            + '<p style="font-weight:600;margin-bottom:6px;">跨站点导入说明</p>'
            + '<ul style="padding-left:18px;margin:0;">'
            + '<li>请选择从其他站点「导出」的 .zip 备份包</li>'
            + '<li>导入将覆盖当前应用的数据库表和程序文件</li>'
            + '<li>请确保目标应用已安装且版本兼容</li>'
            + '</ul></div>'
            + '<div class="app-upload-zone" id="dlgImportZone" style="border-color:#e6e6e6;">'
            + '<div class="app-upload-icon"><i class="fa fa-upload"></i></div>'
            + '<div class="app-upload-text">拖拽 .zip 备份包到此处，或点击下方按钮选择文件</div>'
            + '<button type="button" class="layui-btn layui-btn-sm layui-btn-warm" id="dlgBtnSelectImportFile">'
            + '<i class="fa fa-folder-open"></i> 选择备份包</button>'
            + '</div>'
            + '<div class="app-upload-file" id="dlgImportFileInfo" style="display:none;">'
            + '<div class="app-upload-file-info">'
            + '<i class="fa fa-file-archive-o" style="font-size:28px;color:#ff9800;"></i>'
            + '<div class="app-upload-file-detail">'
            + '<div class="app-upload-file-name" id="dlgImportFileName"></div>'
            + '<div class="app-upload-file-size" id="dlgImportFileSize"></div>'
            + '</div>'
            + '<button type="button" class="app-upload-file-remove" id="dlgBtnRemoveImportFile">'
            + '<i class="fa fa-times"></i></button>'
            + '</div></div>'
            + '<div style="text-align:center;margin-top:15px;">'
            + '<button type="button" class="layui-btn layui-btn-disabled" id="dlgBtnDoImport" disabled>'
            + '<i class="fa fa-cloud-upload"></i> 确认导入恢复</button>'
            + '</div></div>'
            // Tab 2: 从服务器选择
            + '<div id="importTabServer" style="display:none;">'
            + '<div style="background:#e8eaf6;padding:10px 14px;border-radius:6px;margin-bottom:12px;font-size:13px;color:#283593;">'
            + '<i class="fa fa-server"></i> 从服务器本地备份文件恢复，无需上传，不受文件大小限制'
            + '</div>'
            + '<div id="dlgServerFileList"><div style="text-align:center;padding:20px;color:#999;">加载中...</div></div>'
            + '</div>'
            + '</div>';
    }

    function bindImportDialogEvents(layero, appId, appName) {
        var $ = layuiJquery();
        var layer = layuiLayer();
        var fileInput = $('<input type="file" accept=".zip" style="position:absolute;top:0;left:0;width:0;height:0;opacity:0;">');
        $('body').append(fileInput);

        var selectedFile = null;
        var importZone = layero.find('#dlgImportZone');
        var fileInfo = layero.find('#dlgImportFileInfo');
        var btnDoImport = layero.find('#dlgBtnDoImport');
        var serverTabLoaded = false;

        // Tab 切换，服务器列表首次切入时懒加载
        layero.on('click', '.import-tab', function () {
            var tab = $(this).data('tab');
            layero.find('.import-tab').removeClass('active').css({ color: '#999', borderBottom: '2px solid transparent' });
            $(this).addClass('active').css({ color: '#1e9fff', borderBottom: '2px solid #1e9fff' });

            if (tab === 'upload') {
                layero.find('#importTabUpload').show();
                layero.find('#importTabServer').hide();
            } else {
                layero.find('#importTabUpload').hide();
                layero.find('#importTabServer').show();
                if (!serverTabLoaded) {
                    serverTabLoaded = true;
                    loadServerBackupFiles(layero, appId, appName);
                }
            }
        });

        function setImportFile(file) {
            if (!file || file.name.slice(-4).toLowerCase() !== '.zip') {
                layer.msg('请选择 .zip 格式的备份包', { icon: 2 });
                return;
            }
            selectedFile = file;
            layero.find('#dlgImportFileName').text(file.name);
            layero.find('#dlgImportFileSize').text(formatSize(file.size));
            importZone.hide();
            fileInfo.show();
            btnDoImport.prop('disabled', false).removeClass('layui-btn-disabled');
        }

        function clearImportFile() {
            selectedFile = null;
            fileInput.val('');
            importZone.show();
            fileInfo.hide();
            btnDoImport.prop('disabled', true).addClass('layui-btn-disabled');
        }

        layero.find('#dlgBtnSelectImportFile').on('click', function (e) {
            e.stopPropagation();
            fileInput.trigger('click');
        });

        importZone.on('click', function () { fileInput.trigger('click'); });

        fileInput.on('change', function () {
            if (this.files && this.files.length) { setImportFile(this.files[0]); }
        });

        importZone.on('dragover', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).addClass('dragover');
        }).on('dragleave drop', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('dragover');
        }).on('drop', function (e) {
            var files = e.originalEvent.dataTransfer.files;
            if (files.length) { setImportFile(files[0]); }
        });

        layero.find('#dlgBtnRemoveImportFile').on('click', clearImportFile);

        btnDoImport.on('click', function () {
            if (!selectedFile) { return; }
            layer.confirm('确定要从此备份包恢复「' + appName + '」吗？<br><br>'
                + '<span style="color:#ff5722;font-size:13px;font-weight:600;">警告：当前应用的数据表和程序文件将被覆盖！</span>',
            { icon: 0, title: '确认导入恢复' }, function (index) {
                layer.close(index);
                closeBackupLayers();

                // 先上传文件到服务器本地目录，再走分步恢复
                var formData = new FormData();
                formData.append('package', selectedFile);

                var uploadLoadIndex = layer.load(2);
                legacyAjax({
                    url: '/api/admin/apps/' + encodeURIComponent(appId) + '/backups/upload-file',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    timeout: 600000,
                    success: function (res) {
                        layer.close(uploadLoadIndex);
                        if (res.code === 0) {
                            doRestoreWithProgress(appId, { local_file_name: res.data.file_name }, appName);
                        } else {
                            layer.msg(res.message || '上传失败', { icon: 2 });
                        }
                    },
                    error: function () {
                        layer.close(uploadLoadIndex);
                        layer.msg('上传请求失败', { icon: 2 });
                    }
                });
            });
        });

        // 弹层关闭时清理隐藏的文件选择框
        layero.on('remove', function () { fileInput.remove(); });
    }

    /** 加载服务器本地备份文件列表（/storage/app_backups/{appId}/ 下的 .zip） */
    function loadServerBackupFiles(layero, appId, appName) {
        var listEl = layero.find('#dlgServerFileList');
        listEl.html('<div style="text-align:center;padding:20px;color:#999;"><i class="fa fa-spinner fa-spin"></i> 正在读取...</div>');
        legacyAjax({
            url: '/api/admin/apps/' + encodeURIComponent(appId) + '/backups/local-files',
            type: 'GET',
            success: function (res) {
                if (res.code !== 0) {
                    listEl.html('<div style="text-align:center;padding:20px;color:#f44336;">'
                        + escapeHtml(res.message || '读取失败') + '</div>');
                    return;
                }
                var files = Array.isArray(res.data) ? res.data : [];
                if (files.length === 0) {
                    listEl.html('<div style="text-align:center;padding:40px 0;color:#999;">'
                        + '<i class="fa fa-folder-open" style="font-size:36px;display:block;margin-bottom:10px;"></i>'
                        + '<p>服务器备份目录中暂无 .zip 文件</p>'
                        + '<p style="font-size:12px;margin-top:6px;">请先将备份包上传至：/storage/app_backups/'
                        + escapeHtml(appId) + '/</p></div>');
                    return;
                }
                var html = '<div style="margin-bottom:8px;font-size:12px;color:#999;">'
                    + '<i class="fa fa-info-circle"></i> 文件位于 /storage/app_backups/' + escapeHtml(appId) + '/ 目录'
                    + '</div>'
                    + '<table class="layui-table" style="margin:0;">'
                    + '<colgroup><col><col width="90"><col width="140"><col width="80"></colgroup>'
                    + '<thead><tr><th>文件名</th><th>大小</th><th>修改时间</th>'
                    + '<th style="text-align:right;">操作</th></tr></thead><tbody>';
                files.forEach(function (file) {
                    html += '<tr>'
                        + '<td style="word-break:break-all;">' + escapeHtml(file.file_name) + '</td>'
                        + '<td>' + formatSize(file.file_size) + '</td>'
                        + '<td>' + escapeHtml(file.modified_time) + '</td>'
                        + '<td style="text-align:right;"><button type="button" class="layui-btn layui-btn-xs"'
                        + ' data-local-file="' + escapeHtml(file.file_name) + '">'
                        + '<i class="fa fa-undo"></i> 恢复</button></td>'
                        + '</tr>';
                });
                html += '</tbody></table>';
                listEl.html(html);
                listEl.find('[data-local-file]').on('click', function () {
                    doLocalRestore(appId, $(this).data('local-file'), appName);
                });
            },
            error: function () {
                listEl.html('<div style="text-align:center;padding:20px;color:#f44336;">读取服务器文件失败</div>');
            }
        });
    }

    /** 从服务器本地文件恢复（照抄后台 doLocalRestore） */
    function doLocalRestore(appId, fileName, appName) {
        var layer = layuiLayer();
        layer.confirm('确定要从本地文件「' + escapeHtml(fileName) + '」恢复「' + appName + '」吗？<br><br>'
            + '<span style="color:#ff5722;font-size:13px;font-weight:600;">警告：当前应用的数据表和程序文件将被覆盖！</span>',
        { icon: 0, title: '确认本地恢复' }, function (index) {
            layer.close(index);
            closeBackupLayers();
            doRestoreWithProgress(appId, { local_file_name: fileName }, appName);
        });
    }

    function doRestoreWithProgress(appId, prepareData, appName) {
        var ctx = {
            appId: appId, appName: appName, prepareData: prepareData, prefix: 'rs',
            sessionId: '', totalTables: 0, tables: [], retryCount: {}, layero: null
        };
        pushBackupLayer(layuiLayer().open({
            type: 1,
            title: '正在恢复「' + appName + '」',
            area: ['480px', '280px'],
            shadeClose: false,
            content: taskProgressMarkup('rs', 'width:0%;background:#1e9fff;'),
            success: function (layero) {
                ctx.layero = layero;
                prepareRestore(ctx);
            }
        }));
    }

    function prepareRestore(ctx) {
        legacyAjax({
            url: '/api/admin/apps/' + encodeURIComponent(ctx.appId) + '/backups/restore-prepare',
            type: 'POST',
            data: ctx.prepareData,
            timeout: 300000,
            success: function (res) {
                if (res.code !== 0) {
                    closeBackupLayers();
                    layuiLayer().msg(res.message || '准备失败', { icon: 2 });
                    return;
                }
                ctx.sessionId = res.data.session_id;
                ctx.tables = res.data.tables || [];
                ctx.totalTables = res.data.total_tables || ctx.tables.length;
                updateTaskProgress(ctx, {
                    current: 0, total: ctx.totalTables,
                    status: '准备完成，共 ' + ctx.totalTables + ' 张表', detail: ''
                });
                restoreNextTable(ctx, 0, 0);
            },
            error: function () {
                closeBackupLayers();
                layuiLayer().msg('恢复准备请求失败', { icon: 2 });
            }
        });
    }

    /** 单表分页恢复：失败按 offset 维度计数重试，成功后清除计数 */
    function restoreNextTable(ctx, tableIndex, tableOffset) {
        if (tableIndex >= ctx.tables.length) {
            finishRestoreSession(ctx);
            return;
        }
        var table = ctx.tables[tableIndex];
        var key = table + '_' + tableOffset;
        if (!ctx.retryCount[key]) {
            ctx.retryCount[key] = 0;
        }
        updateTaskProgress(ctx, {
            current: tableIndex, total: ctx.tables.length,
            status: '正在恢复表 ' + (tableIndex + 1) + '/' + ctx.tables.length
                + (tableOffset > 0 ? ' (续传 ' + tableOffset + ')' : ''),
            detail: table + (ctx.retryCount[key] > 0 ? ' (重试 ' + ctx.retryCount[key] + '/' + maxRetries + ')' : '')
        });
        legacyAjax({
            url: '/api/admin/apps/' + encodeURIComponent(ctx.appId) + '/backups/restore-table',
            type: 'POST',
            data: { session_id: ctx.sessionId, table: table, offset: tableOffset },
            timeout: 300000,
            success: function (res) {
                handleRestoreTableResult(ctx, tableIndex, tableOffset, res);
            },
            error: function () {
                handleRestoreError(ctx, tableIndex, tableOffset);
            }
        });
    }

    function handleRestoreTableResult(ctx, tableIndex, tableOffset, res) {
        var table = ctx.tables[tableIndex];
        if (res.code !== 0) {
            handleRestoreFailure(ctx, tableIndex, tableOffset, res.message);
            return;
        }
        delete ctx.retryCount[table + '_' + tableOffset];
        var data = res.data || {};
        if (data.has_more) {
            updateTaskProgress(ctx, {
                current: tableIndex, total: ctx.tables.length,
                status: '表 ' + table + ' 恢复中 (' + data.offset + '/' + data.total + ')', detail: table
            });
            restoreNextTable(ctx, tableIndex, data.offset);
            return;
        }
        updateTaskProgress(ctx, {
            current: tableIndex + 1, total: ctx.tables.length,
            status: '表 ' + table + ' 恢复完成', detail: ''
        });
        restoreNextTable(ctx, tableIndex + 1, 0);
    }

    /** 恢复表返回业务错误：未超过上限则 1 秒后重试，否则提示后端错误信息 */
    function handleRestoreFailure(ctx, tableIndex, tableOffset, message) {
        var table = ctx.tables[tableIndex];
        var key = table + '_' + tableOffset;
        if (ctx.retryCount[key] < maxRetries) {
            ctx.retryCount[key] += 1;
            updateTaskProgress(ctx, {
                current: tableIndex, total: ctx.tables.length,
                status: '表 ' + table + ' 返回异常，' + ctx.retryCount[key] + '/' + maxRetries + ' 重试...',
                detail: message
            });
            window.setTimeout(function () {
                restoreNextTable(ctx, tableIndex, tableOffset);
            }, 1000);
            return;
        }
        closeBackupLayers();
        layuiLayer().msg(message || '表恢复失败', { icon: 2 });
    }

    /** 恢复表请求失败（网络/超时）：未超过上限则 2 秒后重试，否则提示已重试次数 */
    function handleRestoreError(ctx, tableIndex, tableOffset) {
        var table = ctx.tables[tableIndex];
        var key = table + '_' + tableOffset;
        if (ctx.retryCount[key] < maxRetries) {
            ctx.retryCount[key] += 1;
            updateTaskProgress(ctx, {
                current: tableIndex, total: ctx.tables.length,
                status: '表 ' + table + ' 请求失败，' + ctx.retryCount[key] + '/' + maxRetries + ' 重试...', detail: ''
            });
            window.setTimeout(function () {
                restoreNextTable(ctx, tableIndex, tableOffset);
            }, 2000);
            return;
        }
        closeBackupLayers();
        layuiLayer().msg('恢复请求失败：表 ' + table + '（已重试 ' + maxRetries + ' 次）', { icon: 2 });
    }

    function finishRestoreSession(ctx) {
        var layer = layuiLayer();
        updateTaskProgress(ctx, {
            current: ctx.totalTables, total: ctx.totalTables, status: '正在恢复程序文件...', detail: ''
        });
        legacyAjax({
            url: '/api/admin/apps/' + encodeURIComponent(ctx.appId) + '/backups/restore-finish',
            type: 'POST',
            data: { session_id: ctx.sessionId },
            timeout: 120000,
            success: function (res) {
                closeBackupLayers();
                if (res.code !== 0) {
                    layer.msg(res.message || '恢复完成步骤失败', { icon: 2 });
                    return;
                }
                layer.msg('恢复成功', { icon: 1 }, function () {
                    reloadInstalledAppCenter();
                });
            },
            error: function () {
                closeBackupLayers();
                layer.msg('恢复完成请求失败', { icon: 2 });
            }
        });
    }

    /** 打开应用文档：先取文档树，再定位第一篇文档进入三栏预览 */
    function openAppDocs(appId) {
        var layer = layuiLayer();
        state.docAppId = appId;
        var loadIndex = layer.load(2);
        legacyAjax({
            url: '/api/admin/apps/' + encodeURIComponent(appId) + '/docs',
            type: 'GET',
            success: function (res) {
                layer.close(loadIndex);
                handleDocTreeLoaded(res);
            },
            error: function () {
                layer.close(loadIndex);
                layer.msg('加载文档列表失败', { icon: 2 });
            }
        });
    }

    function handleDocTreeLoaded(res) {
        var layer = layuiLayer();
        if (res.code !== 0) {
            layer.msg(res.message || '加载文档失败', { icon: 2 });
            return;
        }
        var data = res.data || {};
        var first = data.has_doc ? findFirstDocFile(data.tree || []) : null;
        if (!first) {
            layer.msg('该应用暂无文档', { icon: 0, time: 1500 });
            return;
        }
        openDocViewer(first.path, first.name);
    }

    function findFirstDocFile(items) {
        for (var index = 0; index < items.length; index += 1) {
            if (items[index].type === 'file') {
                return { path: items[index].path, name: items[index].name };
            }
            if (items[index].type === 'directory' && items[index].children) {
                var found = findFirstDocFile(items[index].children);
                if (found) {
                    return found;
                }
            }
        }
        return null;
    }

    /** 三栏文档预览：左侧文件树、中间正文、右侧 TOC 目录 */
    function openDocViewer(path, name) {
        layuiLayer().open({
            type: 1,
            title: '<i class="fa fa-book"></i> ' + escapeHtml(String(name).slice(0, 18)) + ' - 文档预览',
            area: ['90%', '90%'],
            maxmin: true,
            resize: true,
            content: docViewerMarkup(name),
            btn: ['关闭'],
            success: function () {
                loadDocViewerTree(path);
                loadDocViewerContent(path, name);
                setDocDownloadLink(path);
            }
        });
    }

    function docViewerMarkup(name) {
        return '<div class="doc-viewer">'
            + '<div class="doc-viewer-side">'
            + '<div class="doc-pane-title"><i class="fa fa-list"></i> 应用文档</div>'
            + '<div id="docViewerTree">加载中...</div></div>'
            + '<div class="doc-viewer-main">'
            + '<div class="doc-viewer-header">'
            + '<span id="docViewerTitle" class="doc-viewer-title">' + escapeHtml(name) + '</span>'
            + '<a href="javascript:void(0);" id="docViewerDownload" '
            + 'class="layui-btn layui-btn-sm layui-btn-normal"><i class="fa fa-download"></i> 下载</a></div>'
            + '<div id="docViewerContent" class="doc-markdown-body"></div></div>'
            + '<div class="doc-viewer-toc">'
            + '<div class="doc-pane-title"><i class="fa fa-sitemap"></i> 文档目录</div>'
            + '<nav id="docViewerToc" class="doc-toc"></nav></div></div>';
    }

    function setDocDownloadLink(path) {
        layuiJquery()('#docViewerDownload').attr('href', '/api/admin/apps/' + encodeURIComponent(state.docAppId)
            + '/docs/download?path=' + encodeURIComponent(path));
    }

    function loadDocViewerTree(activePath) {
        legacyAjax({
            url: '/api/admin/apps/' + encodeURIComponent(state.docAppId) + '/docs',
            type: 'GET',
            success: function (res) {
                var tree = (res.code === 0 && res.data && res.data.tree) || [];
                if (!tree.length) {
                    layuiJquery()('#docViewerTree').html('<div class="doc-tree-empty">暂无文档</div>');
                    return;
                }
                layuiJquery()('#docViewerTree').html(renderDocTree(tree, ''));
                bindDocViewerTreeEvents();
                markActiveDocFile(activePath);
            }
        });
    }

    /** 文档文件树：目录节点没有 path，需用父路径逐层拼接；仅 .md 文件可点开 */
    function renderDocTree(items, parentPath) {
        var html = '<ul class="doc-tree-list">';
        items.forEach(function (item) {
            var path = parentPath ? parentPath + '/' + item.name : item.name;
            if (item.type === 'directory') {
                html += '<li><div class="doc-tree-dir"><i class="fa fa-folder-open-o"></i><span>'
                    + escapeHtml(item.name) + '</span></div><div class="doc-tree-children">'
                    + renderDocTree(item.children || [], path) + '</div></li>';
                return;
            }
            html += '<li><div class="doc-tree-file" data-path="' + escapeHtml(path) + '" data-name="'
                + escapeHtml(item.name) + '"><i class="fa fa-file-text-o"></i><span>'
                + escapeHtml(item.name) + '</span></div></li>';
        });
        return html + '</ul>';
    }

    function markActiveDocFile(path) {
        var $ = layuiJquery();
        $('.doc-tree-file').removeClass('is-active');
        $('.doc-tree-file[data-path="' + path + '"]').addClass('is-active');
    }

    /** 文件树交互：目录折叠、点击文档就地切换正文（不重开弹层） */
    function bindDocViewerTreeEvents() {
        var $ = layuiJquery();
        $('.doc-tree-dir').off('click').on('click', function () {
            var $children = $(this).next('.doc-tree-children');
            var $icon = $(this).find('.fa-folder-open-o, .fa-folder-o');
            if ($children.is(':visible')) {
                $children.hide();
                $icon.removeClass('fa-folder-open-o').addClass('fa-folder-o');
                return;
            }
            $children.show();
            $icon.removeClass('fa-folder-o').addClass('fa-folder-open-o');
        });
        $('.doc-tree-file').off('click').on('click', function () {
            loadDocViewerContent($(this).data('path'), $(this).data('name'));
            setDocDownloadLink($(this).data('path'));
        });
    }

    function loadDocViewerContent(path, name) {
        var $ = layuiJquery();
        $('#docViewerContent').html('<div class="doc-loading">加载中...</div>');
        $('#docViewerTitle').text(name);
        $('#docViewerToc').html('');
        legacyAjax({
            url: '/api/admin/apps/' + encodeURIComponent(state.docAppId) + '/docs/content',
            type: 'GET',
            data: { path: path },
            success: function (res) {
                handleDocContentLoaded(res, path);
            },
            error: function () {
                $('#docViewerContent').html('<div class="doc-error">加载文档失败</div>');
            }
        });
    }

    function handleDocContentLoaded(res, path) {
        var $ = layuiJquery();
        if (res.code !== 0) {
            $('#docViewerContent').html('<div class="doc-error">'
                + escapeHtml(res.message || '加载失败') + '</div>');
            return;
        }
        try {
            var $content = $('#docViewerContent');
            $content.html(window.marked.parse((res.data || {}).content || ''));
            highlightDocCodeBlocks($content);
            renderDocViewerToc($content);
            markActiveDocFile(path);
        } catch (error) {
            $('#docViewerContent').html('<div class="doc-error">文档渲染失败</div>');
        }
    }

    /** 代码高亮：vue / html 等标记统一按 xml 处理，语言包缺失时保留原始文本 */
    function highlightDocCodeBlocks($content) {
        $content.find('pre code').each(function (index, block) {
            try {
                if (/\blanguage-(vue|html|xml)\b/.test(block.className)) {
                    block.className = 'language-xml';
                }
                hljs.highlightElement(block);
            } catch (error) {
                // 忽略单个代码块的高亮失败，不影响整篇文档阅读
            }
        });
    }

    /** 渲染右侧 TOC：为每个标题生成锚点 id，并绑定点击滚动 */
    function renderDocViewerToc($content) {
        var $ = layuiJquery();
        var headings = $content.find('h1, h2, h3, h4, h5, h6');
        var $toc = $('#docViewerToc');
        $toc.html('');
        if (!headings.length) {
            $toc.html('<div class="doc-toc-empty">无目录</div>');
            return;
        }
        var html = '<ul class="doc-toc-list">';
        headings.each(function (index, heading) {
            heading.id = 'doc-heading-' + index;
            html += renderDocTocItem(heading, index);
        });
        $toc.html(html + '</ul>');
        $toc.find('a').off('click').on('click', function () {
            var target = document.getElementById($(this).data('target'));
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
            $toc.find('a').removeClass('doc-toc-active');
            $(this).addClass('doc-toc-active');
        });
        bindDocTocScroll($content, headings);
    }

    function renderDocTocItem(heading, index) {
        var level = parseInt(heading.tagName.toLowerCase().substring(1), 10);
        var title = escapeHtml(heading.textContent);
        return '<li><a href="javascript:void(0);" class="doc-toc-link doc-toc-level-' + level
            + '" data-target="doc-heading-' + index + '" title="' + title + '">' + title + '</a></li>';
    }

    /** 正文滚动时联动高亮 TOC，并把当前项滚到可视区中间 */
    function bindDocTocScroll($content, headings) {
        var $ = layuiJquery();
        var $toc = $('#docViewerToc');
        var $tocScroll = $toc.closest('.doc-viewer-toc');
        $content.off('scroll.docToc').on('scroll.docToc', function () {
            var scrollTop = $content.scrollTop();
            var currentId = null;
            headings.each(function (index, heading) {
                if (heading.offsetTop - 10 <= scrollTop) {
                    currentId = 'doc-heading-' + index;
                }
            });
            if (!currentId) {
                return;
            }
            $toc.find('a').removeClass('doc-toc-active');
            keepTocItemVisible($tocScroll, $toc.find('a[data-target="' + currentId + '"]').addClass('doc-toc-active'));
        });
    }

    function keepTocItemVisible($tocScroll, current) {
        if (!$tocScroll.length || !current.length) {
            return;
        }
        var tocTop = $tocScroll.scrollTop();
        var itemTop = current[0].offsetTop;
        var itemHeight = current.outerHeight();
        if (itemTop < tocTop || itemTop + itemHeight > tocTop + $tocScroll.height()) {
            $tocScroll.scrollTop(itemTop - $tocScroll.height() / 2 + itemHeight / 2);
        }
    }

    function openAppSettingsDialog(app) {
        openActionDialog({
            kicker: '应用设置',
            title: '「' + appDisplayName(app) + '」应用设置',
            body: '<div class="panel-empty"><i class="fa fa-circle-o-notch fa-spin"></i>正在读取配置</div>'
        });
        api('/api/admin/apps/' + encodeURIComponent(app.app_id) + '/config').then(function (groups) {
            var list = Array.isArray(groups) ? groups : [];
            if (!list.length) {
                elements.actionDialogBody.innerHTML = emptyState('fa-cog', '该应用暂无配置项');
                return;
            }
            elements.actionDialogBody.innerHTML = '<div class="config-groups">' + list.map(renderConfigGroup).join('') + '</div>';
            elements.actionDialogFooter.innerHTML = actionDialogCancelButton()
                + '<button class="webos-button primary" type="button" data-config-save data-app-id="'
                + escapeHtml(app.app_id) + '"><i class="fa fa-check"></i>保存设置</button>';
        }).catch(function (error) {
            elements.actionDialogBody.innerHTML = emptyState('fa-exclamation-circle', error.message);
        });
    }

    function renderConfigGroup(group) {
        return '<section class="config-group"><h3>' + escapeHtml(group.title || '配置分组') + '</h3>'
            + (group.items || []).map(renderConfigItem).join('') + '</section>';
    }

    function configSwitchMarkup(item, value) {
        var enabled = value === '1' || value === 'true';

        return '<button class="status-switch" type="button" data-config-switch="' + escapeHtml(item.code)
            + '" aria-pressed="' + (enabled ? 'true' : 'false') + '"><i></i><span>'
            + (enabled ? '开启' : '关闭') + '</span></button>';
    }

    function configOptionsMarkup(item, value) {
        return '<select data-config-code="' + escapeHtml(item.code) + '">' + (item.options || []).map(function (option) {
            var optionValue = option && typeof option === 'object' ? option.value : option;
            var optionLabel = option && typeof option === 'object' ? option.label : option;

            return '<option value="' + escapeHtml(String(optionValue)) + '"'
                + (String(optionValue) === value ? ' selected' : '') + '>' + escapeHtml(String(optionLabel)) + '</option>';
        }).join('') + '</select>';
    }

    function renderConfigItem(item) {
        var value = item.value == null ? '' : String(item.value);
        var field = '';
        if (item.type === 'switch') {
            field = configSwitchMarkup(item, value);
        } else if (item.type === 'select') {
            field = configOptionsMarkup(item, value);
        } else if (item.type === 'textarea') {
            field = '<textarea rows="3" data-config-code="' + escapeHtml(item.code) + '">' + escapeHtml(value) + '</textarea>';
        } else if (item.type === 'number') {
            field = '<input type="number" data-config-code="' + escapeHtml(item.code) + '" value="' + escapeHtml(value) + '">';
        } else {
            field = '<input type="text" data-config-code="' + escapeHtml(item.code) + '" value="' + escapeHtml(value)
                + '"' + (item.type === 'image' ? ' placeholder="请输入图片地址"' : '') + '>';
        }

        return '<label class="config-field"><span>' + escapeHtml(item.name || item.code) + '</span>' + field
            + (item.tips ? '<small>' + escapeHtml(item.tips) + '</small>' : '') + '</label>';
    }

    function saveAppSettings(appId) {
        var payload = {};
        elements.actionDialogBody.querySelectorAll('[data-config-code]').forEach(function (field) {
            payload[field.dataset.configCode] = field.value;
        });
        elements.actionDialogBody.querySelectorAll('[data-config-switch]').forEach(function (field) {
            payload[field.dataset.configSwitch] = field.getAttribute('aria-pressed') === 'true' ? '1' : '0';
        });
        api('/api/admin/apps/' + encodeURIComponent(appId) + '/config', { method: 'PUT', body: payload })
            .then(function () {
                closeActionDialog();
                toast('应用设置已保存');
            })
            .catch(function (error) { toast(error.message, 'error'); });
    }

    function openEntryDialog(app) {
        var entries = state.flatMenus.filter(function (entry) { return entry.app_id === app.app_id; });
        var body = entries.length ? entries.map(function (entry) {
            var pinned = state.workspace.desktop_items.some(function (item) { return item.id === entry.id; });

            return '<div class="entry-row"><span class="start-app-item-icon"><i class="' + safeIcon(entry.icon)
                + '"></i></span><div class="entry-row-info"><strong>' + escapeHtml(entry.title) + '</strong><small>'
                + escapeHtml(entry.path) + '</small></div><div class="entry-row-actions">'
                + '<button class="small-action" type="button" data-launch-id="' + escapeHtml(entry.id) + '">打开</button>'
                + '<button class="small-action" type="button" data-entry-toggle="' + escapeHtml(entry.id) + '">'
                + (pinned ? '移出桌面' : '添加到桌面') + '</button></div></div>';
        }).join('') : emptyState('fa-bars', '该应用未声明后台菜单，可在安装时选择菜单挂载位置');

        openActionDialog({
            kicker: '入口管理',
            title: '「' + appDisplayName(app) + '」应用入口',
            plain: true,
            body: '<div class="entry-list">' + body + '</div>',
            footer: '<button class="webos-button secondary" type="button" data-action="close-action">关闭</button>'
        });
    }

    /**
     * 启动台：macOS Launchpad 风格的全功能浮层——全屏遮罩 + 顶部搜索框 + 分类卡片网格。
     * 与系统菜单同源（state.catalog.menus 菜单树）：按顶级分类逐层进入，
     * 目录显示为文件夹卡片（目录图标优先、文件夹兜底），叶子显示应用图标卡片；
     * 搜索时跨层级平铺匹配叶子。点击卡片打开/进入，Esc 或点击空白处关闭。
     */
    function openLauncherDialog() {
        closeLauncherDialog();
        var layer = document.createElement('div');
        layer.className = 'launcher-layer';
        layer.innerHTML = '<button class="launcher-close" type="button" aria-label="关闭" data-launcher-close><i class="fa fa-times"></i></button>'
            + '<div class="launcher-panel" role="dialog" aria-label="全部功能">'
            + '<div class="launcher-search"><i class="fa fa-search" aria-hidden="true"></i>'
            + '<input type="text" placeholder="搜索功能…" aria-label="搜索功能"></div>'
            + '<div class="launcher-crumbs" hidden></div>'
            + '<div class="launcher-grid"></div></div>';
        root.appendChild(layer);
        renderLauncherView(layer, [], '');
        var input = layer.querySelector('input');
        input.addEventListener('input', function () {
            renderLauncherView(layer, [], input.value.trim().toLowerCase());
        });
        // 卡片与面包屑点击：进入文件夹 / 打开叶子 / 返回上级；右上角关闭按钮、点击遮罩空白或按 Esc 关闭
        layer.addEventListener('click', function (event) {
            if (event.target.closest('[data-launcher-close]')) {
                closeLauncherDialog();
                return;
            }
            var folder = event.target.closest('[data-launcher-folder]');
            var leaf = event.target.closest('[data-launcher-leaf]');
            var crumb = event.target.closest('[data-launcher-crumb]');
            if (folder && !folder.disabled) {
                renderLauncherView(layer, folder.dataset.launcherFolder.split(',').map(Number), '');
                return;
            }
            if (leaf) {
                var entry = findEntry(leaf.dataset.launcherLeaf);
                if (entry && !openMenuByType(entry)) {
                    openEntry(entry);
                    closeLauncherDialog();
                }
                return;
            }
            if (crumb && !crumb.disabled) {
                var crumbPath = crumb.dataset.launcherCrumb;
                renderLauncherView(layer, crumbPath ? crumbPath.split(',').map(Number) : [], '');
                return;
            }
            // 空白区域点击关闭：非功能卡片（launcher-item）、非搜索框（launcher-search）区域即关闭；
            // 卡片/面包屑/关闭按钮在上方分支已 return，不会走到这里
            if (!event.target.closest('.launcher-item') && !event.target.closest('.launcher-search')) {
                closeLauncherDialog();
            }
        });
        layer.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') { closeLauncherDialog(); }
        });
        input.focus();
    }

    function closeLauncherDialog() {
        var layer = root.querySelector('.launcher-layer');
        if (layer) { layer.remove(); }
    }

    /** 按菜单 id 取 flattenMenus 平铺叶子：app_id 含祖先继承（开始菜单聚合同源），树原生节点仅顶层带 app_id */
    function findFlatLeaf(menuId) {
        var id = Number(menuId || 0);
        return state.flatMenus.find(function (candidate) {
            return candidate.menu_id === id;
        }) || null;
    }

    /**
     * 启动台叶子卡片：图标以开始菜单（系统菜单）为标准——app_id 取 flattenMenus
     * 继承后的值（深层叶子自身无 app_id，靠祖先继承），渲染复用开始菜单同款
     * entryIconMarkup（findApplication → applicationIconMarkup：icon_url 图片 → icon 字体，
     * 无应用回退 safeIcon 菜单图标）。
     */
    function launcherLeafMarkup(item) {
        var leaf = findFlatLeaf(item.id) || { app_id: item.app_id || '', icon: item.icon };
        return '<button class="launcher-item" type="button" data-launcher-leaf="menu-'
            + escapeHtml(String(item.id || '')) + '" title="' + escapeHtml(item.name || '') + '">'
            + entryIconMarkup(leaf, 'launcher-item-icon')
            + '<strong>' + escapeHtml(item.name || '未命名菜单') + '</strong></button>';
    }

    /**
     * 启动台分组卡片：与开始菜单应用聚合卡同标准——分组节点带 app_id（如「儿康管理」）
     * 时走 applicationIconMarkup 显示应用图标图片（icon_url 图片 → icon 字体）；
     * 无应用关联（如「前台用户」）显示 is-folder-icon 底色风格，与开始菜单目录卡片一致。
     * 点击行为同样对齐开始菜单：有应用关联的分组直接打开应用窗口（应用代表叶子），
     * 不再进入子菜单；纯目录分组（无 app_id）保持进入子目录。
     */
    function launcherFolderMarkup(item, path) {
        var leaf = findFlatLeaf(item.id) || { app_id: item.app_id || '', icon: item.icon };
        var application = leaf.app_id ? findApplication(leaf.app_id) : null;
        var iconMarkup = application
            ? applicationIconMarkup(application, 'launcher-item-icon')
            : '<span class="launcher-item-icon is-folder-icon"><i class="'
                + safeIcon(item.icon || 'fa fa-folder') + '"></i></span>';
        var representative = application
            ? state.flatMenus.find(function (candidate) { return candidate.app_id === application.app_id; })
            : null;
        var action = representative
            ? ' data-launcher-leaf="menu-' + escapeHtml(String(representative.menu_id)) + '"'
            : ' data-launcher-folder="' + escapeHtml(path.join(',')) + '"';
        return '<button class="launcher-item" type="button"' + action
            + ' title="' + escapeHtml(item.name || '') + '">'
            + iconMarkup
            + '<strong>' + escapeHtml(item.name || '未命名分组') + '</strong></button>';
    }

    /** 递归收集菜单树全部可打开叶子（搜索用） */
    function collectLauncherLeaves(items, output) {
        (items || []).forEach(function (item) {
            var children = Array.isArray(item.children) ? item.children : [];
            if (children.length) {
                collectLauncherLeaves(children, output);
                return;
            }
            if (openablePath(item)) { output.push(item); }
        });
    }

    /** 启动台视图：按顶级分类逐层进入（文件夹卡片 + 应用卡片）；搜索时跨层级平铺叶子 */
    function renderLauncherView(layer, path, query) {
        var crumbs = layer.querySelector('.launcher-crumbs');
        var grid = layer.querySelector('.launcher-grid');
        var current = state.catalog.menus || [];
        var nodes = current;
        for (var i = 0; i < path.length; i++) {
            nodes = (nodes[path[i]] && nodes[path[i]].children) || [];
        }

        if (query) {
            var leaves = [];
            collectLauncherLeaves(state.catalog.menus || [], leaves);
            var matched = leaves.filter(function (item) {
                // 与卡片图标同源：取 flattenMenus 继承后的 app_id 匹配应用名（树原生节点深层叶子无 app_id）
                var leaf = findFlatLeaf(item.id);
                var appId = (leaf && leaf.app_id) || item.app_id || '';
                var application = appId ? findCatalogApp(appId) : null;
                return String(item.name || '').toLowerCase().indexOf(query) >= 0
                    || (application && String(application.name || '').toLowerCase().indexOf(query) >= 0);
            });
            crumbs.hidden = true;
            grid.innerHTML = matched.length ? matched.map(launcherLeafMarkup).join('')
                : '<div class="launcher-empty"><i class="fa fa-search" aria-hidden="true"></i>没有匹配的功能</div>';
            return;
        }

        // 面包屑：全部功能 / 一级分组 / 子分组，点击任意层级返回
        var folders = (state.catalog.menus || []).length ? [['全部功能', []]] : [];
        (function buildCrumbs(items, trail) {
            for (var i = 0; i < trail.length; i++) {
                var node = items[trail[i]];
                if (!node) { break; }
                folders.push([node.name || '未命名分组', trail.slice(0, i + 1)]);
                items = node.children || [];
            }
        })(state.catalog.menus || [], path);
        crumbs.hidden = path.length === 0;
        crumbs.innerHTML = folders.map(function (crumb, index) {
            var isLast = index === folders.length - 1;
            return '<button type="button" data-launcher-crumb="'
                + escapeHtml(crumb[1].join(',')) + '"' + (isLast ? ' disabled' : '')
                + '>' + escapeHtml(crumb[0]) + '</button>';
        }).join('<i class="fa fa-angle-right" aria-hidden="true"></i>');

        var cards = '';
        nodes.forEach(function (item, index) {
            var children = Array.isArray(item.children) ? item.children : [];
            if (children.length) {
                cards += launcherFolderMarkup(item, path.concat(index));
                return;
            }
            if (openablePath(item)) { cards += launcherLeafMarkup(item); }
        });
        grid.innerHTML = cards || '<div class="launcher-empty"><i class="fa fa-folder-open" aria-hidden="true"></i>此分类下没有可打开的功能</div>';
    }

    function toggleEntryOnDesktop(button) {
        var entryId = button.dataset.entryToggle;
        toggleDesktopEntry(entryId);
        var pinned = state.workspace.desktop_items.some(function (item) { return item.id === entryId; });
        button.textContent = pinned ? '移出桌面' : '添加到桌面';
    }

    function runAppRowAction(appId, action) {
        var app = findCatalogApp(appId);
        if (!app) {
            return;
        }
        if (action === 'manage-entry') { openEntryDialog(app); }
        if (action === 'backup') { openBackupDialog(app); }
        if (action === 'docs') { openAppDocs(app.app_id); }
        if (action === 'manual-upgrade') { openManualUpgradeDialog(app); }
        if (action === 'export') { exportAppPackage(app); }
        if (action === 'settings') { openAppSettingsDialog(app); }
        if (action === 'uninstall') { openUninstallDialog(app); }
    }

    function handleAppCenterAction(event) {
        // 安装记录：点击左侧记录行切换右侧操作详情
        var recordRow = event.target.closest('[data-record-index]');
        if (recordRow) {
            var center = recordRow.closest('[data-app-center]');
            recordsSelectedIndex = Number(recordRow.dataset.recordIndex || 0);
            if (center) {
                refreshRecordsPanels(center);
            }
            return;
        }

        var statusToggle = event.target.closest('[data-toggle-app-status]');
        if (statusToggle) {
            toggleAppStatus(statusToggle.dataset.toggleAppStatus,
                statusToggle.getAttribute('aria-pressed') !== 'true', null, statusToggle);
            return;
        }

        // 应用更新状态栏刷新按钮：强制重新检查更新并刷新列表
        var updatesRefresh = event.target.closest('[data-updates-refresh]');
        if (updatesRefresh) {
            var updatesCenter = updatesRefresh.closest('[data-app-center]');
            if (updatesCenter) {
                refreshAppUpdates(updatesCenter);
            }
            return;
        }

        var passwordSubmit = event.target.closest('[data-password-submit]');
        if (passwordSubmit) { submitPasswordChange(); return; }

        var upload = event.target.closest('[data-app-upload]');
        if (upload) { openAppUploadDialog(); return; }

        var uninstallSubmit = event.target.closest('[data-uninstall-submit]');
        if (uninstallSubmit) { runUninstall(uninstallSubmit.dataset.appId, uninstallSubmit.dataset.appName); return; }

        // 未安装列表导出：复用已安装的导出实现
        var exportButton = event.target.closest('[data-export-id]');
        if (exportButton) {
            var exportTarget = findLocalApp(exportButton.dataset.exportId, exportButton.dataset.exportSource || 'installed');
            if (exportTarget) { exportAppPackage(exportTarget); }
            return;
        }

        var deleteButton = event.target.closest('[data-delete-app-id]');
        if (deleteButton) { openDeleteFilesDialog(deleteButton.dataset.deleteAppId, deleteButton.dataset.appName); return; }

        var deleteSubmit = event.target.closest('[data-delete-submit]');
        if (deleteSubmit) { runDeleteFiles(deleteSubmit.dataset.appId, deleteSubmit.dataset.appName); return; }

        var disableFirst = event.target.closest('[data-disable-first]');
        if (disableFirst) {
            var disableAppId = disableFirst.dataset.appId;
            closeActionDialog();
            toggleAppStatus(disableAppId, false, function () {
                openUninstallDialog(findCatalogApp(disableAppId)
                    || { app_id: disableAppId, name: disableFirst.dataset.appName || '' });
            });
            return;
        }

        var configSave = event.target.closest('[data-config-save]');
        if (configSave) { saveAppSettings(configSave.dataset.appId); return; }

        var configSwitch = event.target.closest('[data-config-switch]');
        if (configSwitch) {
            var enabled = configSwitch.getAttribute('aria-pressed') === 'true';
            configSwitch.setAttribute('aria-pressed', enabled ? 'false' : 'true');
            configSwitch.querySelector('span').textContent = enabled ? '关闭' : '开启';
            return;
        }

        var entryToggle = event.target.closest('[data-entry-toggle]');
        if (entryToggle) { toggleEntryOnDesktop(entryToggle); return; }

        var appAction = event.target.closest('[data-app-action]');
        if (appAction) { runAppRowAction(appAction.dataset.appId, appAction.dataset.appAction); }
    }

    /** 上传自定义壁纸：POST 文件到壁纸接口，成功后写入偏好并立即生效 */
    function uploadWallpaper(file) {
        var form = new FormData();
        form.append('wallpaper', file);
        var uploadUrl = root.dataset.workspaceUrl.replace(/\/workspace$/, '/wallpaper');
        api(uploadUrl, { method: 'POST', body: form }).then(function (result) {
            var url = result && result.url;
            if (!url) {
                throw new Error('上传结果无效');
            }
            state.workspace.preferences.wallpaper_url = url;
            applyWorkspacePreferences();
            // 清空缓存，让背景设置重绘时重新拉取壁纸库，新上传的壁纸立即可见
            state.wallpapers = null;
            savePreference('背景壁纸已更新');
        }).catch(function (error) {
            toast(error.message || '壁纸上传失败', 'error');
        });
    }

    /**
     * WebOS 开始菜单仅展示后台终端菜单：terminal_type 缺省按旧版后台菜单处理（与传统后台菜单目录一致）
     */
    function filterAdminMenus(menus) {
        var filtered = [];
        (menus || []).forEach(function (menu) {
            if (!menu || typeof menu !== 'object') { return; }
            var terminal = menu.terminal_type;
            if (terminal === undefined || terminal === null || terminal === '') { terminal = 'admin'; }
            if (terminal !== 'admin') { return; }
            var item = Object.assign({}, menu);
            if (Array.isArray(menu.children)) { item.children = filterAdminMenus(menu.children); }
            filtered.push(item);
        });
        return filtered;
    }

    /** 当前用户菜单目录中出现过 app_id 的应用集合：应用图标按「已分配」过滤的口径来源 */
    function visibleAppIds() {
        var ids = new Set();

        function collect(items) {
            (items || []).forEach(function (menu) {
                if (!menu || typeof menu !== 'object') { return; }
                if (menu.app_id) { ids.add(String(menu.app_id)); }
                collect(menu.children);
            });
        }

        collect(state.catalog.menus);
        return ids;
    }

    /**
     * 工作区入口是否仍对当前管理员分配：角色收回菜单/应用授权后，桌面与任务栏不再显示对应图标。
     * 应用中心仅超级管理员可用；OS 设置始终可用；菜单入口须存在于当前用户菜单目录；
     * 应用快捷方式须目录中仍有该应用的菜单。
     */
    function entryAssigned(id) {
        var entryId = String(id || '');
        if (entryId === 'webos-app-center') { return isSuperAdmin; }
        if (entryId === 'webos-settings') { return true; }
        if (state.flatMenus.some(function (item) { return item.id === entryId; })) { return true; }

        var appPrefix = 'app:';
        if (entryId.indexOf(appPrefix) === 0) {
            return visibleAppIds().has(entryId.slice(appPrefix.length));
        }

        return false;
    }

    /** 当前已分配（可显示）的桌面项：未分配入口不参与渲染、网格占用与找空位，但保留原始坐标待恢复 */
    function assignedDesktopItems() {
        return state.workspace.desktop_items.filter(function (item) {
            return entryAssigned(item.id);
        });
    }

    /**
     * 桌面布局归一化：应用停用期间其格子被其它图标占用后，重新启用时坐标冲突的图标
     * 自动按 (y,x) 顺序下移到第一个空白格（未冲突则保持原位），布局有变化时静默保存。
     * 隐藏（未分配）项不参与网格占用，原始坐标保留不动。
     */
    function normalizeDesktopLayout() {
        var items = assignedDesktopItems().slice().sort(function (a, b) {
            return (Number(a.y) || 0) - (Number(b.y) || 0) || (Number(a.x) || 0) - (Number(b.x) || 0);
        });
        if (!items.length) { return; }

        var occupied = new Set();
        var changed = false;
        items.forEach(function (item) {
            var x = Math.max(0, Math.min(99, Number(item.x) || 0));
            var y = Math.max(0, Math.min(99, Number(item.y) || 0));
            var guard = 0;
            while (occupied.has(x + ',' + y) && guard < 200) {
                y += 1;
                guard += 1;
            }
            occupied.add(x + ',' + y);
            if (item.x !== x || item.y !== y) {
                item.x = x;
                item.y = y;
                changed = true;
            }
        });

        if (changed) { saveWorkspace(false).catch(function () {}); }
    }

    /**
     * 应用中心/开始菜单目录：直接复用系统接口作为数据源（与传统后台应用管理同一数据源，便于统一维护）
     * 已安装应用列表：GET /api/admin/apps；后台菜单目录：GET /api/admin/menus/user
     * 操作记录不再随目录预取，切换到「安装记录」时按需读取 /api/admin/app-logs
     */
    function loadCatalog() {
        // GET 请求附时间戳破坏 HTTP 缓存：菜单移动/应用安装后 refreshCatalog 必须拿到实时数据，
        // 避免个别浏览器对同 URL 短时间内的 GET 复用缓存导致界面显示旧分组
        var cacheBust = '?_t=' + Date.now();
        return Promise.all([
            api('/api/admin/apps' + cacheBust),
            api('/api/admin/menus/user' + cacheBust)
        ]).then(function (responses) {
            state.catalog.applications = responses[0] || [];
            state.catalog.menus = filterAdminMenus(responses[1]);
            state.catalog.operation_logs = [];
            state.flatMenus = flattenMenus(state.catalog.menus);
            return state.catalog;
        });
    }

    function refreshCatalog() {
        return loadCatalog().then(function () {
            renderStartMenu();
            // 菜单目录刷新后同步重绘桌面与任务栏（角色授权/应用启停变化后图标即时过滤，
            // 归一化让重新启用的图标在坐标被占用时自动落到空白格）
            normalizeDesktopLayout();
            renderDesktop();
            renderPinnedApps();
        });
    }

    /** 桌面右键“刷新”：重新拉取工作区与菜单目录并重绘桌面、开始菜单、任务栏 */
    function refreshWorkspace() {
        return api(root.dataset.workspaceUrl).then(function (workspace) {
            state.workspace = workspace || state.workspace;
            state.workspace.preferences = normalizePreferences(state.workspace.preferences);
            applyWorkspacePreferences();
            renderDesktop();
            return refreshCatalog();
        });
    }

    function loadNotifications() {
        return Promise.all([
            api('/api/admin/notifications/panel'),
            api('/api/admin/notifications/badge')
        ]).then(function (responses) {
            var panel = responses[0] || {};
            var badge = responses[1] || {};
            var total = Number(badge.unread || 0) + Number(badge.todo_total || 0);
            elements.notificationBadge.textContent = total > 99 ? '99+' : String(total);
            elements.notificationBadge.hidden = total === 0;
            elements.notificationSummary.textContent = total ? total + ' 条未处理消息' : '当前没有未读消息';
            renderNotifications(panel);
        }).catch(function (error) {
            elements.notificationSummary.textContent = '通知读取失败';
            elements.notificationList.innerHTML = emptyState('fa-exclamation-circle', error.message);
        });
    }

    /** 官网动态正文链接化：http(s) 链接转新窗口 <a>（输入需已 escapeHtml 转义；剥末尾标点，与框架后台 dashboard 一致） */
    function linkifyOfficialNews(text) {
        return text.replace(/(https?:\/\/[^\s]+)/g, function (url) {
            var cleanUrl = url.replace(/[。，,；;！!？?、"')】\]]+$/, '');
            return '<a href="' + cleanUrl + '" target="_blank" rel="noopener">' + cleanUrl + '</a>';
        });
    }

    /**
     * 官网动态窗口渲染：远程读取官网公开动态接口（与框架后台 dashboard 同一数据源），
     * 列表含正文（链接化）、图片网格（点击新标签查看原图）与发布时间；窗口关闭后异步返回不再写 DOM。
     */
    function renderOfficialNews() {
        var shell = document.querySelector('[data-official-news]');
        if (!shell) {
            return;
        }
        shell.innerHTML = '<div class="official-news-head"><h3>官网动态</h3>'
            + '<a href="https://www.cmspro.cn/news" target="_blank" rel="noopener">更多&nbsp;&gt;&gt;</a></div>'
            + '<div class="official-news-list" data-official-news-list>'
            + '<p class="official-news-empty">加载中...</p></div>';
        var listEl = shell.querySelector('[data-official-news-list]');
        fetch('https://www.cmspro.cn/api/home/moments/latest?limit=6', { headers: { Accept: 'application/json' } })
            .then(function (response) { return response.json(); })
            .then(function (res) {
                if (!shell.isConnected || !listEl.isConnected) {
                    return;
                }
                var list = res && res.code === 0 && Array.isArray(res.data) ? res.data : [];
                listEl.innerHTML = list.length ? list.map(function (item) {
                    var images = Array.isArray(item.images) ? item.images.filter(Boolean) : [];
                    return '<article class="official-news-item">'
                        + '<div class="official-news-content">'
                        + linkifyOfficialNews(escapeHtml(String(item.content || ''))) + '</div>'
                        + (images.length
                            ? '<div class="official-news-images">' + images.map(function (img) {
                                return '<img src="' + escapeHtml(String(img)) + '" loading="lazy" alt="动态图片">';
                            }).join('') + '</div>'
                            : '')
                        + '<div class="official-news-time">' + escapeHtml(String(item.create_time || '')) + '</div>'
                        + '</article>';
                }).join('') : '<p class="official-news-empty">暂无动态</p>';
                // 点击图片弹出大屏预览，同一条动态多张图片可左右切换（layer.photos 内置相册组件，与框架后台一致）
                listEl.querySelectorAll('.official-news-images').forEach(function (group) {
                    group.addEventListener('click', function (event) {
                        var img = event.target.closest('img');
                        if (!img) {
                            return;
                        }
                        var layer = layuiLayer();
                        if (!layer) {
                            window.open(img.getAttribute('src'), '_blank', 'noopener');
                            return;
                        }
                        var photos = [];
                        group.querySelectorAll('img').forEach(function (node) {
                            var src = node.getAttribute('src');
                            photos.push({ src: src, thumb: src });
                        });
                        layer.photos({
                            photos: { title: '', start: Array.prototype.indexOf.call(group.querySelectorAll('img'), img), data: photos },
                            anim: 5
                        });
                    });
                });
            })
            .catch(function () {
                if (listEl.isConnected) {
                    listEl.innerHTML = '<p class="official-news-empty">暂无动态</p>';
                }
            });
    }

    /** 通知中心当前 Tab：todos 待办 / notifications 通知 */
    var notificationActiveTab = 'todos';

    /** 通知中心面板数据缓存（todos + notifications + unread） */
    var notificationPanelData = {};

    /**
     * 通知时间显示：今天显示 HH:MM，昨天显示「昨天 HH:MM」，更早显示 月-日 HH:MM
     */
    function compactNoticeTime(value) {
        var text = String(value || '');
        var match = text.match(/^(\d{4})-(\d{2})-(\d{2}) (\d{2}:\d{2})/);
        if (!match) {
            return text;
        }
        var today = new Date();
        var pad = function (n) { return (n < 10 ? '0' : '') + n; };
        var stamp = match[2] + '-' + match[3];
        var yesterday = new Date(today.getTime() - 86400000);
        if (stamp === pad(today.getMonth() + 1) + '-' + pad(today.getDate())) {
            return match[4];
        }
        if (stamp === pad(yesterday.getMonth() + 1) + '-' + pad(yesterday.getDate())) {
            return '昨天 ' + match[4];
        }
        return match[2] + '-' + match[3] + ' ' + match[4];
    }

    /** 切换通知中心 Tab（待办 / 通知） */
    function setNotificationTab(tab) {
        notificationActiveTab = tab === 'notifications' ? 'notifications' : 'todos';
        document.querySelectorAll('[data-notification-tab]').forEach(function (button) {
            var active = button.dataset.notificationTab === notificationActiveTab;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        if (elements.notificationTodos && elements.notificationList) {
            elements.notificationTodos.hidden = notificationActiveTab !== 'todos';
            elements.notificationList.hidden = notificationActiveTab !== 'notifications';
        }
    }

    /** 待办列表：图标 + 标题 + 待处理数量 + 数字徽标 + 箭头，点击跳转处理页面并标记已读 */
    function renderNotificationTodos() {
        var todos = notificationPanelData.todos || [];
        if (!elements.notificationTodos) {
            return;
        }
        elements.notificationTodos.innerHTML = todos.length ? todos.map(function (todo) {
            return '<a class="notification-todo" href="' + escapeHtml(todo.link || '#') + '" target="_blank" rel="noopener"'
                + ' data-todo-key="' + escapeHtml(todo.key || '') + '" data-todo-count="' + Number(todo.count || 0) + '">'
                + '<span class="notification-todo-icon"><i class="fa fa-list-ul"></i></span>'
                + '<span class="notification-todo-body"><strong>' + escapeHtml(todo.title) + '</strong>'
                + '<small>待处理 ' + Number(todo.count || 0) + ' 项</small></span>'
                + '<span class="notification-todo-badge"><i></i>' + Number(todo.count || 0) + '</span>'
                + '<i class="fa fa-angle-right notification-todo-arrow"></i></a>';
        }).join('') : emptyState('fa-check-circle-o', '暂无待办事项');
    }

    /** 通知列表：图标 + 标题 + 内容 + 时间 + 未读蓝点，点击跳转通知链接 */
    function renderNotificationItems() {
        if (!elements.notificationList) {
            return;
        }
        // 面板为未读口径：系统 panel 返回最新 10 条通知（含已读），这里过滤已读只显示未读
        // （通知中心窗口为全量口径，两者分工不同）；点击通知/全部已读后列表随 loadNotifications 收敛。
        var notices = (notificationPanelData.notifications || []).filter(function (notice) {
            return Number(notice.is_read) !== 1;
        });
        elements.notificationList.innerHTML = notices.length ? notices.map(function (notice) {
            var link = notice.link ? ' data-notification-link="' + escapeHtml(notice.link) + '"' : '';
            var noticeId = notice.id ? ' data-notification-id="' + escapeHtml(notice.id) + '"' : '';
            return '<article class="notification-item"' + link + noticeId + '>'
                + '<span class="notification-item-icon"><i class="fa ' + (notice.level === 'error' ? 'fa-exclamation-circle' : 'fa-bell-o') + '"></i></span>'
                + '<div class="notification-item-body"><strong>' + escapeHtml(notice.title) + '</strong><span>' + escapeHtml(notice.content || '') + '</span></div>'
                + '<div class="notification-item-meta"><time>' + escapeHtml(compactNoticeTime(notice.create_time)) + '</time>'
                + '<i class="notification-dot"></i></div></article>';
        }).join('') : emptyState('fa-bell-o', '暂无新通知');
    }

    function renderNotifications(panel) {
        notificationPanelData = panel || {};
        renderNotificationTodos();
        renderNotificationItems();
        // 通知中心窗口打开时同步刷新其侧栏徽标与待办列表（待办列表为全量口径，含已读）
        var centerWindow = state.windows.get(windowKey(notificationCenterEntry()));
        if (centerWindow) {
            rerenderWindowNav(centerWindow);
            if (notificationCenterState.tab === 'todos') {
                loadNotificationCenterTodos(centerWindow.element);
            }
        }
    }

    /** 待办卡片列表（通知中心窗口用，含已读条目；面板未读列表共用 is_read 缺省时的默认样式） */
    function renderTodoCards(todos) {
        return todos.length ? todos.map(function (todo) {
            var isRead = todo.is_read === true || Number(todo.seen || 0) >= Number(todo.count || 0);
            return '<a class="notification-todo' + (isRead ? ' is-read' : '') + '" href="' + escapeHtml(todo.link || '#') + '" target="_blank" rel="noopener"'
                + ' data-todo-key="' + escapeHtml(todo.key || '') + '" data-todo-count="' + Number(todo.count || 0) + '">'
                + '<span class="notification-todo-icon"><i class="fa ' + (isRead ? 'fa-check-circle-o' : 'fa-list-ul') + '"></i></span>'
                + '<span class="notification-todo-body"><strong>' + escapeHtml(todo.title) + '</strong>'
                + '<small>' + (isRead ? '已处理' : '待处理 ' + Number(todo.count || 0) + ' 项') + '</small></span>'
                + '<span class="notification-todo-badge"><i></i>' + (isRead ? '已读' : Number(todo.count || 0)) + '</span>'
                + '<i class="fa fa-angle-right notification-todo-arrow"></i></a>';
        }).join('') : emptyState('fa-check-circle-o', '暂无待办事项');
    }

    /** 待办点击标记已读：调系统 todo-read 接口刷新徽标，失败不阻断跳转 */
    function markTodoRead(key, count) {
        return api('/api/admin/notifications/todo-read', {
            method: 'POST',
            body: { key: key, count: Number(count || 0) }
        }).then(loadNotifications).catch(function () { /* 标记失败不阻断跳转 */ });
    }

    /** 通知中心窗口渲染：按 Tab 加载待办 / 通知列表 */
    function renderNotificationCenter(tab) {
        notificationCenterState.tab = tab === 'notifications' ? 'notifications' : 'todos';
        if (notificationCenterState.tab !== 'notifications') {
            notificationCenterState.page = 1;
        }
        var appWindow = state.windows.get(windowKey(notificationCenterEntry()));
        if (!appWindow) {
            return;
        }
        appWindow.element.querySelectorAll('[data-special-tab]').forEach(function (button) {
            button.classList.toggle('is-active', button.dataset.specialTab === notificationCenterState.tab);
        });
        rerenderWindowNav(appWindow);
        var container = appWindow.element.querySelector('[data-webos-notifications]');
        if (!container) {
            return;
        }
        var titles = {
            todos: ['待办事项', '需要您处理的事项，点击进入对应处理页面'],
            notifications: ['通知消息', '系统与应用的通知消息，点击查看详情']
        };
        var title = titles[notificationCenterState.tab];
        container.innerHTML = '<div class="app-center-toolbar"><div><h1>' + title[0] + '</h1><p>' + title[1] + '</p></div>'
            + notificationToolbarActionsMarkup()
            + '</div>'
            + '<div class="app-center-status" data-notification-status><i class="fa fa-circle-o-notch fa-spin"></i>正在读取数据</div>'
            + '<div class="app-center-content" data-notification-content></div>';
        if (notificationCenterState.tab === 'todos') {
            loadNotificationCenterTodos(container);
            return;
        }
        loadNotificationCenterNotices(container, 1);
    }

    /** 通知中心窗口工具栏右侧动作区：全部/已读/未读筛选按钮组 + 全部已读按钮 */
    function notificationToolbarActionsMarkup() {
        var current = notificationCenterState.tab === 'todos'
            ? notificationCenterState.todoFilter
            : notificationCenterState.noticeFilter;
        var filters = [['all', '全部'], ['unread', '未读'], ['read', '已读']];
        return '<div class="notification-toolbar-actions">'
            + '<div class="notification-filter">'
            + filters.map(function (item) {
                return '<button type="button" class="notification-filter-btn' + (current === item[0] ? ' is-active' : '') + '"'
                    + ' data-notification-filter="' + item[0] + '">' + item[1] + '</button>';
            }).join('')
            + '</div>'
            + '<button type="button" class="webos-button secondary notification-read-all" data-notification-read-all>'
            + '<i class="fa fa-check-double"></i>全部已读</button>'
            + '</div>';
    }

    /** 通知中心窗口「全部已读」：待办逐条标记已处理（幂等），通知走系统 read-all；完成后刷新列表与徽标 */
    function markAllNotificationCenterRead(button) {
        var isTodos = notificationCenterState.tab === 'todos';
        if (button) {
            button.disabled = true;
            button.innerHTML = '<i class="fa fa-circle-o-notch fa-spin"></i>处理中…';
        }
        var finish = function () {
            if (button) {
                button.disabled = false;
                button.innerHTML = '<i class="fa fa-check-double"></i>全部已读';
            }
        };
        var refresh = function () {
            var appWindow = state.windows.get(windowKey(notificationCenterEntry()));
            if (appWindow) {
                if (isTodos) {
                    loadNotificationCenterTodos(appWindow.element);
                } else {
                    loadNotificationCenterNotices(appWindow.element, 1);
                }
            }
        };
        var request = isTodos
            ? api('/admin/cmspro/webos/api/all-todos').then(function (todos) {
                var unread = (Array.isArray(todos) ? todos : []).filter(function (todo) { return !todo.is_read; });
                if (!unread.length) {
                    return Promise.resolve();
                }
                return Promise.all(unread.map(function (todo) {
                    return api('/api/admin/notifications/todo-read', {
                        method: 'POST',
                        body: { key: todo.key, count: Number(todo.count || 0) }
                    });
                })).then(function () {});
            })
            : api('/api/admin/notifications/read-all', { method: 'POST' }).then(function () {});
        request.then(function () {
            finish();
            loadNotifications();
            refresh();
            toast(isTodos ? '待办已全部标记为已处理' : '通知已全部标记为已读');
        }).catch(function () {
            finish();
            toast('操作失败，请稍后重试', 'error');
        });
    }

    /** 通知中心窗口-待办列表：显示全部待办（含已读），系统面板数据仅用于侧栏徽标（未读口径） */
    function loadNotificationCenterTodos(container) {
        var status = container.querySelector('[data-notification-status]');
        var content = container.querySelector('[data-notification-content]');
        api('/api/admin/notifications/panel').then(function (panel) {
            notificationPanelData = panel || {};
            rerenderWindowNav(state.windows.get(windowKey(notificationCenterEntry())));
        }).catch(function () { /* 徽标刷新失败不阻断列表展示 */ });
        api('/admin/cmspro/webos/api/all-todos').then(function (todos) {
            todos = Array.isArray(todos) ? todos : [];
            var filter = notificationCenterState.todoFilter;
            var filtered = todos.filter(function (todo) {
                if (filter === 'unread') { return !todo.is_read; }
                if (filter === 'read') { return todo.is_read; }
                return true;
            });
            var readCount = todos.filter(function (todo) { return todo.is_read; }).length;
            if (status) {
                status.innerHTML = '<i class="fa fa-list-ul"></i>共 ' + filtered.length + ' 类待办事项'
                    + (filter === 'all' && readCount ? '，其中 ' + readCount + ' 类已处理' : '');
            }
            content.innerHTML = '<div class="notification-page-todos">' + renderTodoCards(filtered) + '</div>';
        }).catch(function (error) {
            if (status) {
                status.classList.add('is-error');
                status.innerHTML = '<i class="fa fa-exclamation-circle"></i>' + escapeHtml(error.message);
            }
            content.innerHTML = emptyState('fa-list-ul', '暂时无法读取待办事项');
        });
    }

    /** 通知中心窗口-通知列表：系统通知分页数据（支持已读/未读筛选，is_read 由系统接口过滤） */
    function loadNotificationCenterNotices(container, page) {
        notificationCenterState.page = page;
        var status = container.querySelector('[data-notification-status]');
        var content = container.querySelector('[data-notification-content]');
        var url = '/api/admin/notifications?per_page=15&page=' + page;
        if (notificationCenterState.noticeFilter === 'unread') {
            url += '&is_read=0';
        } else if (notificationCenterState.noticeFilter === 'read') {
            url += '&is_read=1';
        }
        api(url).then(function (body) {
            // api() 已 resolve 响应的 data 部分（{items, pagination}），无需再取 .data
            var notices = (body && body.items) || [];
            var pagination = (body && body.pagination) || {};
            if (status) {
                status.innerHTML = '<i class="fa fa-bell-o"></i>共 ' + Number(pagination.total || notices.length) + ' 条通知';
            }
            content.innerHTML = '<div class="notification-page-list">' + renderNoticeRows(notices) + '</div>'
                + noticePaginationMarkup(pagination);
        }).catch(function (error) {
            if (status) {
                status.classList.add('is-error');
                status.innerHTML = '<i class="fa fa-exclamation-circle"></i>' + escapeHtml(error.message);
            }
            content.innerHTML = emptyState('fa-bell-o', '暂时无法读取通知消息');
        });
    }

    /** 通知行：图标 + 标题 + 内容 + 相对时间 + 未读点，点击标记已读并打开详情链接 */
    function renderNoticeRows(notices) {
        return notices.length ? notices.map(function (notice) {
            var isRead = Number(notice.is_read) === 1;
            return '<button type="button" class="notification-item' + (isRead ? ' is-read' : '') + '"'
                + ' data-notification-notice="' + escapeHtml(notice.id || '') + '"'
                + (notice.link ? ' data-notice-link="' + escapeHtml(notice.link) + '"' : '') + '>'
                + '<span class="notification-item-icon"><i class="fa ' + (notice.level === 'error' ? 'fa-exclamation-circle' : 'fa-bell-o') + '"></i></span>'
                + '<div class="notification-item-body"><strong>' + escapeHtml(notice.title) + '</strong><span>' + escapeHtml(notice.content || '') + '</span></div>'
                + '<div class="notification-item-meta"><time>' + escapeHtml(compactNoticeTime(notice.create_time)) + '</time>'
                + (isRead ? '' : '<i class="notification-dot"></i>') + '</div></button>';
        }).join('') : emptyState('fa-bell-o', '暂无通知消息');
    }

    /** 通知分页栏：上一页 / 下一页 + 页码信息 */
    function noticePaginationMarkup(pagination) {
        var current = Number(pagination.current_page || 1);
        var last = Number(pagination.last_page || 1);
        var total = Number(pagination.total || 0);
        if (last <= 1) {
            return '';
        }
        return '<div class="notification-page-nav">'
            + '<button class="webos-button secondary compact" type="button" data-notification-goto-page="' + (current - 1) + '"' + (current <= 1 ? ' disabled' : '') + '>上一页</button>'
            + '<span>第 ' + current + ' / ' + last + ' 页 · 共 ' + total + ' 条</span>'
            + '<button class="webos-button secondary compact" type="button" data-notification-goto-page="' + (current + 1) + '"' + (current >= last ? ' disabled' : '') + '>下一页</button>'
            + '</div>';
    }

    function closePanels(except) {
        [['start', elements.startPanel, elements.startButton], ['notifications', elements.notificationPanel, elements.notificationButton], ['account', elements.accountPanel, elements.accountButton], ['calendar', elements.calendarPanel, elements.clockButton]].forEach(function (panel) {
            if (panel[0] !== except) {
                panel[1].hidden = true;
                panel[2].classList.remove('is-active');
                panel[2].setAttribute('aria-expanded', 'false');
            }
        });
    }

    function isPanelToggle(target) {
        return [elements.startButton, elements.notificationButton, elements.accountButton, elements.clockButton].some(function (button) {
            return button && button.contains(target);
        });
    }

    function padNumber(value) {
        return String(value).padStart(2, '0');
    }

    function formatDateKey(date) {
        return date.getFullYear() + '-' + padNumber(date.getMonth() + 1) + '-' + padNumber(date.getDate());
    }

    function parseDateKey(key) {
        var parts = String(key).split('-').map(Number);
        if (parts.length !== 3 || parts.some(function (value) { return isNaN(value); })) {
            return null;
        }

        return new Date(parts[0], parts[1] - 1, parts[2]);
    }

    function describeDate(date) {
        return date.getFullYear() + '年' + (date.getMonth() + 1) + '月' + date.getDate() + '日 '
            + date.toLocaleDateString('zh-CN', { weekday: 'long' });
    }

    function calendarMonthKey(date) {
        return date.getFullYear() + '-' + padNumber(date.getMonth() + 1);
    }

    function calendarDayInfo(key) {
        var days = state.calendarMonths.get(key.slice(0, 7));

        return days ? (days[key] || null) : null;
    }

    function calendarDayLabel(info) {
        if (!info) {
            return '';
        }
        if (info.term) {
            return info.term;
        }

        return info.lunar_day === '初一' ? info.lunar_month : info.lunar_day;
    }

    function describeLunar(key) {
        var info = calendarDayInfo(key);
        if (!info) {
            return '';
        }

        return ' · 农历' + info.lunar_month + info.lunar_day + (info.term ? ' · ' + info.term : '');
    }

    function calendarDayMarkup(date, view, todayKey, selectedKey) {
        var key = formatDateKey(date);
        var classes = ['calendar-day'];
        if (date.getMonth() !== view.getMonth()) {
            classes.push('is-outside');
        }
        if (key === todayKey) {
            classes.push('is-today');
        }
        if (key === selectedKey) {
            classes.push('is-selected');
        }
        var info = calendarDayInfo(key);
        var labelClass = 'calendar-day-label' + (info && info.term ? ' is-term' : '');

        return '<button class="' + classes.join(' ') + '" type="button" data-calendar-date="' + key + '">'
            + '<span class="calendar-day-number">' + date.getDate() + '</span>'
            + '<small class="' + labelClass + '">' + escapeHtml(calendarDayLabel(info)) + '</small></button>';
    }

    function calendarDaysMarkup(view, todayKey, selectedKey) {
        var first = new Date(view.getFullYear(), view.getMonth(), 1);
        var offset = (first.getDay() + 6) % 7;
        var cells = '';

        for (var index = 0; index < 42; index += 1) {
            var date = new Date(first.getFullYear(), first.getMonth(), first.getDate() - offset + index);
            cells += calendarDayMarkup(date, view, todayKey, selectedKey);
        }

        return cells;
    }

    function loadCalendarMonth(date) {
        var key = calendarMonthKey(date);
        if (!root.dataset.calendarUrl || state.calendarMonths.has(key) || state.calendarPending.has(key)) {
            return;
        }
        state.calendarPending.add(key);
        api(root.dataset.calendarUrl + '?month=' + encodeURIComponent(key)).then(function (payload) {
            state.calendarPending.delete(key);
            state.calendarMonths.set(key, (payload && payload.days) || {});
            renderCalendar();
        }).catch(function () {
            state.calendarPending.delete(key);
            state.calendarMonths.set(key, {});
        });
    }

    function renderCalendar() {
        var today = new Date();
        var view = state.calendarView || new Date(today.getFullYear(), today.getMonth(), 1);
        state.calendarView = view;
        var todayKey = formatDateKey(today);
        var selected = state.calendarSelected ? parseDateKey(state.calendarSelected) : null;
        var focusKey = formatDateKey(selected || today);

        elements.calendarTitle.textContent = view.getFullYear() + '年' + (view.getMonth() + 1) + '月';
        elements.calendarSubtitle.textContent = (selected ? '已选 ' : '今天 ')
            + describeDate(selected || today) + describeLunar(focusKey);
        elements.calendarToday.hidden = !selected || state.calendarSelected === todayKey;
        elements.calendarGrid.innerHTML = calendarDaysMarkup(view, todayKey, state.calendarSelected);

        loadCalendarMonth(view);
        loadCalendarMonth(new Date(view.getFullYear(), view.getMonth() - 1, 1));
        loadCalendarMonth(new Date(view.getFullYear(), view.getMonth() + 1, 1));
    }

    function openCalendar() {
        var today = new Date();
        state.calendarView = new Date(today.getFullYear(), today.getMonth(), 1);
        state.calendarSelected = '';
        renderCalendar();
    }

    function shiftCalendarMonth(offset) {
        var view = state.calendarView || new Date();
        state.calendarView = new Date(view.getFullYear(), view.getMonth() + offset, 1);
        renderCalendar();
    }

    function selectCalendarDate(key) {
        var date = parseDateKey(key);
        if (!date) {
            return;
        }
        state.calendarSelected = key;
        state.calendarView = new Date(date.getFullYear(), date.getMonth(), 1);
        renderCalendar();
    }

    function applyTaskbarPosition() {
        var allowed = ['top', 'bottom', 'left', 'right'];
        var position = state.workspace.preferences.taskbar_position || 'bottom';
        if (allowed.indexOf(position) < 0) {
            position = 'bottom';
        }
        state.workspace.preferences.taskbar_position = position;
        root.dataset.taskbarPosition = position;
        document.querySelectorAll('[data-set-taskbar-position]').forEach(function (button) {
            var active = button.dataset.setTaskbarPosition === position;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
    }

    function applyWorkspacePreferences() {
        var preferences = state.workspace.preferences;
        root.dataset.wallpaper = preferences.wallpaper || 'webos-default';
        // 自定义壁纸优先，未设置时回退系统默认壁纸
        var wallpaperUrl = preferences.wallpaper_url
            ? '/' + String(preferences.wallpaper_url).replace(/^\/+/, '')
            : (root.dataset.wallpaperUrl || '');
        root.style.setProperty('--webos-wallpaper-image', 'url("' + wallpaperUrl + '")');
        root.dataset.motion = preferences.motion === false ? 'off' : 'on';
        root.style.setProperty('--webos-window-width', clampWindowRatio(preferences.window_width, 78) + '%');
        root.style.setProperty('--webos-window-height', clampWindowRatio(preferences.window_height, 80) + '%');
        applyTaskbarPosition();
        updateClock();
    }

    function savePreference(message) {
        renderWebosSettings();
        saveWorkspace(false).then(function () { toast(message); });
    }

    function setTaskbarPosition(position) {
        if (['top', 'bottom', 'left', 'right'].indexOf(position) < 0) {
            return;
        }
        state.workspace.preferences.taskbar_position = position;
        applyWorkspacePreferences();
        closePanels();
        savePreference('任务栏位置已更新');
    }

    function setWebosPreference(name, value, message) {
        state.workspace.preferences[name] = value;
        applyWorkspacePreferences();
        savePreference(message);
    }

    function updateWindowSizeLabel(slider) {
        var value = slider.parentNode.querySelector('b');
        if (value) {
            value.textContent = slider.value + '%';
        }
    }

    function setWindowSizePreference(name, value) {
        if (name !== 'window_width' && name !== 'window_height') {
            return;
        }
        setWebosPreference(name, clampWindowRatio(value, name === 'window_width' ? 78 : 80), '窗口默认尺寸已更新');
    }

    function togglePanel(name, panel, button) {
        var opening = panel.hidden;
        closePanels(name);
        panel.hidden = !opening;
        button.classList.toggle('is-active', opening);
        button.setAttribute('aria-expanded', opening ? 'true' : 'false');
        if (opening && name === 'notifications') {
            loadNotifications();
        }
        if (opening && name === 'start') {
            renderStartMenu();
            window.setTimeout(function () { elements.startSearch.focus(); }, 20);
        }
        if (opening && name === 'calendar') {
            openCalendar();
        }
    }

    function updateClock() {
        var now = new Date();
        var preferences = state.workspace.preferences || {};
        var options = { hour: '2-digit', minute: '2-digit', hour12: preferences.clock_format === '12h' };
        if (preferences.show_seconds) {
            options.second = '2-digit';
        }
        var time = now.toLocaleTimeString('zh-CN', options);
        var date = now.toLocaleDateString('zh-CN', { year: 'numeric', month: '2-digit', day: '2-digit' });
        elements.clockDate.textContent = date;
        elements.clockWeekday.textContent = now.toLocaleDateString('zh-CN', { weekday: 'short' });
        elements.clockTime.textContent = time;
        elements.lockDate.textContent = now.toLocaleDateString('zh-CN', { year: 'numeric', month: 'long', day: 'numeric', weekday: 'long' });
        elements.lockTime.textContent = time;
    }

    /** 按 id 查找桌面图标按钮（id 含特殊字符，避开 querySelector 转义） */
    function desktopButtonById(id) {
        var found = null;
        elements.desktopIcons.querySelectorAll('[data-desktop-id]').forEach(function (button) {
            if (!found && button.dataset.desktopId === id) {
                found = button;
            }
        });
        return found;
    }

    /** 将桌面选中集同步到按钮高亮态 */
    function syncDesktopSelection() {
        elements.desktopIcons.querySelectorAll('[data-desktop-id]').forEach(function (button) {
            button.classList.toggle('is-selected', state.desktopSelection.has(button.dataset.desktopId));
        });
    }

    /**
     * 桌面图标拖动：支持单图标与多选（框选/Ctrl 点选）整体移动。
     * 多选时保持图标相对位置，落点按网格吸附，格子冲突自动向下寻找空位。
     */
    function dragDesktopIcon(button, event) {
        var id = button.dataset.desktopId;
        var item = state.workspace.desktop_items.find(function (entry) { return entry.id === id; });
        if (!item) {
            return;
        }

        // 选择逻辑：按住已选中的图标拖动 → 保持多选整体移动；否则选中该图标
        var multiDrag = state.desktopSelection.has(id) && state.desktopSelection.size > 1;
        if (!multiDrag) {
            state.desktopSelection.clear();
            state.desktopSelection.add(id);
            syncDesktopSelection();
        }
        var dragItems = state.workspace.desktop_items.filter(function (entry) {
            return state.desktopSelection.has(entry.id);
        });
        var dragButtons = dragItems.map(function (entry) {
            return desktopButtonById(entry.id);
        }).filter(Boolean);

        var startX = event.clientX;
        var startY = event.clientY;
        var initialPositions = dragButtons.map(function (entryButton) {
            return { left: entryButton.offsetLeft, top: entryButton.offsetTop };
        });
        var moved = false;
        button.setPointerCapture(event.pointerId);
        dragButtons.forEach(function (entryButton) { entryButton.classList.add('is-dragging'); });

        function move(moveEvent) {
            if (Math.abs(moveEvent.clientX - startX) + Math.abs(moveEvent.clientY - startY) > 5) {
                moved = true;
            }
            if (!moved) {
                return;
            }
            var deltaX = moveEvent.clientX - startX;
            var deltaY = moveEvent.clientY - startY;
            var size = desktopIconSize();
            dragButtons.forEach(function (entryButton, index) {
                var base = initialPositions[index];
                entryButton.style.left = Math.max(4, Math.min(elements.desktopIcons.clientWidth - size.iconW, base.left + deltaX)) + 'px';
                entryButton.style.top = Math.max(4, Math.min(elements.desktopIcons.clientHeight - size.iconH, base.top + deltaY)) + 'px';
            });
        }
        function cleanup() {
            dragButtons.forEach(function (entryButton) { entryButton.classList.remove('is-dragging'); });
            // 监听统一绑在 document：无论指针捕获是否被原生拖选中断，收尾必执行，杜绝图标黏住鼠标
            document.removeEventListener('pointermove', move);
            document.removeEventListener('pointerup', end);
            document.removeEventListener('pointercancel', cleanup);
            try {
                button.releasePointerCapture(event.pointerId);
            } catch (error) {
                // 指针捕获已随 pointercancel 释放，忽略
            }
        }
        function end() {
            cleanup();
            if (moved) {
                // 网格落点 + 冲突探测：占用集合为未参与拖动的可见图标（未分配入口不占位，可移入其原格子）
                var selectedIds = new Set(dragItems.map(function (entry) { return entry.id; }));
                var occupied = new Set(assignedDesktopItems().filter(function (entry) {
                    return !selectedIds.has(entry.id);
                }).map(function (entry) {
                    return (Number(entry.x) || 0) + ',' + (Number(entry.y) || 0);
                }));
                var dropSize = desktopIconSize();
                var placed = dragButtons.map(function (entryButton, index) {
                    return {
                        item: dragItems[index],
                        gridX: Math.max(0, Math.round((entryButton.offsetLeft - 4) / dropSize.cellW)),
                        gridY: Math.max(0, Math.round((entryButton.offsetTop - 4) / dropSize.cellH))
                    };
                }).sort(function (a, b) {
                    return a.gridY - b.gridY || a.gridX - b.gridX;
                });
                placed.forEach(function (place) {
                    var gridX = place.gridX;
                    var gridY = place.gridY;
                    var guard = 0;
                    while (occupied.has(gridX + ',' + gridY) && guard < 200) {
                        gridY += 1;
                        guard += 1;
                    }
                    occupied.add(gridX + ',' + gridY);
                    place.item.x = gridX;
                    place.item.y = gridY;
                });
                renderDesktop();
                saveWorkspace(false);
                // renderDesktop 重建了按钮节点，拖动标记要写到新节点上（双击打开据此跳过误触）
                var refreshedButton = desktopButtonById(id);
                if (refreshedButton) {
                    refreshedButton.dataset.wasDragged = '1';
                }
                return;
            }
            if (event.ctrlKey || event.metaKey) {
                // Ctrl+单击：切换选中
                if (state.desktopSelection.has(id)) {
                    state.desktopSelection.delete(id);
                } else {
                    state.desktopSelection.add(id);
                }
                syncDesktopSelection();
            }
        }
        // 收尾监听统一绑在 document：即便指针捕获被原生拖选中断，pointerup/pointercancel 仍必达，
        // end/cleanup 必执行，杜绝图标黏住鼠标（图标的 img 已通过 CSS pointer-events:none 禁掉原生拖拽源）
        document.addEventListener('pointermove', move);
        document.addEventListener('pointerup', end);
        document.addEventListener('pointercancel', cleanup);
    }

    /**
     * 桌面空白处框选：按住左键拖出选框，框内图标进入选中态（Windows 桌面风格）。
     * Ctrl 按住时在现有选择基础上追加；松开时位移过小视为点击空白，清空选择。
     */
    function bindDesktopMarquee() {
        elements.desktopIcons.addEventListener('pointerdown', function (event) {
            if (event.button !== 0 || event.target.closest('[data-desktop-id]')) {
                return;
            }
            var containerRect = elements.desktopIcons.getBoundingClientRect();
            var originX = event.clientX - containerRect.left;
            var originY = event.clientY - containerRect.top;
            var moved = false;
            var marquee = document.createElement('div');
            marquee.className = 'desktop-marquee';
            elements.desktopIcons.appendChild(marquee);
            // 指针捕获到桌面容器：指针短暂移出容器边缘时框选与收尾逻辑仍能继续
            elements.desktopIcons.setPointerCapture(event.pointerId);

            function hitTest() {
                var box = marquee.getBoundingClientRect();
                elements.desktopIcons.querySelectorAll('[data-desktop-id]').forEach(function (button) {
                    var iconBox = button.getBoundingClientRect();
                    var hit = !(iconBox.right < box.left || iconBox.left > box.right || iconBox.bottom < box.top || iconBox.top > box.bottom);
                    var iconId = button.dataset.desktopId;
                    if (hit) {
                        state.desktopSelection.add(iconId);
                    } else if (!event.ctrlKey && !event.metaKey) {
                        state.desktopSelection.delete(iconId);
                    }
                });
                syncDesktopSelection();
            }
            function moveTarget(moveEvent) {
                if (!moved && Math.abs(moveEvent.clientX - event.clientX) + Math.abs(moveEvent.clientY - event.clientY) > 5) {
                    moved = true;
                }
                if (!moved) {
                    return;
                }
                var currentX = moveEvent.clientX - containerRect.left;
                var currentY = moveEvent.clientY - containerRect.top;
                marquee.style.left = Math.min(originX, currentX) + 'px';
                marquee.style.top = Math.min(originY, currentY) + 'px';
                marquee.style.width = Math.abs(currentX - originX) + 'px';
                marquee.style.height = Math.abs(currentY - originY) + 'px';
                hitTest();
            }
            function endTarget() {
                marquee.remove();
                // 收尾监听绑在 document：框选中断（pointercancel）或指针移出容器都能正常收尾
                document.removeEventListener('pointermove', moveTarget);
                document.removeEventListener('pointerup', endTarget);
                document.removeEventListener('pointercancel', endTarget);
                try {
                    elements.desktopIcons.releasePointerCapture(event.pointerId);
                } catch (error) {
                    // 指针捕获已随 pointercancel 释放，忽略
                }
                if (!moved) {
                    state.desktopSelection.clear();
                    syncDesktopSelection();
                }
            }
            document.addEventListener('pointermove', moveTarget);
            document.addEventListener('pointerup', endTarget);
            document.addEventListener('pointercancel', endTarget);
        });
    }

    function bindEvents() {
        elements.startButton.addEventListener('click', function () {
            // 「点击菜单进入」偏好（OS 设置-系统设置）：开启后点击菜单按钮直接进入「全部功能」启动台，
            // 默认（关闭）打开当前系统菜单面板
            if (state.workspace.preferences.menu_open_launcher === true) {
                closePanels();
                openLauncherDialog();
                return;
            }
            togglePanel('start', elements.startPanel, elements.startButton);
        });
        elements.notificationButton.addEventListener('click', function () { togglePanel('notifications', elements.notificationPanel, elements.notificationButton); });
        // 前台首页：浏览器新标签页打开站点首页
        elements.websiteButton.addEventListener('click', function () {
            window.open(elements.websiteButton.dataset.websiteUrl, '_blank', 'noopener');
        });
        elements.accountButton.addEventListener('click', function () { togglePanel('account', elements.accountPanel, elements.accountButton); });
        elements.clockButton.addEventListener('click', function () { togglePanel('calendar', elements.calendarPanel, elements.clockButton); });
        elements.startSearch.addEventListener('input', renderStartMenu);
        elements.windowLayer.addEventListener('input', function (event) {
            var slider = event.target.closest('[data-set-window-size]');
            if (slider) {
                updateWindowSizeLabel(slider);
            }
        });
        elements.windowLayer.addEventListener('change', function (event) {
            var slider = event.target.closest('[data-set-window-size]');
            if (slider) {
                setWindowSizePreference(slider.dataset.setWindowSize, Number(slider.value));
            }
        });

        document.getElementById('notification-read-all').addEventListener('click', function () {
            api('/api/admin/notifications/read-all', { method: 'POST' }).then(function () {
                toast('通知已全部标记为已读');
                loadNotifications();
            }).catch(function (error) { toast(error.message, 'error'); });
        });

        // 通知中心 Tab 切换（待办 / 通知）
        elements.notificationPanel.addEventListener('click', function (event) {
            var tab = event.target.closest('[data-notification-tab]');
            if (tab) {
                setNotificationTab(tab.dataset.notificationTab);
                return;
            }
            // 待办条目：优先在 WebOS 内打开对应应用窗口并标记待办已读（徽标减少）
            var todo = event.target.closest('[data-todo-key]');
            if (todo && todo.dataset.todoKey) {
                event.preventDefault();
                markTodoRead(todo.dataset.todoKey, todo.dataset.todoCount);
                openLinkInWebos(todo.getAttribute('href'));
                return;
            }
            // 通知条目：标记已读（刷新任务栏徽标与面板）并优先在 WebOS 内打开对应应用窗口
            var item = event.target.closest('[data-notification-link]');
            if (item) {
                var panelNoticeId = item.dataset.notificationId;
                if (panelNoticeId) {
                    api('/api/admin/notifications/' + encodeURIComponent(panelNoticeId) + '/read', { method: 'POST' })
                        .then(loadNotifications)
                        .catch(function () { /* 标记失败不阻断跳转 */ });
                }
                openLinkInWebos(item.dataset.notificationLink);
                return;
            }
            // 底部「查看全部」：在 WebOS 窗口中打开通知中心页面
            var viewAll = event.target.closest('[data-open-notification-page]');
            if (viewAll) {
                openEntry(notificationCenterEntry());
            }
        });

        document.getElementById('show-desktop-button').addEventListener('click', function () {
            state.windows.forEach(function (_, key) { minimizeWindow(key); });
            closePanels();
        });

        elements.desktopIcons.addEventListener('pointerdown', function (event) {
            var button = event.target.closest('[data-desktop-id]');
            if (button && event.button === 0) {
                dragDesktopIcon(button, event);
            }
        });
        // 桌面空白处框选多个图标（Windows 桌面风格）
        bindDesktopMarquee();
        elements.desktopIcons.addEventListener('dblclick', function (event) {
            var button = event.target.closest('[data-desktop-id]');
            if (button && button.dataset.wasDragged !== '1') {
                openEntry(findEntry(button.dataset.desktopId));
            }
            if (button) {
                button.dataset.wasDragged = '0';
            }
        });
        elements.desktopIcons.addEventListener('contextmenu', function (event) {
            var button = event.target.closest('[data-desktop-id]');
            if (!button) {
                return;
            }
            event.preventDefault();
            openDesktopContextMenu(button.dataset.desktopId, event.clientX, event.clientY);
        });

        // 桌面空白区域右键：仅在桌面本身生效，窗口、任务栏、面板、弹窗内右键不触发
        root.addEventListener('contextmenu', function (event) {
            if (!isDesktopSurface(event.target)) {
                return;
            }
            event.preventDefault();
            openDesktopBlankContextMenu(event.clientX, event.clientY);
        });

        // 自定义壁纸文件选择
        root.addEventListener('change', function (event) {
            var input = event.target.closest('[data-wallpaper-input]');
            if (!input || !input.files || !input.files[0]) {
                return;
            }
            var file = input.files[0];
            input.value = '';
            uploadWallpaper(file);
        });

        // 任务栏固定图标：左键按住可拖动排序（独立于桌面顺序）
        elements.taskbarPinned.addEventListener('pointerdown', function (event) {
            var button = event.target.closest('[data-pinned-launch-id]');
            if (button && event.button === 0) {
                dragTaskbarIcon(button, event);
            }
        });
        elements.taskbarPinned.addEventListener('contextmenu', function (event) {
            var button = event.target.closest('[data-pinned-launch-id]');
            if (!button) {
                return;
            }
            event.preventDefault();
            openTaskbarContextMenu(taskbarPinnedMenuItems(button.dataset.pinnedLaunchId), event.clientX, event.clientY);
        });

        elements.taskbarWindows.addEventListener('contextmenu', function (event) {
            var button = event.target.closest('[data-task-window]');
            if (!button) {
                return;
            }
            event.preventDefault();
            var items = taskbarWindowMenuItems(button.dataset.taskWindow);
            if (items.length) {
                openTaskbarContextMenu(items, event.clientX, event.clientY);
            }
        });

        // 窗口选项卡右键：关闭当前 / 关闭其它 / 关闭全部（右键同时聚焦所在窗口）
        root.addEventListener('contextmenu', function (event) {
            var tab = event.target.closest('[data-window-tab]');
            if (!tab) {
                return;
            }
            event.preventDefault();
            var host = tab.closest('[data-window-key]');
            if (host && state.windows.get(host.dataset.windowKey)) {
                focusWindow(host.dataset.windowKey);
            }
            openWindowTabContextMenu(tab.dataset.windowTab, event.clientX, event.clientY);
        });

        // 系统菜单拖拽应用换顶级分类：拖应用卡片到左侧分类按钮上松手，复用系统菜单移动接口
        // （PUT /api/admin/menus/move，MenuService::batchMove 服务端已防环形引用并按顶层节点
        // 移动防止子树拍平）。仅超管卡片才带 data-move-ids；「常用」为虚拟分组不可作为目标
        var draggingMoveIds = null;
        var dragOverCategory = null;
        document.addEventListener('dragstart', function (event) {
            var card = event.target.closest ? event.target.closest('[data-move-ids]') : null;
            if (!card) { return; }
            draggingMoveIds = card.dataset.moveIds.split(',').map(function (id) { return Number(id); }).filter(function (id) { return id > 0; });
            if (!draggingMoveIds.length) { draggingMoveIds = null; event.preventDefault(); return; }
            event.dataTransfer.effectAllowed = 'move';
            try { event.dataTransfer.setData('text/plain', card.dataset.moveIds); } catch (error) { /* 兼容旧浏览器忽略 */ }
            card.classList.add('is-dragging');
        });
        document.addEventListener('dragover', function (event) {
            if (!draggingMoveIds) { return; }
            var category = event.target.closest('.start-category-button');
            if (!category || category.dataset.groupId === 'common') { return; }
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            if (dragOverCategory !== category) {
                if (dragOverCategory) { dragOverCategory.classList.remove('is-drop-target'); }
                category.classList.add('is-drop-target');
                dragOverCategory = category;
            }
        });
        document.addEventListener('drop', function (event) {
            if (!draggingMoveIds) { return; }
            event.preventDefault();
            var category = dragOverCategory;
            if (dragOverCategory) { dragOverCategory.classList.remove('is-drop-target'); dragOverCategory = null; }
            var moveIds = draggingMoveIds;
            draggingMoveIds = null;
            document.querySelectorAll('.start-app-item.is-dragging').forEach(function (card) { card.classList.remove('is-dragging'); });
            if (!category || category.dataset.groupId === 'common') { return; }
            api('/api/admin/menus/move', { method: 'PUT', body: { ids: moveIds, parent_id: Number(category.dataset.groupId) } })
                .then(function () {
                    toast('已移动到「' + (category.textContent || '').trim() + '」分类', 'success');
                    return refreshCatalog();
                })
                .catch(function (error) { toast(error.message, 'error'); });
        });
        document.addEventListener('dragend', function () {
            if (dragOverCategory) { dragOverCategory.classList.remove('is-drop-target'); dragOverCategory = null; }
            draggingMoveIds = null;
            document.querySelectorAll('.start-app-item.is-dragging').forEach(function (card) { card.classList.remove('is-dragging'); });
        });

        document.addEventListener('click', function (event) {
            // 点击前台菜单下拉框外部时收起所有已展开的前台菜单
            if (!event.target.closest('[data-window-home-menu]')) {
                document.querySelectorAll('.window-home-menu-list:not([hidden])').forEach(function (openList) {
                    openList.hidden = true;
                    var trigger = openList.parentElement.querySelector('.home-menu-trigger');
                    if (trigger) { trigger.setAttribute('aria-expanded', 'false'); }
                });
            }

            var action = event.target.closest('[data-action]');
            if (action) {
                var name = action.dataset.action;
                if (name === 'close-start') { closePanels(); }
                if (name === 'close-calendar') { closePanels(); }
                if (name === 'open-password-dialog') { closePanels(); openPasswordDialog(); }
                // 清除缓存：复用系统后台缓存清理接口（GET /api/admin/cache/clear，ConfigController::clearCache）
                if (name === 'clear-cache') {
                    closePanels();
                    api('/api/admin/cache/clear').then(function () {
                        toast('缓存已清除', 'success');
                    }).catch(function (error) { toast(error.message, 'error'); });
                }
                if (name === 'close-install') { hideModalDialog(elements.installDialog); }
                if (name === 'close-action') { closeActionDialog(); }
                if (name === 'lock-desktop') { closePanels(); elements.lockScreen.hidden = false; updateClock(); }
                if (name === 'unlock-desktop') { elements.lockScreen.hidden = true; }
                if (name === 'logout') {
                    api('/api/admin/auth/logout', { method: 'POST' }).then(function () {
                        window.location.href = root.dataset.loginUrl || '/admin/login';
                    }).catch(function (error) { toast(error.message, 'error'); });
                }
            }

            if (!event.target.closest('.webos-panel') && !isPanelToggle(event.target)) {
                closePanels();
            }

            var groupButton = event.target.closest('[data-group-id]');
            if (groupButton) { state.activeGroup = groupButton.dataset.groupId; renderStartMenu(); }

            var calendarNav = event.target.closest('[data-calendar-nav]');
            if (calendarNav) { shiftCalendarMonth(calendarNav.dataset.calendarNav === 'prev' ? -1 : 1); }

            if (event.target.closest('[data-calendar-today]')) { openCalendar(); }

            var calendarDay = event.target.closest('[data-calendar-date]');
            if (calendarDay) { selectCalendarDate(calendarDay.dataset.calendarDate); }

            var pinButton = event.target.closest('[data-pin-id]');
            if (pinButton) {
                event.stopPropagation();
                toggleDesktopEntry(pinButton.dataset.pinId, findStartItem(pinButton.dataset.pinId));
            }

            var menuButton = event.target.closest('[data-menu-id]');
            if (menuButton && !event.target.closest('[data-pin-id]')) { openEntry(findEntry(menuButton.dataset.menuId)); }

            var pinnedLaunch = event.target.closest('[data-pinned-launch-id]');
            if (pinnedLaunch) {
                if (taskbarDragJustEnded) {
                    // 拖动排序刚结束：吞掉本次 click，避免误启动入口
                    taskbarDragJustEnded = false;
                    return;
                }
                closeActionDialog();
                var pinnedEntry = findEntry(pinnedLaunch.dataset.pinnedLaunchId);
                var runningKey = runningWindowKeyByApp(pinnedEntry || { id: pinnedLaunch.dataset.pinnedLaunchId });
                if (runningKey) {
                    // 固定应用已在运行：点击在聚焦与最小化之间切换（Windows 风格）
                    var pinnedTarget = state.windows.get(runningKey);
                    if (pinnedTarget && !pinnedTarget.minimized && pinnedTarget.element.classList.contains('is-focused')) {
                        minimizeWindow(runningKey);
                    } else {
                        restoreWindow(runningKey);
                    }
                } else {
                    openEntry(pinnedEntry);
                }
                return;
            }

            var launch = event.target.closest('[data-launch-id]');
            if (launch) { closeActionDialog(); closeLauncherDialog(); openEntry(findEntry(launch.dataset.launchId)); }

            var taskWindow = event.target.closest('[data-task-window]');
            if (taskWindow) {
                var target = state.windows.get(taskWindow.dataset.taskWindow);
                if (target && !target.minimized && target.element.classList.contains('is-focused')) { minimizeWindow(taskWindow.dataset.taskWindow); }
                else { restoreWindow(taskWindow.dataset.taskWindow); }
            }

            var windowAction = event.target.closest('[data-window-action]');
            if (windowAction) {
                var windowElement = windowAction.closest('[data-window-key]');
                var key = windowElement.dataset.windowKey;
                if (windowAction.dataset.windowAction === 'toggle-sidebar') {
                    toggleWindowSidebar(windowElement, windowAction);
                }
                if (windowAction.dataset.windowAction === 'toggle-home-menu') {
                    // 展开当前前台菜单前先收起其它窗口已展开的下拉，保持全局互斥
                    document.querySelectorAll('.window-home-menu-list:not([hidden])').forEach(function (openList) {
                        if (openList.parentElement !== windowAction.parentElement) {
                            openList.hidden = true;
                            var otherTrigger = openList.parentElement.querySelector('.home-menu-trigger');
                            if (otherTrigger) { otherTrigger.setAttribute('aria-expanded', 'false'); }
                        }
                    });
                    var homeMenuList = windowAction.parentElement.querySelector('.window-home-menu-list');
                    if (homeMenuList) {
                        var willOpen = homeMenuList.hidden;
                        homeMenuList.hidden = !willOpen;
                        windowAction.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                    }
                }
                if (windowAction.dataset.windowAction === 'refresh') {
                    // 标题栏刷新按钮：多选项卡窗口刷新当前激活选项卡（与选项卡右键「刷新页面」共用 reloadWindowTab）；
                    // 无选项卡的特殊窗口（应用中心/OS 设置/通知中心/官网动态）刷新整页——
                    // 页面令牌相同会被 renderWindowPage 跳过，先清空令牌再重建，并重新执行各窗口的数据渲染
                    var refreshTarget = state.windows.get(key);
                    if (refreshTarget && refreshTarget.tabs && refreshTarget.tabs.length) {
                        var refreshTab = refreshTarget.tabs.find(function (item) { return item.id === refreshTarget.activeTabId; })
                            || refreshTarget.tabs[0];
                        reloadWindowTab(refreshTarget, refreshTab);
                    } else if (refreshTarget && refreshTarget.entry && refreshTarget.entry.path) {
                        var refreshHost = refreshTarget.element.querySelector('[data-window-page-host]');
                        if (refreshHost) {
                            refreshHost.dataset.pageToken = '';
                            renderWindowPage(refreshTarget, refreshTarget.entry);
                        }
                        if (refreshTarget.entry.id === 'webos-app-center') {
                            renderAppCenter(state.appCenterTab);
                            loadUpdateCount();
                        }
                        if (refreshTarget.entry.id === 'webos-settings') {
                            renderWebosSettings();
                        }
                        if (refreshTarget.entry.id === 'webos-notification-page') {
                            renderNotificationCenter(notificationCenterState.tab);
                            loadNotifications();
                        }
                        if (refreshTarget.entry.id === 'webos-official-news') {
                            renderOfficialNews();
                        }
                    }
                }
                if (windowAction.dataset.windowAction === 'minimize') { minimizeWindow(key); }
                if (windowAction.dataset.windowAction === 'maximize') { maximizeWindow(key); }
                if (windowAction.dataset.windowAction === 'close') { closeWindow(key); }
            }

            // 前台菜单项：新标签页打开前台地址并收起下拉
            var homeMenuItem = event.target.closest('[data-home-url]');
            if (homeMenuItem) {
                window.open(homeMenuItem.dataset.homeUrl, '_blank', 'noopener');
                var homeList = homeMenuItem.closest('.window-home-menu-list');
                if (homeList) {
                    homeList.hidden = true;
                    var homeTrigger = homeList.parentElement.querySelector('.home-menu-trigger');
                    if (homeTrigger) { homeTrigger.setAttribute('aria-expanded', 'false'); }
                }
            }

            var windowNavBranch = event.target.closest('[data-window-nav-branch]');
            if (windowNavBranch) { toggleWindowNavBranch(windowNavBranch); }

            // 窗口标题栏选项卡：先判关闭叉（嵌套在选项卡按钮内），再判选项卡主体切换
            // 注意：局部变量不得命名为 closeWindow/switchWindowTab 等，var 提升会遮蔽同名全局函数
            var tabClose = event.target.closest('[data-window-tab-close]');
            var tabButton = event.target.closest('[data-window-tab]');
            if (tabClose && tabButton) {
                var tabHostWindow = tabButton.closest('[data-window-key]');
                if (tabHostWindow) {
                    closeWindowTab(state.windows.get(tabHostWindow.dataset.windowKey), tabClose.dataset.windowTabClose);
                }
            } else if (tabButton) {
                var tabHostWindow = tabButton.closest('[data-window-key]');
                if (tabHostWindow) {
                    switchWindowTab(state.windows.get(tabHostWindow.dataset.windowKey), tabButton.dataset.windowTab);
                }
            }

            // 选项卡条溢出导航：向左/向右滚动约一屏（点击后按钮显隐随 scroll 事件自动更新）
            var tabsScroll = event.target.closest('[data-window-tabs-scroll]');
            if (tabsScroll) {
                var tabsHost = tabsScroll.closest('[data-window-key]');
                var tabsBar = tabsHost && tabsHost.querySelector('.window-tabs');
                if (tabsBar) {
                    var scrollDirection = tabsScroll.dataset.windowTabsScroll === 'prev' ? -1 : 1;
                    tabsBar.scrollBy({ left: scrollDirection * Math.round(tabsBar.clientWidth * 0.6), behavior: 'smooth' });
                }
            }

            var windowMenu = event.target.closest('[data-window-menu-id]');
            if (windowMenu) {
                var currentWindow = windowMenu.closest('[data-window-key]');
                var entry = findEntry(windowMenu.dataset.windowMenuId);
                // _blank / _layer 不占用窗口内容区：openMenuByType 打开后即结束，不切换页面也不更新侧栏高亮
                if (entry && !openMenuByType(entry) && currentWindow) {
                    var currentState = state.windows.get(currentWindow.dataset.windowKey);
                    activateWindowEntry(currentState, entry);
                    focusWindow(currentWindow.dataset.windowKey);
                }
            }

            var specialTab = event.target.closest('[data-special-tab]');
            if (specialTab) {
                // 按钮所在窗口为通知中心时切换「待办/通知」，否则视为应用中心功能 Tab
                var tabWindow = specialTab.closest('[data-window-key]');
                var tabState = tabWindow ? state.windows.get(tabWindow.dataset.windowKey) : null;
                if (tabState && tabState.entry && tabState.entry.special === 'notifications') {
                    renderNotificationCenter(specialTab.dataset.specialTab);
                } else {
                    renderAppCenter(specialTab.dataset.specialTab);
                }
            }

            // 通知中心窗口：全部/已读/未读筛选（按当前 Tab 写入对应筛选状态并重载列表）
            var noticeFilterBtn = event.target.closest('[data-notification-filter]');
            if (noticeFilterBtn) {
                var filterValue = noticeFilterBtn.dataset.notificationFilter;
                var filterWindow = noticeFilterBtn.closest('[data-window-key]');
                var filterState = filterWindow ? state.windows.get(filterWindow.dataset.windowKey) : null;
                if (filterState && filterState.entry && filterState.entry.special === 'notifications') {
                    if (notificationCenterState.tab === 'todos') {
                        notificationCenterState.todoFilter = filterValue;
                    } else {
                        notificationCenterState.noticeFilter = filterValue;
                        notificationCenterState.page = 1;
                    }
                    renderNotificationCenter(notificationCenterState.tab);
                }
                return;
            }

            // 通知中心窗口：全部已读按钮
            var noticeReadAll = event.target.closest('[data-notification-read-all]');
            if (noticeReadAll) {
                markAllNotificationCenterRead(noticeReadAll);
                return;
            }

            // 通知中心窗口：待办卡片点击，标记已读并优先在 WebOS 内打开对应应用窗口
            var centerTodo = event.target.closest('.webos-notifications-shell [data-todo-key]');
            if (centerTodo && centerTodo.dataset.todoKey) {
                event.preventDefault();
                markTodoRead(centerTodo.dataset.todoKey, centerTodo.dataset.todoCount);
                openLinkInWebos(centerTodo.getAttribute('href'));
                return;
            }

            // 通知中心窗口：通知行点击，标记已读（同步刷新任务栏徽标）并优先在 WebOS 内打开对应应用窗口
            var centerNotice = event.target.closest('[data-notification-notice]');
            if (centerNotice) {
                var noticeId = centerNotice.dataset.notificationNotice;
                if (noticeId) {
                    api('/api/admin/notifications/' + encodeURIComponent(noticeId) + '/read', { method: 'POST' })
                        .then(function () {
                            centerNotice.classList.add('is-read');
                            var dot = centerNotice.querySelector('.notification-dot');
                            if (dot) { dot.remove(); }
                            loadNotifications();
                        }).catch(function () { /* 标记失败不阻断跳转 */ });
                }
                var noticeLink = centerNotice.dataset.noticeLink;
                if (noticeLink) {
                    openLinkInWebos(noticeLink);
                }
                return;
            }

            // 通知中心窗口：通知分页
            var noticePage = event.target.closest('[data-notification-goto-page]');
            if (noticePage && !noticePage.disabled) {
                var centerShell = noticePage.closest('[data-webos-notifications]');
                if (centerShell) {
                    loadNotificationCenterNotices(centerShell, Number(noticePage.dataset.notificationGotoPage || 1));
                }
            }

            var marketCategory = event.target.closest('[data-market-category]');
            if (marketCategory) {
                var marketContent = marketCategory.closest('[data-app-content]');
                if (marketContent) {
                    switchMarketCategory(marketCategory.dataset.marketCategory, marketContent);
                }
            }

            // 市场内部子Tab切换：首页 / 分类
            var marketSubTab = event.target.closest('[data-market-sub-tab]');
            if (marketSubTab) {
                var subTabContent = marketSubTab.closest('[data-app-content]');
                if (subTabContent) {
                    switchMarketSubTab(marketSubTab.dataset.marketSubTab, subTabContent);
                }
            }

            // 市场首页区块翻页：< > 切换该区块上一页/下一页（仅重渲染区块内容）
            var homePager = event.target.closest('[data-home-pager]');
            if (homePager && !homePager.disabled) {
                switchMarketHomePage(homePager, homePager.dataset.homePager === 'next' ? 1 : -1);
            }

            // 市场卡片整卡可点击进入详情，卡内的安装/更新按钮与链接保持自身行为
            var marketDetail = event.target.closest('[data-market-detail]');
            if (marketDetail && !event.target.closest('button, a, input, select, label')) {
                openMarketDetail(marketDetail.closest('[data-app-center]'), marketDetail.dataset.marketDetail);
            }

            // 本地列表（已安装/未安装/应用更新）点击应用名称进入本地详情
            var localDetail = event.target.closest('[data-local-detail]');
            if (localDetail && !event.target.closest('button, a, input, select, label')) {
                openLocalDetail(localDetail.closest('[data-app-center]'), localDetail.dataset.localDetail,
                    localDetail.dataset.localSource);
            }

            var marketDetailClose = event.target.closest('[data-market-detail-close]');
            if (marketDetailClose) {
                closeMarketDetail(marketDetailClose.closest('[data-app-center]'));
            }

            // 全屏截图预览层：命中后直接返回，不叠加桌面与应用中心的其他点击行为
            if (event.target.closest('[data-shot-viewer-close]')) {
                closeShotViewer();
                return;
            }

            var viewerStep = event.target.closest('[data-shot-viewer-prev], [data-shot-viewer-next]');
            if (viewerStep) {
                stepShotViewer(viewerStep.hasAttribute('data-shot-viewer-prev') ? -1 : 1);
                return;
            }

            // 截图轨道左右箭头：单行展示时超出部分横向滚动切换
            var shotsNav = event.target.closest('[data-shots-prev], [data-shots-next]');
            if (shotsNav) {
                scrollMarketShots(shotsNav.parentNode.querySelector('[data-shots-track]'),
                    shotsNav.hasAttribute('data-shots-prev') ? -1 : 1);
                return;
            }

            var shotThumb = event.target.closest('[data-shot-url]');
            if (shotThumb) {
                openShotViewerFrom(shotThumb);
                return;
            }

            var specialOpen = event.target.closest('[data-open-special]');
            if (specialOpen) {
                // 「全部功能」：开始菜单按钮直接打开启动台（macOS 风格全功能浮层）；
                // 其余特殊入口仍走应用中心对应标签页
                if (specialOpen.dataset.openSpecial === 'entries') { openLauncherDialog(); return; }
                state.appCenterTab = specialOpen.dataset.openSpecial;
                openEntry(applicationCenterEntry());
                renderAppCenter(state.appCenterTab);
            }

            var settingsOpen = event.target.closest('[data-open-webos-settings]');
            if (settingsOpen) { openEntry(webosSettingsEntry()); }

            var settingsTab = event.target.closest('[data-settings-tab]');
            if (settingsTab) { osSettingsTab = settingsTab.dataset.settingsTab; renderWebosSettings(); }

            var install = event.target.closest('[data-install-id]');
            if (install) {
                var app = state.marketApps.get(install.dataset.installId);
                if (app) { openInstallDialog(app, install.dataset.installSource); }
            }

            var upgrade = event.target.closest('[data-upgrade-id]');
            if (upgrade) { upgradeApp(upgrade.dataset.upgradeId); }

            var openApp = event.target.closest('[data-open-app-id]');
            if (openApp) {
                closeLauncherDialog();
                var appMenus = state.flatMenus.filter(function (entry) {
                    return entry.app_id === openApp.dataset.openAppId;
                });
                openEntry(defaultEntryOf(appMenus));
            }

            var pinApp = event.target.closest('[data-pin-app-id]');
            if (pinApp) {
                var appMenu = state.flatMenus.find(function (entry) { return entry.app_id === pinApp.dataset.pinAppId; });
                addDesktopEntry(appMenu);
            }

            var addEntry = event.target.closest('[data-add-entry-id]');
            if (addEntry) { addDesktopEntry(findEntry(addEntry.dataset.addEntryId)); renderAppCenter('entries'); }

            var entryBranch = event.target.closest('[data-entry-branch]');
            if (entryBranch) {
                var branchKey = entryBranch.dataset.entryBranch;
                if (entryTreeExpanded.has(branchKey)) {
                    entryTreeExpanded.delete(branchKey);
                } else {
                    entryTreeExpanded.add(branchKey);
                }
                // 重渲染会重建列表导致滚动跳回顶部，先记住位置渲染后恢复
                var centerEl = entryBranch.closest('[data-app-center]');
                var contentEl = centerEl ? centerEl.querySelector('[data-app-content]') : null;
                var entryScroll = contentEl ? contentEl.scrollTop : 0;
                renderAppCenter('entries');
                contentEl = centerEl ? centerEl.querySelector('[data-app-content]') : null;
                if (contentEl) {
                    contentEl.scrollTop = entryScroll;
                }
            }

            var removeEntry = event.target.closest('[data-remove-entry-id]');
            if (removeEntry) { removeDesktopEntry(removeEntry.dataset.removeEntryId); renderAppCenter('entries'); }

            var taskbarPosition = event.target.closest('[data-set-taskbar-position]');
            if (taskbarPosition) { setTaskbarPosition(taskbarPosition.dataset.setTaskbarPosition); }

            var wallpaper = event.target.closest('[data-set-webos-wallpaper]');
            if (wallpaper) { setWebosPreference('wallpaper', wallpaper.dataset.setWebosWallpaper, '桌面外观已更新'); }

            var wallpaperUpload = event.target.closest('[data-upload-wallpaper]');
            if (wallpaperUpload) {
                var wallpaperInput = document.querySelector('[data-wallpaper-input]');
                if (wallpaperInput) { wallpaperInput.click(); }
            }

            var wallpaperReset = event.target.closest('[data-reset-wallpaper]');
            if (wallpaperReset) { setWebosPreference('wallpaper_url', '', '已恢复默认壁纸'); }

            var wallpaperReload = event.target.closest('[data-wallpaper-reload]');
            if (wallpaperReload) { loadWallpapers(); }

            var wallpaperUse = event.target.closest('[data-wallpaper-use]');
            if (wallpaperUse) { setWebosPreference('wallpaper_url', wallpaperUse.dataset.wallpaperUse, '背景壁纸已更新'); }

            var wallpaperDeleteConfirm = event.target.closest('[data-wallpaper-delete-confirm]');
            if (wallpaperDeleteConfirm) { deleteWallpaper(wallpaperDeleteConfirm.dataset.wallpaperDeleteConfirm); }

            var wallpaperDeleteCancel = event.target.closest('[data-wallpaper-delete-cancel]');
            if (wallpaperDeleteCancel) {
                var cancelItem = wallpaperDeleteCancel.closest('[data-wallpaper-item]');
                if (cancelItem) { cancelItem.classList.remove('is-confirming'); }
            }

            var wallpaperDelete = event.target.closest('[data-wallpaper-delete]');
            if (wallpaperDelete) {
                var deleteItem = wallpaperDelete.closest('[data-wallpaper-item]');
                if (deleteItem) {
                    var library = deleteItem.parentNode;
                    Array.prototype.forEach.call(library.querySelectorAll('.wallpaper-library-item.is-confirming'), function (item) {
                        item.classList.remove('is-confirming');
                    });
                    deleteItem.classList.add('is-confirming');
                }
            }

            var clockFormat = event.target.closest('[data-set-clock-format]');
            if (clockFormat) { setWebosPreference('clock_format', clockFormat.dataset.setClockFormat, '时间格式已更新'); }

            var settingToggle = event.target.closest('[data-toggle-webos-setting]');
            if (settingToggle) {
                var settingName = settingToggle.dataset.toggleWebosSetting;
                setWebosPreference(settingName, !state.workspace.preferences[settingName], 'WebOS 设置已更新');
            }

            var resetToggle = event.target.closest('[data-reset-workspace]');
            if (resetToggle) {
                var confirmRow = resetToggle.parentElement.querySelector('.workspace-reset-confirm');
                if (confirmRow) {
                    confirmRow.hidden = false;
                    resetToggle.hidden = true;
                }
                return;
            }
            var resetConfirm = event.target.closest('[data-reset-workspace-confirm]');
            if (resetConfirm) {
                resetWorkspace();
                return;
            }
            var resetCancel = event.target.closest('[data-reset-workspace-cancel]');
            if (resetCancel) {
                var resetRow = resetCancel.closest('.settings-reset-row');
                if (resetRow) {
                    resetRow.querySelector('.workspace-reset-confirm').hidden = true;
                    resetRow.querySelector('[data-reset-workspace]').hidden = false;
                }
                return;
            }

            var accountPath = event.target.closest('[data-account-path]');
            if (accountPath) {
                openEntry({ id: 'account-settings', title: '个人设置', path: accountPath.dataset.accountPath, icon: 'fa fa-cog', group_title: '账号' });
            }

            var appMore = event.target.closest('[data-app-more]');
            if (appMore) { toggleAppRowMenu(appMore); return; }

            var desktopAction = event.target.closest('[data-desktop-action]');
            if (desktopAction) {
                runDesktopContextAction(desktopAction.dataset.desktopAction, desktopAction.dataset.desktopId);
            }

            var taskbarAction = event.target.closest('[data-taskbar-action]');
            if (taskbarAction) {
                runTaskbarContextAction(taskbarAction.dataset.taskbarAction, taskbarAction.dataset);
            }

            closeDesktopContextMenu();
            closeTaskbarContextMenu();
            closeAppRowMenus();
            handleAppCenterAction(event);
        });

        // iframe 是独立文档，其内部点击不会冒泡到父文档的 click 委托（上方监听收不到），
        // 导致右键菜单在点击 iframe 内容后残留；点击 iframe 会使父窗口失焦，借 blur 事件关闭
        window.addEventListener('blur', function () {
            closeDesktopContextMenu();
            closeTaskbarContextMenu();
            closeAppRowMenus();
        });

        elements.confirmInstall.addEventListener('click', confirmInstall);

        elements.windowLayer.addEventListener('input', function (event) {
            if (!event.target.matches('[data-app-search]')) {
                return;
            }
            var query = event.target.value.trim();
            var center = event.target.closest('[data-app-center]');
            // 安装记录：右上角搜索框承担应用标识过滤，重绘记录列表与详情
            if (state.appCenterTab === 'records') {
                state.recordsKeyword = query;
                if (center) {
                    refreshRecordsPanels(center);
                }
                return;
            }
            // 应用市场：输入防抖后带 keyword 全量 Ajax 搜索；其余 Tab：本地过滤已渲染卡片
            if (state.appCenterTab === 'market') {
                window.clearTimeout(marketSearchTimer);
                marketSearchTimer = window.setTimeout(function () {
                    var content = center.querySelector('[data-app-content]');
                    if (content) {
                        searchMarketApps(content, query);
                    }
                }, 220);
                return;
            }
            var lower = query.toLowerCase();
            center.querySelectorAll('[data-app-name], [data-entry-name]').forEach(function (item) {
                var name = item.dataset.appName || item.dataset.entryName || '';
                item.style.display = name.indexOf(lower) >= 0 ? '' : 'none';
            });
        });

        elements.windowLayer.addEventListener('change', function (event) {
            // 安装记录：操作类型筛选变更后按类型重新拉取
            if (event.target.matches('[data-records-operation]')) {
                var recordsCenter = event.target.closest('[data-app-center]');
                if (recordsCenter) {
                    var recordsContent = recordsCenter.querySelector('[data-app-content]');
                    var recordsStatus = recordsCenter.querySelector('[data-app-status]');
                    if (recordsContent && recordsStatus) {
                        loadRecordsTab(recordsContent, recordsStatus, event.target.value);
                    }
                }
                return;
            }
            if (!event.target.matches('[data-app-status-filter]')) {
                return;
            }
            var center = event.target.closest('[data-app-center]');
            var value = event.target.value;
            center.querySelectorAll('[data-app-state]').forEach(function (item) {
                item.style.display = value === '' || item.dataset.appState === value ? '' : 'none';
            });
        });

        elements.actionDialogBody.addEventListener('submit', function (event) {
            if (!event.target.classList.contains('password-form')) {
                return;
            }
            event.preventDefault();
            submitPasswordChange();
        });

        elements.actionDialogBody.addEventListener('input', function (event) {
            var input = event.target.closest('[data-uninstall-input], [data-delete-input]');
            var submit = elements.actionDialogFooter.querySelector('[data-uninstall-submit], [data-delete-submit]');
            if (input && submit) {
                var matched = input.value.trim() === submit.dataset.appName;
                submit.disabled = !matched;
                // 输入匹配点亮确认按钮：加类触发脉冲动画，失配时移除
                submit.classList.toggle('is-armed', matched);
            }
        });

        // 挂在 document 上以捕获阶段监听：截图预览层直接挂在 body 下，不在窗口层子树内
        document.addEventListener('error', function (event) {
            var image = event.target;
            if (!(image instanceof HTMLImageElement)) {
                return;
            }

            if (image.matches('[data-app-icon-primary]')) {
                image.hidden = true;
                var fallback = image.parentNode.querySelector('[data-app-icon-fallback]');
                if (fallback) {
                    if (fallback instanceof HTMLImageElement && fallback.dataset.src) {
                        fallback.src = fallback.dataset.src;
                    }
                    fallback.hidden = false;
                }
                return;
            }

            if (image.matches('[data-app-icon-fallback]')) {
                image.hidden = true;
                var finalIcon = image.parentNode.querySelector('[data-app-icon-final]');
                if (finalIcon) {
                    finalIcon.hidden = false;
                }
                return;
            }

            if (image.matches('[data-market-icon]')) {
                image.hidden = true;
                var marketFallback = image.parentNode.querySelector('[data-market-icon-fallback]');
                if (marketFallback) {
                    marketFallback.hidden = false;
                }
            }

            // 预览层大图加载失败：替换为文字占位，切换截图时由 renderShotViewer 重写恢复
            if (image.matches('[data-shot-viewer-image]')) {
                image.outerHTML = '<p class="shot-viewer-error"><i class="fa fa-exclamation-circle"></i>'
                    + '截图加载失败</p>';
                return;
            }

            // 详情截图加载失败时移除整个缩略图，避免留下空白框
            if (image.matches('[data-market-shot]')) {
                var shot = image.closest('.market-detail-shot');
                if (shot) {
                    var shotTrack = shot.parentNode;
                    shot.remove();
                    if (shotTrack) {
                        updateMarketShotsNav(shotTrack);
                    }
                }
            }
        }, true);

        document.addEventListener('keydown', function (event) {
            // 截图预览层打开时独占键盘：Esc 关闭，左右方向键切换截图
            if (shotViewer) {
                if (event.key === 'Escape') {
                    closeShotViewer();
                }
                if (event.key === 'ArrowLeft') {
                    stepShotViewer(-1);
                }
                if (event.key === 'ArrowRight') {
                    stepShotViewer(1);
                }
                return;
            }

            var activeDialog = activeModalDialog();
            if (event.key === 'Tab' && activeDialog) {
                trapModalFocus(event, activeDialog);
                return;
            }
            if (event.key === 'Escape' && activeDialog) {
                event.preventDefault();
                if (activeDialog === elements.actionDialog) {
                    closeActionDialog();
                } else {
                    hideModalDialog(elements.installDialog);
                }
                return;
            }

            var startItem = event.target.closest('.start-app-item[data-menu-id]');
            if (startItem && event.target === startItem && (event.key === 'Enter' || event.key === ' ')) {
                event.preventDefault();
                openEntry(findEntry(startItem.dataset.menuId));
                return;
            }
            if (event.key === 'Escape') {
                closePanels();
                closeAppRowMenus();
                closeDesktopContextMenu();
                closeActionDialog();
            }
        });
    }

    /** 归一化工作区偏好设置：补齐默认值 */
    function normalizePreferences(preferences) {
        return Object.assign({
            wallpaper: 'webos-default',
            wallpaper_url: '',
            icon_size: 'medium',
            window_tabs: false,
            menu_open_launcher: false,
            taskbar_alignment: 'left',
            taskbar_position: 'bottom',
            clock_format: '24h',
            show_seconds: false,
            motion: true,
            window_width: 78,
            window_height: 80,
            usage_stats: {}
        }, preferences || {});
    }

    function initialize() {
        root.style.setProperty('--webos-wallpaper-image', 'url("' + root.dataset.wallpaperUrl + '")');
        bindEvents();
        updateClock();
        window.setInterval(updateClock, 1000);
        // 窗口/浏览器尺寸变化后重算各窗口选项卡条的溢出导航显隐（动画帧节流）
        var tabsResizeFrame = 0;
        window.addEventListener('resize', function () {
            if (tabsResizeFrame) { window.cancelAnimationFrame(tabsResizeFrame); }
            tabsResizeFrame = window.requestAnimationFrame(function () {
                tabsResizeFrame = 0;
                document.querySelectorAll('.window-controls').forEach(updateWindowTabsNav);
            });
        });

        Promise.all([
            api(root.dataset.workspaceUrl),
            loadCatalog()
        ]).then(function (responses) {
            state.workspace = responses[0] || state.workspace;
            state.workspace.preferences = normalizePreferences(state.workspace.preferences);
            if (Array.isArray(state.workspace.preferences.usage_stats)) {
                state.workspace.preferences.usage_stats = {};
            }
            applyWorkspacePreferences();
            return bootstrapDesktopItems();
        }).then(function () {
            // 首次渲染前归一化：应用启停/授权变化导致坐标冲突的图标自动落到空白格
            normalizeDesktopLayout();
            renderDesktop();
            renderStartMenu();
            loadNotifications();
            // 官网动态窗口：仅超级管理员桌面自动打开（固定 600×500，停靠桌面最右侧）
            if (isSuperAdmin) {
                openEntry(officialNewsEntry());
            }
            // 每次进入 WebOS 页面检查应用市场是否有 WebOS 自身的更高版本
            checkWebosSelfUpdate();
            root.classList.remove('is-loading');
        }).catch(function (error) {
            root.classList.remove('is-loading');
            toast(error.message || 'WebOS 初始化失败', 'error');
        });
    }

    initialize();
}());
