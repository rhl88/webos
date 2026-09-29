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
        updateApps: [],
        appCenterTab: 'market',
        desktopSnapshot: []
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
        clockDate: document.getElementById('clock-date'),
        clockTime: document.getElementById('clock-time'),
        lockScreen: document.getElementById('lock-screen'),
        lockDate: document.getElementById('lock-date'),
        lockTime: document.getElementById('lock-time'),
        installDialog: document.getElementById('install-dialog'),
        installSummary: document.getElementById('install-app-summary'),
        installParents: document.getElementById('install-menu-parents'),
        confirmInstall: document.getElementById('confirm-install'),
        toastRegion: document.getElementById('webos-toast-region')
    };

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
                if (!safePath(item.path)) {
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

    function bootstrapDesktopItems() {
        state.workspace.desktop_items = normalizeDesktopItems(state.workspace.desktop_items);
        if (state.workspace.desktop_items.length) {
            return Promise.resolve();
        }

        var preferred = ['文件', '内容', '系统'];
        var selected = [];
        preferred.forEach(function (keyword) {
            var match = state.flatMenus.find(function (item) {
                return item.title.indexOf(keyword) >= 0 && !selected.some(function (entry) { return entry.id === item.id; });
            });
            if (match) {
                selected.push(match);
            }
        });
        state.flatMenus.forEach(function (item) {
            if (selected.length < 3 && !selected.some(function (entry) { return entry.id === item.id; })) {
                selected.push(item);
            }
        });
        selected = selected.slice(0, 3);
        selected.push(applicationCenterEntry());
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
                + '<span class="desktop-icon-badge"><i class="' + safeIcon(item.icon) + '"></i></span>'
                + '<span class="desktop-icon-label">' + escapeHtml(item.title) + '</span>'
                + '</button>';
        }).join('');
        renderPinnedApps();
    }

    function renderPinnedApps() {
        var pinned = state.workspace.desktop_items.slice(0, 4);
        elements.taskbarPinned.innerHTML = pinned.map(function (item) {
            return '<button class="taskbar-app-button" type="button" data-launch-id="' + escapeHtml(item.id) + '" title="' + escapeHtml(item.title) + '">'
                + '<span class="taskbar-app-icon"><i class="' + safeIcon(item.icon) + '"></i></span>'
                + '</button>';
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
            var representative = entries[0];
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
                + '<span class="start-app-item-icon"><i class="' + safeIcon(item.start_icon) + '"></i></span>'
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

    function toggleDesktopEntry(id) {
        var pinned = state.workspace.desktop_items.some(function (item) { return item.id === id; });
        if (pinned) {
            removeDesktopEntry(id);
        } else {
            addDesktopEntry(findEntry(id));
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

    function siblingEntries(entry) {
        if (entry.app_id) {
            return state.flatMenus.filter(function (item) { return item.app_id === entry.app_id; });
        }
        var target = entry;
        return state.flatMenus.filter(function (entry) { return entry.folder_id === target.folder_id; });
    }

    function windowIdentity(entry) {
        var application = entry.app_id ? state.catalog.applications.find(function (item) {
            return item.app_id === entry.app_id;
        }) : null;
        return {
            title: application ? application.name : (entry.folder_title || entry.group_title || entry.title),
            subtitle: entry.title,
            icon: application && typeof application.icon === 'string' && application.icon.indexOf('/') < 0
                ? application.icon : entry.icon
        };
    }

    function windowSidebarToggleMarkup(key, collapsed) {
        var label = collapsed ? '展开左侧菜单' : '收起左侧菜单';

        return '<button class="window-control sidebar-toggle" type="button" data-window-action="toggle-sidebar"'
            + ' aria-controls="window-sidebar-' + key + '" aria-expanded="' + (!collapsed) + '"'
            + ' aria-label="' + label + '" title="' + label + '">'
            + '<i class="fa ' + (collapsed ? 'fa-indent' : 'fa-outdent') + '"></i></button>';
    }

    function windowMarkup(entry, key) {
        var isMarket = entry.special === 'market' || entry.id === 'webos-app-center';
        var isSettings = entry.special === 'settings' || entry.id === 'webos-settings';
        var siblings = isMarket ? [
            { id: 'market', title: '应用市场', icon: 'fa fa-shopping-bag' },
            { id: 'installed', title: '已安装', icon: 'fa fa-cube' },
            { id: 'uninstalled', title: '未安装', icon: 'fa fa-download' },
            { id: 'updates', title: '应用更新', icon: 'fa fa-refresh' },
            { id: 'records', title: '安装记录', icon: 'fa fa-file-text-o' },
            { id: 'entries', title: '入口管理', icon: 'fa fa-th' }
        ] : siblingEntries(entry);
        var sidebar = siblings.map(function (item) {
            var active = isMarket ? item.id === state.appCenterTab : item.id === entry.id;
            var attrs = isMarket ? 'data-special-tab="' + item.id + '"' : 'data-window-menu-id="' + escapeHtml(item.id) + '"';
            return '<button class="window-nav-button ' + (active ? 'is-active' : '') + '" type="button" ' + attrs + '>'
                + '<i class="' + safeIcon(item.icon) + '"></i><span>' + escapeHtml(item.title) + '</span></button>';
        }).join('');
        var content = isMarket
            ? '<div class="app-center-shell" data-app-center></div>'
            : (isSettings ? '<div class="webos-settings-shell" data-webos-settings></div>'
                : '<iframe src="' + escapeHtml(entry.path) + '" title="' + escapeHtml(entry.title) + '"></iframe>');
        var identity = isMarket ? { title: '应用中心', subtitle: 'WebOS' }
            : (isSettings ? { title: 'OS 设置', subtitle: 'WebOS 系统偏好' } : windowIdentity(entry));
        var sidebarCollapsed = !isSettings && siblings.length <= 1;
        var sidebarToggle = isSettings ? '' : windowSidebarToggleMarkup(key, sidebarCollapsed);
        var windowBody = isSettings
            ? '<div class="window-body settings-window-body"><section class="window-content">' + content + '</section></div>'
            : '<div class="window-body"><aside class="window-sidebar" id="window-sidebar-' + key + '">'
                + '<span class="window-sidebar-title">'
                + (isMarket || entry.app_id ? '应用菜单' : '子菜单') + '</span>' + sidebar
                + '</aside><section class="window-content">' + content + '</section></div>';

        return '<article class="app-window' + (sidebarCollapsed ? ' is-sidebar-collapsed' : '')
            + '" data-window-key="' + key + '">'
            + '<header class="window-titlebar" data-window-drag>'
            + '<div class="window-brand"><img src="/Images/logo-80x80.png" alt=""><strong>'
            + escapeHtml(identity.title) + '<small>' + escapeHtml(identity.subtitle) + '</small></strong></div>'
            + '<div class="window-controls">'
            + sidebarToggle
            + '<button class="window-control" type="button" data-window-action="minimize" aria-label="最小化"><i class="fa fa-minus"></i></button>'
            + '<button class="window-control" type="button" data-window-action="maximize" aria-label="最大化"><i class="fa fa-square-o"></i></button>'
            + '<button class="window-control close" type="button" data-window-action="close" aria-label="关闭"><i class="fa fa-times"></i></button>'
            + '</div></header>'
            + windowBody
            + '<div class="window-resize-handle" data-window-resize></div></article>';
    }

    function settingsChoice(attribute, value, icon, label, active) {
        return '<button class="settings-choice ' + (active ? 'is-active' : '') + '" type="button" data-'
            + attribute + '="' + value + '" aria-pressed="' + (active ? 'true' : 'false') + '">'
            + '<i class="fa ' + icon + '"></i><span>' + label + '</span></button>';
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
        shell.innerHTML = '<header class="settings-hero"><span><i class="fa fa-sliders"></i></span><div>'
            + '<h2>WebOS 个性化设置</h2><p>调整任务栏、桌面外观与时间显示，设置会自动保存到当前管理员工作区。</p></div></header>'
            + '<div class="settings-grid">'
            + '<section class="settings-card"><div class="settings-card-title"><i class="fa fa-window-maximize"></i><div>'
            + '<strong>任务栏位置</strong><small>选择状态栏停靠方向</small></div></div><div class="settings-choice-grid">'
            + positions.map(function (item) {
                return settingsChoice('set-taskbar-position', item[0], item[1], item[2], preferences.taskbar_position === item[0]);
            }).join('') + '</div></section>'
            + '<section class="settings-card"><div class="settings-card-title"><i class="fa fa-picture-o"></i><div>'
            + '<strong>桌面外观</strong><small>切换壁纸显示风格</small></div></div><div class="settings-choice-grid is-two">'
            + settingsChoice('set-webos-wallpaper', 'webos-default', 'fa-sun-o', '默认明亮', preferences.wallpaper === 'webos-default')
            + settingsChoice('set-webos-wallpaper', 'deep-blue', 'fa-moon-o', '深蓝沉浸', preferences.wallpaper === 'deep-blue')
            + '</div></section>'
            + '<section class="settings-card"><div class="settings-card-title"><i class="fa fa-clock-o"></i><div>'
            + '<strong>时间显示</strong><small>设置时钟格式与精度</small></div></div><div class="settings-choice-grid is-two">'
            + settingsChoice('set-clock-format', '24h', 'fa-clock-o', '24 小时', preferences.clock_format === '24h')
            + settingsChoice('set-clock-format', '12h', 'fa-clock-o', '12 小时', preferences.clock_format === '12h')
            + '</div><button class="settings-toggle" type="button" data-toggle-webos-setting="show_seconds" aria-pressed="'
            + (preferences.show_seconds ? 'true' : 'false') + '"><span><strong>显示秒数</strong><small>在任务栏时钟中显示秒</small></span><i></i></button></section>'
            + '<section class="settings-card"><div class="settings-card-title"><i class="fa fa-magic"></i><div>'
            + '<strong>交互体验</strong><small>控制窗口与面板的过渡效果</small></div></div>'
            + '<button class="settings-toggle" type="button" data-toggle-webos-setting="motion" aria-pressed="'
            + (preferences.motion ? 'true' : 'false') + '"><span><strong>界面动效</strong><small>开启柔和的窗口和菜单动画</small></span><i></i></button></section>'
            + '</div>';
    }

    function openEntry(entry) {
        if (!entry) {
            return;
        }
        if (!safePath(entry.path)) {
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
        var width = Math.min(1120, window.innerWidth - 140);
        var height = Math.min(720, window.innerHeight - 140);
        windowElement.style.left = Math.max(24, (window.innerWidth - width) / 2 + offset * 18) + 'px';
        windowElement.style.top = Math.max(20, (window.innerHeight - 74 - height) / 2 + offset * 14) + 'px';
        windowElement.style.width = width + 'px';
        windowElement.style.height = height + 'px';
        elements.windowLayer.appendChild(windowElement);
        state.windows.set(key, { entry: entry, element: windowElement, minimized: false, maximized: false });
        focusWindow(key);
        bindWindowGestures(key);
        renderTaskbarWindows();

        if (entry.id === 'webos-app-center') {
            renderAppCenter(state.appCenterTab);
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
        var frame = windowState.element.querySelector('iframe');
        if (frame && frame.getAttribute('src') !== entry.path) {
            frame.src = entry.path;
        }
        windowState.entry = entry;
        windowState.element.querySelectorAll('[data-window-menu-id]').forEach(function (button) {
            button.classList.toggle('is-active', button.dataset.windowMenuId === entry.id);
        });
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
        elements.taskbarWindows.innerHTML = Array.from(state.windows.entries()).map(function (pair) {
            var key = pair[0];
            var windowState = pair[1];
            var identity = windowState.entry.id === 'webos-app-center'
                ? { title: '应用中心', icon: windowState.entry.icon }
                : windowIdentity(windowState.entry);
            return '<button class="taskbar-app-button is-running ' + (windowState.element.classList.contains('is-focused') && !windowState.minimized ? 'is-active' : '')
                + '" type="button" data-task-window="' + escapeHtml(key) + '" title="' + escapeHtml(identity.title) + '">'
                + '<span class="taskbar-app-icon"><i class="' + safeIcon(identity.icon) + '"></i></span></button>';
        }).join('');
    }

    function bindWindowGestures(key) {
        var target = state.windows.get(key);
        var element = target.element;
        var titlebar = element.querySelector('[data-window-drag]');
        var resize = element.querySelector('[data-window-resize]');

        element.addEventListener('pointerdown', function () { focusWindow(key); });
        titlebar.addEventListener('pointerdown', function (event) {
            if (event.target.closest('button') || target.maximized) {
                return;
            }
            var startX = event.clientX;
            var startY = event.clientY;
            var startLeft = element.offsetLeft;
            var startTop = element.offsetTop;
            titlebar.setPointerCapture(event.pointerId);
            function move(moveEvent) {
                var left = Math.max(0, Math.min(window.innerWidth - 180, startLeft + moveEvent.clientX - startX));
                var top = Math.max(0, Math.min(window.innerHeight - 120, startTop + moveEvent.clientY - startY));
                element.style.left = left + 'px';
                element.style.top = top + 'px';
            }
            function end() {
                titlebar.removeEventListener('pointermove', move);
                titlebar.removeEventListener('pointerup', end);
            }
            titlebar.addEventListener('pointermove', move);
            titlebar.addEventListener('pointerup', end);
        });

        resize.addEventListener('pointerdown', function (event) {
            if (target.maximized) {
                return;
            }
            event.preventDefault();
            var startX = event.clientX;
            var startY = event.clientY;
            var startWidth = element.offsetWidth;
            var startHeight = element.offsetHeight;
            resize.setPointerCapture(event.pointerId);
            function move(moveEvent) {
                element.style.width = Math.max(620, Math.min(window.innerWidth - element.offsetLeft, startWidth + moveEvent.clientX - startX)) + 'px';
                element.style.height = Math.max(420, Math.min(window.innerHeight - 74 - element.offsetTop, startHeight + moveEvent.clientY - startY)) + 'px';
            }
            function end() {
                resize.removeEventListener('pointermove', move);
                resize.removeEventListener('pointerup', end);
            }
            resize.addEventListener('pointermove', move);
            resize.addEventListener('pointerup', end);
        });
    }

    function renderAppCenter(tab) {
        state.appCenterTab = tab || 'market';
        var appWindow = state.windows.get(windowKey(applicationCenterEntry()));
        if (!appWindow) {
            return;
        }
        appWindow.element.querySelectorAll('[data-special-tab]').forEach(function (button) {
            button.classList.toggle('is-active', button.dataset.specialTab === state.appCenterTab);
        });
        var container = appWindow.element.querySelector('[data-app-center]');
        var titles = {
            market: ['应用市场', '发现和安装更多应用，扩展系统能力'],
            installed: ['已安装应用', '管理应用状态，并将入口固定到桌面'],
            uninstalled: ['未安装应用', '查看本地已发现但尚未安装的应用'],
            updates: ['应用更新', '检查可用版本并查看更新说明'],
            records: ['安装记录', '查看应用安装、启用、升级与卸载记录'],
            entries: ['入口管理', '管理桌面快捷方式与可用系统菜单']
        };
        var title = titles[state.appCenterTab] || titles.market;
        container.innerHTML = '<div class="app-center-toolbar"><div><h1>' + title[0] + '</h1><p>' + title[1] + '</p></div>'
            + '<label class="app-center-search"><i class="fa fa-search"></i><input type="search" data-app-search placeholder="搜索应用"></label></div>'
            + '<div class="app-center-status" data-app-status><i class="fa fa-circle-o-notch fa-spin"></i>正在读取数据</div>'
            + '<div class="app-center-content" data-app-content></div>';
        loadAppCenterTab(container, state.appCenterTab);
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
            status.innerHTML = '<i class="fa fa-check-circle"></i>共 ' + state.catalog.applications.length + ' 个已安装应用';
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
                status.innerHTML = '<i class="fa fa-refresh"></i>发现 ' + state.updateApps.length + ' 个可用更新';
                content.innerHTML = renderApplicationCards(state.updateApps, 'updates');
            }).catch(function (error) {
                status.classList.add('is-error');
                status.innerHTML = '<i class="fa fa-exclamation-circle"></i>' + escapeHtml(error.message);
                content.innerHTML = emptyState('fa-refresh', '暂时无法检查更新');
            });
            return;
        }

        api('/api/admin/market/apps?per_page=20').then(function (payload) {
            var apps = extractCollection(payload);
            setMarketApps(apps, 'market');
            status.innerHTML = '<i class="fa fa-cloud"></i>已连接应用市场，共读取 ' + apps.length + ' 个应用';
            content.innerHTML = renderApplicationCards(apps, 'market');
        }).catch(function () {
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
        });
    }

    function setMarketApps(apps, source) {
        state.marketApps.clear();
        (apps || []).forEach(function (app) {
            app._source = source;
            state.marketApps.set(app.app_id, app);
        });
    }

    function isImageIcon(icon) {
        return typeof icon === 'string' && (/^\//.test(icon) || /^https?:\/\//.test(icon));
    }

    function appIconFallbackMarkup(app) {
        var fallback = app.manifest_icon || app.icon;
        if (isImageIcon(fallback)) {
            return '<img data-app-icon-fallback hidden data-src="' + escapeHtml(fallback) + '" alt="">'
                + '<i data-app-icon-final hidden class="fa fa-cube"></i>';
        }
        return '<i data-app-icon-fallback hidden class="' + safeIcon(fallback || 'fa fa-cube') + '"></i>';
    }

    function appIconMarkup(app) {
        if (isImageIcon(app.icon_url)) {
            return '<span class="app-icon"><img data-app-icon-primary src="' + escapeHtml(app.icon_url) + '" alt="">'
                + appIconFallbackMarkup(app) + '</span>';
        }
        return '<span class="app-icon"><i class="' + safeIcon(app.manifest_icon || app.icon || 'fa fa-cube') + '"></i></span>';
    }

    function renderApplicationCards(apps, mode) {
        if (!apps || !apps.length) {
            return emptyState(mode === 'updates' ? 'fa-check-circle' : 'fa-cubes', mode === 'updates' ? '当前应用均为最新版本' : '暂无应用数据');
        }
        return '<div class="app-grid">' + apps.map(function (app) {
            var id = app.app_id || '';
            var installed = mode === 'installed';
            var version = app.version || app.current_version || app.latest_version || '-';
            var description = app.description || app.changelog || '暂无应用说明';
            var actions = '';
            if (mode === 'market' || mode === 'local') {
                actions = '<button class="small-action primary" type="button" data-install-id="' + escapeHtml(id) + '" data-install-source="' + mode + '">安装</button>';
            } else if (mode === 'updates') {
                actions = '<button class="small-action primary" type="button" data-upgrade-id="' + escapeHtml(id) + '">更新</button>';
            } else if (installed) {
                var firstMenu = state.flatMenus.find(function (entry) { return entry.app_id === id; });
                actions = firstMenu ? '<button class="small-action" type="button" data-open-app-id="' + escapeHtml(id) + '">打开</button>'
                    + '<button class="small-action" type="button" data-pin-app-id="' + escapeHtml(id) + '">固定</button>' : '<span class="status-pill">' + escapeHtml(app.status_label || '已安装') + '</span>';
            }
            return '<article class="app-card" data-app-name="' + escapeHtml((app.name || id).toLowerCase()) + '">'
                + appIconMarkup(app) + '<div class="app-card-info"><strong>' + escapeHtml(app.name || id) + '</strong><span>'
                + escapeHtml(description) + '</span><small>版本 ' + escapeHtml(version) + (app.author ? ' · ' + escapeHtml(app.author) : '') + '</small></div>'
                + '<div class="app-card-actions">' + actions + '</div></article>';
        }).join('') + '</div>';
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

    function renderEntryManager() {
        var desktop = state.workspace.desktop_items.map(function (item) {
            return '<div class="entry-row" data-entry-name="' + escapeHtml(item.title.toLowerCase()) + '"><span class="start-app-item-icon"><i class="'
                + safeIcon(item.icon) + '"></i></span><div class="entry-row-info"><strong>' + escapeHtml(item.title) + '</strong><small>'
                + escapeHtml(item.path) + '</small></div><div class="entry-row-actions"><button class="small-action" type="button" data-launch-id="'
                + escapeHtml(item.id) + '">打开</button><button class="small-action" type="button" data-remove-entry-id="' + escapeHtml(item.id) + '">移除</button></div></div>';
        });
        var available = state.flatMenus.filter(function (item) {
            return !state.workspace.desktop_items.some(function (desktopItem) { return desktopItem.id === item.id; });
        }).slice(0, 30).map(function (item) {
            return '<div class="entry-row" data-entry-name="' + escapeHtml(item.title.toLowerCase()) + '"><span class="start-app-item-icon"><i class="'
                + safeIcon(item.icon) + '"></i></span><div class="entry-row-info"><strong>' + escapeHtml(item.title) + '</strong><small>'
                + escapeHtml(item.group_title) + '</small></div><div class="entry-row-actions"><button class="small-action" type="button" data-add-entry-id="'
                + escapeHtml(item.id) + '">添加到桌面</button></div></div>';
        });
        return '<div class="entry-list"><h3>桌面入口</h3>' + (desktop.join('') || emptyState('fa-desktop', '桌面暂无快捷方式'))
            + '<h3>可用菜单</h3>' + (available.join('') || emptyState('fa-check-circle', '所有菜单都已固定')) + '</div>';
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
        elements.installSummary.innerHTML = appIconMarkup(app) + '<div><strong>' + escapeHtml(app.name || app.app_id) + '</strong><span>'
            + escapeHtml(app.description || '安装后可在 WebOS 中打开此应用') + '</span><span>版本 ' + escapeHtml(app.version || app.latest_version || '-') + '</span></div>';
        renderInstallMenuStatus('fa-circle-o-notch fa-spin', '正在识别应用菜单…', false);
        elements.confirmInstall.disabled = true;
        elements.installDialog.hidden = false;
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
            elements.installDialog.hidden = true;
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
            return refreshCatalog();
        }).then(function () { renderAppCenter('installed'); }).catch(function (error) { toast(error.message, 'error'); });
    }

    function refreshCatalog() {
        return api(root.dataset.catalogUrl).then(function (catalog) {
            state.catalog = catalog || state.catalog;
            state.flatMenus = flattenMenus(state.catalog.menus);
            renderStartMenu();
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
        [['start', elements.startPanel, elements.startButton], ['notifications', elements.notificationPanel, elements.notificationButton], ['account', elements.accountPanel, elements.accountButton]].forEach(function (panel) {
            if (panel[0] !== except) {
                panel[1].hidden = true;
                panel[2].classList.remove('is-active');
                panel[2].setAttribute('aria-expanded', 'false');
            }
        });
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
        root.dataset.motion = preferences.motion === false ? 'off' : 'on';
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
    }

    function updateClock() {
        var now = new Date();
        var preferences = state.workspace.preferences || {};
        var options = { hour: '2-digit', minute: '2-digit', hour12: preferences.clock_format === '12h' };
        if (preferences.show_seconds) {
            options.second = '2-digit';
        }
        var time = now.toLocaleTimeString('zh-CN', options);
        var date = now.toLocaleDateString('zh-CN', { year: 'numeric', month: '2-digit', day: '2-digit', weekday: 'short' });
        elements.clockDate.textContent = date;
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
        elements.startSearch.addEventListener('input', renderStartMenu);

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

        document.addEventListener('click', function (event) {
            var action = event.target.closest('[data-action]');
            if (action) {
                var name = action.dataset.action;
                if (name === 'close-start') { closePanels(); }
                if (name === 'close-install') { elements.installDialog.hidden = true; }
                if (name === 'lock-desktop') { closePanels(); elements.lockScreen.hidden = false; updateClock(); }
                if (name === 'unlock-desktop') { elements.lockScreen.hidden = true; }
                if (name === 'logout') {
                    api('/api/admin/auth/logout', { method: 'POST' }).then(function () {
                        window.location.href = root.dataset.loginUrl || '/admin/login';
                    }).catch(function (error) { toast(error.message, 'error'); });
                }
            }

            var groupButton = event.target.closest('[data-group-id]');
            if (groupButton) { state.activeGroup = groupButton.dataset.groupId; renderStartMenu(); }

            var pinButton = event.target.closest('[data-pin-id]');
            if (pinButton) { event.stopPropagation(); toggleDesktopEntry(pinButton.dataset.pinId); }

            var menuButton = event.target.closest('[data-menu-id]');
            if (menuButton && !event.target.closest('[data-pin-id]')) { openEntry(findEntry(menuButton.dataset.menuId)); }

            var launch = event.target.closest('[data-launch-id]');
            if (launch) { openEntry(findEntry(launch.dataset.launchId)); }

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

            var windowMenu = event.target.closest('[data-window-menu-id]');
            if (windowMenu) {
                var currentWindow = windowMenu.closest('[data-window-key]');
                var entry = findEntry(windowMenu.dataset.windowMenuId);
                if (entry && currentWindow) {
                    var currentState = state.windows.get(currentWindow.dataset.windowKey);
                    activateWindowEntry(currentState, entry);
                    focusWindow(currentWindow.dataset.windowKey);
                }
            }

            var specialTab = event.target.closest('[data-special-tab]');
            if (specialTab) { renderAppCenter(specialTab.dataset.specialTab); }

            var specialOpen = event.target.closest('[data-open-special]');
            if (specialOpen) { state.appCenterTab = specialOpen.dataset.openSpecial; openEntry(applicationCenterEntry()); renderAppCenter(state.appCenterTab); }

            var settingsOpen = event.target.closest('[data-open-webos-settings]');
            if (settingsOpen) { openEntry(webosSettingsEntry()); }

            var install = event.target.closest('[data-install-id]');
            if (install) {
                var app = state.marketApps.get(install.dataset.installId);
                if (app) { openInstallDialog(app, install.dataset.installSource); }
            }

            var upgrade = event.target.closest('[data-upgrade-id]');
            if (upgrade) { upgradeApp(upgrade.dataset.upgradeId); }

            var openApp = event.target.closest('[data-open-app-id]');
            if (openApp) {
                var firstMenu = state.flatMenus.find(function (entry) { return entry.app_id === openApp.dataset.openAppId; });
                openEntry(firstMenu);
            }

            var pinApp = event.target.closest('[data-pin-app-id]');
            if (pinApp) {
                var appMenu = state.flatMenus.find(function (entry) { return entry.app_id === pinApp.dataset.pinAppId; });
                addDesktopEntry(appMenu);
            }

            var addEntry = event.target.closest('[data-add-entry-id]');
            if (addEntry) { addDesktopEntry(findEntry(addEntry.dataset.addEntryId)); renderAppCenter('entries'); }

            var removeEntry = event.target.closest('[data-remove-entry-id]');
            if (removeEntry) { removeDesktopEntry(removeEntry.dataset.removeEntryId); renderAppCenter('entries'); }

            var taskbarPosition = event.target.closest('[data-set-taskbar-position]');
            if (taskbarPosition) { setTaskbarPosition(taskbarPosition.dataset.setTaskbarPosition); }

            var wallpaper = event.target.closest('[data-set-webos-wallpaper]');
            if (wallpaper) { setWebosPreference('wallpaper', wallpaper.dataset.setWebosWallpaper, '桌面外观已更新'); }

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
            var query = event.target.value.trim().toLowerCase();
            var center = event.target.closest('[data-app-center]');
            center.querySelectorAll('[data-app-name], [data-entry-name]').forEach(function (item) {
                var name = item.dataset.appName || item.dataset.entryName || '';
                item.style.display = name.indexOf(query) >= 0 ? '' : 'none';
            });
        });

        elements.windowLayer.addEventListener('error', function (event) {
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
            }
        }, true);

        document.addEventListener('keydown', function (event) {
            var startItem = event.target.closest('.start-app-item[data-menu-id]');
            if (startItem && event.target === startItem && (event.key === 'Enter' || event.key === ' ')) {
                event.preventDefault();
                openEntry(findEntry(startItem.dataset.menuId));
                return;
            }
            if (event.key === 'Escape') {
                closePanels();
                elements.installDialog.hidden = true;
            }
        });
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
            state.workspace.preferences = Object.assign({
                wallpaper: 'webos-default',
                taskbar_alignment: 'left',
                taskbar_position: 'bottom',
                clock_format: '24h',
                show_seconds: false,
                motion: true,
                usage_stats: {}
            }, state.workspace.preferences || {});
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
