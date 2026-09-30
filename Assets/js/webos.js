(function () {
    'use strict';

    var root = document.getElementById('webos-desktop');
    if (!root) {
        return;
    }

    var runtime = window.CMSPRO_WEBOS || {};
    var state = {
        workspace: {
            desktop_items: [],
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
        updateApps: [],
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
        notificationButton: document.getElementById('notification-button'),
        notificationPanel: document.getElementById('notification-panel'),
        notificationBadge: document.getElementById('notification-badge'),
        notificationSummary: document.getElementById('notification-summary'),
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

    function safeIcon(icon) {
        var className = String(icon || 'fa fa-cube').replace(/[^A-Za-z0-9 _-]/g, '');
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

        function walk(items, group, inheritedAppId, folder) {
            (items || []).forEach(function (item) {
                var isRoot = !group;
                var nextGroup = group || {
                    id: String(item.id || item.code || item.name),
                    title: item.name || '应用',
                    icon: safeIcon(item.icon)
                };
                var nextAppId = item.app_id || inheritedAppId || '';
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
                    walk(children, nextGroup, nextAppId, nextFolder);
                    return;
                }
                if (!openablePath(item)) {
                    return;
                }
                output.push({
                    id: 'menu-' + item.id,
                    menu_id: Number(item.id || 0),
                    app_id: nextAppId,
                    title: item.name || '未命名菜单',
                    path: item.path,
                    icon: safeIcon(item.icon),
                    open_type: item.open_type || '_iframe',
                    group_id: nextGroup.id,
                    group_title: nextGroup.title,
                    group_icon: nextGroup.icon,
                    folder_id: nextAppId ? '' : String(nextFolder.id),
                    folder_title: nextAppId ? '' : nextFolder.title,
                    folder_icon: nextAppId ? '' : nextFolder.icon
                });
            });
        }

        walk(tree || [], null, '', null);
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
        if (hydrateDesktopAppIds()) {
            saveWorkspace(false).catch(function () {});
        }
        if (state.workspace.desktop_items.length) {
            return Promise.resolve();
        }

        // 初始数据只固定「应用中心」，系统菜单由用户按需从入口管理添加
        var selected = [applicationCenterEntry()];
        state.workspace.desktop_items = selected.map(function (item, index) {
            return Object.assign({}, item, { x: 0, y: index });
        });

        return saveWorkspace(false);
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
                preferences: state.workspace.preferences
            }
        }).then(function (workspace) {
            state.workspace = workspace;
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
        elements.desktopIcons.innerHTML = state.workspace.desktop_items.map(function (item) {
            var left = 4 + Math.max(0, Number(item.x) || 0) * 102;
            var top = 4 + Math.max(0, Number(item.y) || 0) * 104;
            return '<button class="desktop-icon" type="button" data-desktop-id="' + escapeHtml(item.id) + '"'
                + ' style="left:' + left + 'px;top:' + top + 'px" title="' + escapeHtml(item.title) + '">'
                + entryIconMarkup(findEntry(item.id) || item, 'desktop-icon-badge')
                + '<span class="desktop-icon-label">' + escapeHtml(item.title) + '</span>'
                + '</button>';
        }).join('');
        renderTaskbarWindows();
    }

    /** 应用聚合键：同应用（或特殊入口）归并为一个任务栏图标 */
    function entryAppKey(entry) {
        return entry && entry.app_id ? 'app:' + entry.app_id : 'entry:' + (entry ? entry.id : '');
    }

    /** 固定在任务栏前 4 位的入口聚合键集合 */
    function pinnedTaskbarKeys() {
        return new Set(state.workspace.desktop_items.slice(0, 4).map(function (item) {
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
        var pinned = state.workspace.desktop_items.slice(0, 4);
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
        groups.set('webos', {
            id: 'webos',
            title: 'WebOS',
            icon: 'fa fa-desktop',
            entries: [applicationCenterEntry()]
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
            var item = Object.assign({}, representative, {
                start_key: pair[0],
                start_kind: representative.app_id ? 'application' : 'folder',
                start_title: application ? application.name : representative.folder_title,
                start_icon: application ? safeIcon(applicationIcon) : 'fa fa-folder',
                start_subtitle: entries.length + (application ? ' 个菜单' : ' 个子菜单'),
                start_search: [application ? application.name : representative.folder_title, entries.map(function (entry) {
                    return entry.title;
                }).join(' ')].join(' ')
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
            var pinButton = isFolderStartItem(item) ? '' : '<button class="pin-button" type="button" data-pin-id="'
                + escapeHtml(item.id) + '" title="' + (isPinned ? '从桌面移除' : '添加到桌面')
                + '"><i class="fa ' + (isPinned ? 'fa-thumb-tack' : 'fa-plus') + '"></i></button>';
            return '<div class="start-app-item ' + (isFolderStartItem(item) ? 'is-folder' : '')
                + '" tabindex="0" role="button" data-menu-id="' + escapeHtml(item.id) + '" data-start-kind="'
                + escapeHtml(item.start_kind) + '">'
                + (isFolderStartItem(item)
                    ? '<span class="start-app-item-icon"><i class="fa fa-folder"></i></span>'
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
        if (!entry || entry.id === 'webos-settings') {
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
        var occupied = state.workspace.desktop_items.map(function (item) { return Number(item.y) || 0; });
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
        var before = state.workspace.desktop_items.length;
        state.workspace.desktop_items = state.workspace.desktop_items.filter(function (item) { return item.id !== id; });
        if (state.workspace.desktop_items.length === before) {
            return;
        }
        renderDesktop();
        renderStartMenu();
        saveWorkspace(false).then(function () { toast('已从桌面移除'); });
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
        items.push(['删除图标', 'fa-thumb-tack', 'remove']);
        if (context.application && !context.application.is_system) {
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

    /** 桌面空白处右键菜单：刷新、设置背景、个性设置、显示桌面 */
    function openDesktopBlankContextMenu(clientX, clientY) {
        closeDesktopContextMenu();
        closeTaskbarContextMenu();
        closeAppRowMenus();
        var items = [
            ['刷新', 'fa-refresh', 'refresh', ''],
            ['设置背景', 'fa-picture-o', 'wallpaper', ''],
            ['个性设置', 'fa-sliders', 'personalize', ''],
            ['显示桌面', 'fa-eye', 'show-desktop', '']
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
            return '<button class="desktop-context-item ' + (item[3] ? 'is-danger' : '') + '" type="button" role="menuitem"'
                + ' data-desktop-action="' + item[2] + '" data-desktop-id="' + escapeHtml(iconId) + '">'
                + '<i class="fa ' + item[1] + '"></i>' + item[0] + '</button>';
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
        if (action === 'open') {
            openEntry(context.entry || findDesktopItem(iconId));
            return;
        }
        if (action === 'remove') {
            removeDesktopEntry(iconId);
            return;
        }
        if (action === 'refresh') {
            refreshWorkspace();
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
            if (Number(context.application.status) === 1) {
                openDisableFirstDialog(context.application, '「' + appDisplayName(context.application) + '」当前为启用状态，请先禁用后再卸载。');
                return;
            }
            openUninstallDialog(context.application);
        }
    }

    function closeTaskbarContextMenu() {
        var menu = document.getElementById('taskbar-context-menu');
        if (menu) {
            menu.remove();
        }
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
            var pinned = state.workspace.desktop_items.some(function (item) { return item.id === entryId; });
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
        if (application && !application.is_system) {
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
            addDesktopEntry(findEntry(id) || findDesktopItem(id));
            return;
        }
        if (action === 'unpin') {
            removeDesktopEntry(id);
            return;
        }
        if (action === 'uninstall') {
            var context = desktopIconContext(id);
            if (context.application) {
                if (Number(context.application.status) === 1) {
                    openDisableFirstDialog(context.application, '「' + appDisplayName(context.application) + '」当前为启用状态，请先禁用后再卸载。');
                    return;
                }
                openUninstallDialog(context.application);
            }
        }
    }

    function findDesktopItem(id) {
        return state.workspace.desktop_items.find(function (item) { return item.id === id; });
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
                if (branch.children.length) { level.push(branch); }
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

    /** 应用中心窗口侧栏为固定 Tab，其余窗口按菜单树层级渲染 */
    function windowSidebarMarkup(entry, tree, reveal) {
        if (entry.special === 'market' || entry.id === 'webos-app-center') {
            return appCenterSidebarMarkup();
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
    }

    /** 静默检查可用更新数量：服务端 lazy 模式 24h 内直接返回缓存，失败时不提示也不显示角标 */
    function loadUpdateCount() {
        api('/api/admin/apps/check-updates?lazy=1', { method: 'POST' }).then(function (payload) {
            state.updateApps = extractCollection(payload);
            state.updateCount = state.updateApps.length;
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
        var navTree = isMarket || isSettings ? [] : windowNavTree(entry);
        var sidebar = isSettings ? '' : windowSidebarMarkup(entry, navTree, true);
        var content = isMarket
            ? '<div class="app-center-shell" data-app-center></div>'
            : (isSettings ? '<div class="webos-settings-shell" data-webos-settings></div>'
                : '<div class="window-page-host" data-window-page-host></div>');
        var identity = isMarket ? { title: '应用中心', subtitle: 'WebOS' }
            : (isSettings ? { title: 'OS 设置', subtitle: 'WebOS 系统偏好' } : windowIdentity(entry));
        var brandIcon = windowBrandIconMarkup(entry);
        var sidebarCollapsed = !isSettings && !isMarket && countNavLeaves(navTree) <= 1;
        var sidebarToggle = isSettings ? '' : windowSidebarToggleMarkup(key, sidebarCollapsed);
        var windowBody = isSettings
            ? '<div class="window-body settings-window-body"><section class="window-content">' + content + '</section></div>'
            : '<div class="window-body"><aside class="window-sidebar" id="window-sidebar-' + key + '">'
                + sidebar + '</aside><section class="window-content">' + content + '</section></div>';

        return '<article class="app-window' + (sidebarCollapsed ? ' is-sidebar-collapsed' : '')
            + '" data-window-key="' + key + '">'
            + '<header class="window-titlebar" data-window-drag>'
            + '<div class="window-brand">' + brandIcon + '<strong>'
            + escapeHtml(identity.title) + '<small>' + escapeHtml(identity.subtitle) + '</small></strong></div>'
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

    /** 当前 OS 设置窗口激活的 Tab：general=基本设置，wallpaper=背景设置 */
    var osSettingsTab = 'general';

    /** 自定义壁纸库最近一次读取是否失败，用于展示重试入口 */
    var wallpaperLibraryError = false;

    function osSettingsTabBarMarkup() {
        var tabs = [
            ['general', 'fa-sliders', '基本设置'],
            ['wallpaper', 'fa-picture-o', '背景设置']
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
    var BUILTIN_WINDOW_KEYS = ['webos-app-center', 'webos-settings'];

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

    /** 按 open_type 渲染窗口内容区：_iframe 用 iframe 承载，_component 用 AJAX 注入 HTML 片段 */
    function renderWindowPage(windowState, entry) {
        var host = windowState && windowState.element.querySelector('[data-window-page-host]');
        if (!host) {
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
        windowElement.style.left = Math.max(24, (layerRect.width - size.width) / 2 + offset * 18) + 'px';
        windowElement.style.top = Math.max(20, (layerRect.height - size.height) / 2 + offset * 14) + 'px';
        windowElement.style.width = size.width + 'px';
        windowElement.style.height = size.height + 'px';
        elements.windowLayer.appendChild(windowElement);
        state.windows.set(key, { entry: entry, element: windowElement, minimized: false, maximized: false });
        renderWindowPage(state.windows.get(key), entry);
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
    }

    function activateWindowEntry(windowState, entry) {
        if (!windowState || entry.id === 'webos-app-center' || entry.id === 'webos-settings') {
            return;
        }
        var identity = windowIdentity(entry);
        renderWindowPage(windowState, entry);
        windowState.entry = entry;
        rerenderWindowNav(windowState, true);
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
        renderTaskbarWindows();
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
            uninstalled: '搜索未安装应用'
        };
        var placeholder = placeholders[state.appCenterTab] || '搜索应用';

        return '<label class="app-center-search"><i class="fa fa-search"></i>'
            + '<input type="search" data-app-search placeholder="' + placeholder + '"></label>';
    }

    function appCenterExtrasMarkup(tab) {
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
            status.innerHTML = '<i class="fa fa-history"></i>显示最近 ' + state.catalog.operation_logs.length + ' 条应用操作';
            content.innerHTML = renderOperationLogs(state.catalog.operation_logs);
            return;
        }
        if (tab === 'entries') {
            status.innerHTML = '<i class="fa fa-th"></i>桌面已固定 ' + state.workspace.desktop_items.length + ' 个入口';
            content.innerHTML = renderEntryManager();
            return;
        }
        if (tab === 'updates') {
            api('/api/admin/apps/check-updates?lazy=1', { method: 'POST' }).then(function (payload) {
                state.updateApps = extractCollection(payload);
                state.updateCount = state.updateApps.length;
                syncUpdateBadge();
                status.innerHTML = '<i class="fa fa-refresh"></i>发现 ' + state.updateApps.length + ' 个可用更新';
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

    /** 应用市场 Tab：分类 Tab 条 + 应用列表滚动分页加载（远程不可用时降级本地可安装应用） */
    function loadMarketTab(content, status) {
        resetMarketPager('', '');
        status.hidden = true;
        content.innerHTML = '<nav class="market-tabs" data-market-tabs hidden></nav>'
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

    /** 市场全量搜索：带 keyword 重新 Ajax 拉取（远程市场搜索参数为 keyword），保留当前分类 */
    function searchMarketApps(content, keyword) {
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
        var fallback = app.manifest_icon || app.icon;
        if (isImageIcon(fallback)) {
            return '<img data-app-icon-fallback hidden data-src="' + escapeHtml(fallback) + '" decoding="async" alt="">'
                + '<i data-app-icon-final hidden class="fa fa-cube"></i>';
        }
        return '<i data-app-icon-fallback hidden class="' + safeIcon(fallback || 'fa fa-cube') + '"></i>';
    }

    function applicationIconMarkup(app, className) {
        if (isImageIcon(app.icon_url)) {
            return '<span class="' + className + '"><img data-app-icon-primary src="'
                + escapeHtml(app.icon_url) + '" decoding="async" alt="">'
                + appIconFallbackMarkup(app) + '</span>';
        }
        return '<span class="' + className + '"><i class="'
            + safeIcon(app.manifest_icon || app.icon || 'fa fa-cube') + '"></i></span>';
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
            return applicationIconMarkup(application, className + ' is-app-icon');
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
                actions = '<button class="small-action primary" type="button" data-install-id="' + escapeHtml(id) + '" data-install-source="local">安装</button>';
            }
            // 远程市场应用整卡可点击进入详情页；本地降级应用无市场详情，不加该属性
            var detail = app._source === 'market' && id ? ' data-market-detail="' + escapeHtml(id) + '"' : '';
            return '<article class="app-card"' + detail + ' data-app-name="' + escapeHtml((app.name || id).toLowerCase()) + '">'
                + cardIconMarkup(app, 'app-icon') + '<div class="app-card-info"><strong>' + escapeHtml(app.name || id) + '</strong><span>'
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
            + '<strong title="' + escapeHtml(appName) + '">' + escapeHtml(appName) + '</strong><span title="'
            + escapeHtml(description) + '">' + escapeHtml(description) + '</span><small>版本 '
            + escapeHtml(app.version || '-') + (app.is_system ? ' · 系统应用' : '') + '</small></div></div>'
            + '<div class="install-row-cell">' + statusSwitchMarkup(appId, enabled, app.status_label) + '</div>'
            + '<div class="install-row-actions">' + actions + '</div></article>';
    }

    function statusSwitchMarkup(appId, enabled, label) {
        var text = enabled ? '已启用' : (label || '已禁用');

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

    function renderInstalledList(apps) {
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
        if (app.has_config) {
            items.push(['settings', 'fa-cog', '设置']);
        }
        if (!app.is_system) {
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
        button.setAttribute('aria-expanded', 'true');
    }

    function renderOperationLogs(logs) {
        if (!logs || !logs.length) {
            return emptyState('fa-file-text-o', '暂无应用操作记录');
        }
        var labels = { install: '安装', uninstall: '卸载', upgrade: '升级', enable: '启用', disable: '禁用' };
        return '<table class="records-table"><thead><tr><th>应用</th><th>操作</th><th>版本</th><th>执行人</th><th>时间</th><th>结果</th></tr></thead><tbody>'
            + logs.map(function (log) {
                var operator = log.operator ? (log.operator.name || log.operator.username) : '-';
                var version = log.version_to || log.version_from || '-';
                return '<tr><td>' + escapeHtml(log.app_id) + '</td><td>' + escapeHtml(labels[log.operation] || log.operation) + '</td><td>'
                    + escapeHtml(version) + '</td><td>' + escapeHtml(operator) + '</td><td>' + escapeHtml(log.create_time || '-') + '</td><td><span class="status-pill '
                    + (Number(log.result) === 1 ? '' : 'warning') + '">' + (Number(log.result) === 1 ? '成功' : '失败') + '</span></td></tr>';
            }).join('') + '</tbody></table>';
    }

    /** 入口管理树中已展开的节点 key；默认全部收起 */
    var entryTreeExpanded = new Set();

    /** 应用市场搜索防抖计时器 */
    var marketSearchTimer = 0;
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
            ? applicationIconMarkup(node.application, 'entry-tree-app-icon is-app-icon')
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
        var mode = document.querySelector('input[name="install_entry"]:checked').value;
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
            toast('应用安装成功');
            return refreshCatalog();
        }).then(function () {
            if (mode === 'desktop' || mode === 'both') {
                var entry = state.flatMenus.find(function (item) { return item.app_id === target.app_id; });
                if (entry) {
                    addDesktopEntry(entry);
                } else {
                    toast('应用已安装，但未声明可用的后台菜单入口');
                }
            }
            renderAppCenter('installed');
        }).catch(function (error) {
            toast(error.message, 'error');
        }).finally(function () {
            elements.confirmInstall.disabled = false;
            elements.confirmInstall.innerHTML = '<i class="fa fa-download"></i>安装';
        });
    }

    function upgradeApp(appId) {
        api('/api/admin/apps/' + encodeURIComponent(appId) + '/upgrade', { method: 'POST' }).then(function () {
            toast('应用更新完成');
            dropUpdatedApp(appId);
            return refreshCatalog();
        }).then(function () { renderAppCenter('installed'); }).catch(function (error) {
            if (String(error.message).indexOf('请先禁用应用') >= 0) {
                openDisableFirstDialog(findCatalogApp(appId), error.message);
                return;
            }
            toast(error.message, 'error');
        });
    }

    function openActionDialog(options) {
        elements.actionDialogKicker.textContent = options.kicker || '应用操作';
        elements.actionDialogTitle.textContent = options.title || '应用操作';
        elements.actionDialogBody.innerHTML = options.body || '';
        elements.actionDialogFooter.innerHTML = options.footer
            || '<button class="webos-button secondary" type="button" data-action="close-action">关闭</button>';
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
        openActionDialog({
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

    /** 应用安装/升级成功后，询问是否现在启用当前应用（点击「是」则启用，否则不做任何操作） */
    function promptEnableApp(appId, appName, actionText) {
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
                        layer.msg('应用已启用', { icon: 1 });
                    } else {
                        layer.msg(res.message || '启用失败', { icon: 2 });
                    }
                    reloadInstalledAppCenter();
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
                promptEnableApp(appId, app.name, '升级成功，当前版本 v' + app.version);
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
        var loadIndex = context.layer.load(2, { content: '正在处理，请耐心等待...', time: 0 });
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
        var name = appDisplayName(app);
        openActionDialog({
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

    /** 卸载应用后移除其桌面快捷方式（含任务栏固定图标）并持久化 */
    function pruneDesktopItemsByAppId(appId) {
        var remaining = state.workspace.desktop_items.filter(function (item) { return item.app_id !== appId; });
        if (remaining.length === state.workspace.desktop_items.length) {
            return;
        }
        state.workspace.desktop_items = remaining;
        saveWorkspace(false).catch(function () {});
    }

    function runUninstall(appId, appName) {
        api('/api/admin/apps/' + encodeURIComponent(appId) + '/uninstall', { method: 'POST' }).then(function () {
            closeActionDialog();
            toast('应用已卸载');
            pruneDesktopItemsByAppId(appId);
            return reloadInstalledAppCenter();
        }).catch(function (error) {
            if (String(error.message).indexOf('请先禁用应用') >= 0) {
                openDisableFirstDialog(findCatalogApp(appId) || { app_id: appId, name: appName }, error.message);
                return;
            }
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
            area: ['680px', '480px'],
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
        var loadIndex = layer.load(2, { content: '正在准备下载...', time: 0 });
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

                var uploadLoadIndex = layer.load(2, { content: '正在上传文件...', time: 0 });
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
        var loadIndex = layer.load(2, { content: '加载文档...', time: 0 });
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
            area: ['100%', '100%'],
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
            body: '<div class="entry-list">' + body + '</div>',
            footer: '<button class="webos-button secondary" type="button" data-action="close-action">关闭</button>'
        });
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
        var statusToggle = event.target.closest('[data-toggle-app-status]');
        if (statusToggle) {
            toggleAppStatus(statusToggle.dataset.toggleAppStatus,
                statusToggle.getAttribute('aria-pressed') !== 'true', null, statusToggle);
            return;
        }

        var passwordSubmit = event.target.closest('[data-password-submit]');
        if (passwordSubmit) { submitPasswordChange(); return; }

        var upload = event.target.closest('[data-app-upload]');
        if (upload) { openAppUploadDialog(); return; }

        var uninstallSubmit = event.target.closest('[data-uninstall-submit]');
        if (uninstallSubmit) { runUninstall(uninstallSubmit.dataset.appId, uninstallSubmit.dataset.appName); return; }

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

    function refreshCatalog() {
        return api(root.dataset.catalogUrl).then(function (catalog) {
            state.catalog = catalog || state.catalog;
            state.flatMenus = flattenMenus(state.catalog.menus);
            renderStartMenu();
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

    function renderNotifications(panel) {
        var items = [];
        (panel.todos || []).forEach(function (todo) {
            items.push({ title: todo.title, content: '待处理 ' + todo.count + ' 项', create_time: '待办', icon: 'fa-list-ul' });
        });
        (panel.notifications || []).forEach(function (notice) {
            items.push({ title: notice.title, content: notice.content, create_time: notice.create_time, icon: 'fa-bell-o' });
        });
        elements.notificationList.innerHTML = items.length ? items.map(function (item) {
            return '<article class="notification-item"><span class="notification-item-icon"><i class="fa ' + item.icon + '"></i></span>'
                + '<div class="notification-item-body"><strong>' + escapeHtml(item.title) + '</strong><span>' + escapeHtml(item.content || '')
                + '</span><time>' + escapeHtml(item.create_time || '') + '</time></div></article>';
        }).join('') : emptyState('fa-bell-o', '暂无通知');
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

    function dragDesktopIcon(button, event) {
        var id = button.dataset.desktopId;
        var item = state.workspace.desktop_items.find(function (entry) { return entry.id === id; });
        if (!item) {
            return;
        }
        var startX = event.clientX;
        var startY = event.clientY;
        var initialLeft = button.offsetLeft;
        var initialTop = button.offsetTop;
        var moved = false;
        button.setPointerCapture(event.pointerId);
        button.classList.add('is-dragging');

        function move(moveEvent) {
            if (Math.abs(moveEvent.clientX - startX) + Math.abs(moveEvent.clientY - startY) > 5) {
                moved = true;
            }
            button.style.left = Math.max(4, Math.min(elements.desktopIcons.clientWidth - 88, initialLeft + moveEvent.clientX - startX)) + 'px';
            button.style.top = Math.max(4, Math.min(elements.desktopIcons.clientHeight - 96, initialTop + moveEvent.clientY - startY)) + 'px';
        }
        function end() {
            button.classList.remove('is-dragging');
            button.removeEventListener('pointermove', move);
            button.removeEventListener('pointerup', end);
            if (moved) {
                item.x = Math.max(0, Math.round((button.offsetLeft - 4) / 102));
                item.y = Math.max(0, Math.round((button.offsetTop - 4) / 104));
                renderDesktop();
                saveWorkspace(false);
            }
            button.dataset.wasDragged = moved ? '1' : '0';
        }
        button.addEventListener('pointermove', move);
        button.addEventListener('pointerup', end);
    }

    function bindEvents() {
        elements.startButton.addEventListener('click', function () { togglePanel('start', elements.startPanel, elements.startButton); });
        elements.notificationButton.addEventListener('click', function () { togglePanel('notifications', elements.notificationPanel, elements.notificationButton); });
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

        document.addEventListener('click', function (event) {
            var action = event.target.closest('[data-action]');
            if (action) {
                var name = action.dataset.action;
                if (name === 'close-start') { closePanels(); }
                if (name === 'close-calendar') { closePanels(); }
                if (name === 'open-password-dialog') { closePanels(); openPasswordDialog(); }
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
            if (launch) { closeActionDialog(); openEntry(findEntry(launch.dataset.launchId)); }

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
                if (windowAction.dataset.windowAction === 'minimize') { minimizeWindow(key); }
                if (windowAction.dataset.windowAction === 'maximize') { maximizeWindow(key); }
                if (windowAction.dataset.windowAction === 'close') { closeWindow(key); }
            }

            var windowNavBranch = event.target.closest('[data-window-nav-branch]');
            if (windowNavBranch) { toggleWindowNavBranch(windowNavBranch); }

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
            if (specialTab) { renderAppCenter(specialTab.dataset.specialTab); }

            var marketCategory = event.target.closest('[data-market-category]');
            if (marketCategory) {
                var marketContent = marketCategory.closest('[data-app-content]');
                if (marketContent) {
                    switchMarketCategory(marketCategory.dataset.marketCategory, marketContent);
                }
            }

            // 市场卡片整卡可点击进入详情，卡内的安装/更新按钮与链接保持自身行为
            var marketDetail = event.target.closest('[data-market-detail]');
            if (marketDetail && !event.target.closest('button, a, input, select, label')) {
                openMarketDetail(marketDetail.closest('[data-app-center]'), marketDetail.dataset.marketDetail);
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
            if (specialOpen) { state.appCenterTab = specialOpen.dataset.openSpecial; openEntry(applicationCenterEntry()); renderAppCenter(state.appCenterTab); }

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

        elements.confirmInstall.addEventListener('click', confirmInstall);
        document.querySelectorAll('input[name="install_entry"]').forEach(function (input) {
            input.addEventListener('change', function () {
                document.querySelectorAll('.install-options label').forEach(function (label) {
                    label.classList.toggle('is-selected', label.contains(input));
                });
            });
        });

        elements.windowLayer.addEventListener('input', function (event) {
            if (!event.target.matches('[data-app-search]')) {
                return;
            }
            var query = event.target.value.trim();
            var center = event.target.closest('[data-app-center]');
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
            var input = event.target.closest('[data-uninstall-input]');
            var submit = elements.actionDialogFooter.querySelector('[data-uninstall-submit]');
            if (input && submit) {
                submit.disabled = input.value.trim() !== submit.dataset.appName;
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

        Promise.all([
            api(root.dataset.workspaceUrl),
            api(root.dataset.catalogUrl)
        ]).then(function (responses) {
            state.workspace = responses[0] || state.workspace;
            state.workspace.preferences = normalizePreferences(state.workspace.preferences);
            state.catalog = responses[1] || state.catalog;
            if (Array.isArray(state.workspace.preferences.usage_stats)) {
                state.workspace.preferences.usage_stats = {};
            }
            state.flatMenus = flattenMenus(state.catalog.menus);
            applyWorkspacePreferences();
            return bootstrapDesktopItems();
        }).then(function () {
            renderDesktop();
            renderStartMenu();
            loadNotifications();
            root.classList.remove('is-loading');
        }).catch(function (error) {
            root.classList.remove('is-loading');
            toast(error.message || 'WebOS 初始化失败', 'error');
        });
    }

    initialize();
}());
