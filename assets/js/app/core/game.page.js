(function (window) {
    'use strict';

    function getPageContent() {
        return document.getElementById('page-content');
    }

    function getPageRoot() {
        var pageContent = getPageContent();
        if (!pageContent) {
            return null;
        }
        return pageContent.querySelector('.ui-page[id]');
    }

    function readPageData(name) {
        var root = getPageRoot();
        if (!root) {
            return '';
        }
        var key = String(name || '').trim();
        if (!key) {
            return '';
        }
        var attr = 'data-' + key.replace(/_/g, '-');
        var value = root.getAttribute(attr);
        if (value === null || typeof value === 'undefined') {
            return '';
        }
        return String(value).trim();
    }

    function toBool(value) {
        var text = String(value || '').trim().toLowerCase();
        return text === '1' || text === 'true' || text === 'yes';
    }

    function resolveModule(name) {
        if (window.RuntimeBootstrap && typeof window.RuntimeBootstrap.resolveAppModule === 'function') {
            try {
                return window.RuntimeBootstrap.resolveAppModule(name);
            } catch (error) {
                return null;
            }
        }
        return null;
    }

    function resolveFactory(name) {
        var key = String(name || '').trim();
        if (!key) {
            return null;
        }

        if (typeof window[key] === 'function') {
            return window[key];
        }

        return null;
    }

    function createController(config) {
        var cfg = config || {};
        var args = Array.isArray(cfg.args) ? cfg.args : [];

        if (cfg.module) {
            var module = resolveModule(cfg.module);
            if (module && cfg.moduleMethod && typeof module[cfg.moduleMethod] === 'function') {
                try {
                    return module[cfg.moduleMethod].apply(module, args);
                } catch (error) {}
            }
        }

        var factory = resolveFactory(cfg.factory);
        if (typeof factory === 'function') {
            try {
                return factory.apply(window, args);
            } catch (error) {}
        }

        return null;
    }

    var pageActionsHandler = null;
    var formGuardsHandler = null;
    var authzUiOff = null;
    var PAGE_CONTROLLER_GLOBALS = [
        'Forum',
        'Threads',
        'Profile',
        'JobSummary',
        'QuestHistory',
        'Settings',
        'Jobs',
        'Guilds',
        'Guild',
        'Maps',
        'Locations',
        'Shop',
        'Bank',
        'Bag',
        'Equips',
        'Location',
        'LocationChat',
        'LocationSidebar',
        'LocationWhispers',
        'LocationDrops',
        'LocationWeatherStaff',
        'Onlines'
    ];

    function resolveEventBus() {
        if (typeof window.EventBus === 'function') {
            try {
                return window.EventBus();
            } catch (error) {
                return null;
            }
        }
        return null;
    }

    function resolvePermissionGate() {
        if (typeof window.PermissionGate === 'function') {
            try {
                return window.PermissionGate();
            } catch (error) {
                return null;
            }
        }
        return null;
    }

    function attrExists(node, attrName) {
        return !!(node && node.nodeType === 1 && typeof node.hasAttribute === 'function' && node.hasAttribute(attrName));
    }

    function attrString(node, attrName) {
        if (!attrExists(node, attrName)) {
            return '';
        }
        var value = node.getAttribute(attrName);
        return (value == null) ? '' : String(value).trim();
    }

    function attrFlag(node, attrName) {
        if (!attrExists(node, attrName)) {
            return null;
        }
        var value = attrString(node, attrName);
        if (value === '') {
            return true;
        }
        return toBool(value);
    }

    function parseAttrList(node, attrName) {
        var raw = attrString(node, attrName);
        if (!raw) {
            return [];
        }
        return raw
            .split(/[,\s]+/)
            .map(function (item) { return String(item || '').trim(); })
            .filter(function (item) { return item !== ''; });
    }

    function isDisableableElement(node) {
        if (!node || node.nodeType !== 1) {
            return false;
        }
        var tag = String(node.tagName || '').toLowerCase();
        return tag === 'button' || tag === 'input' || tag === 'select' || tag === 'textarea' || tag === 'option' || tag === 'fieldset';
    }

    function applyHiddenState(node, hidden) {
        if (!node || node.nodeType !== 1) {
            return;
        }
        if (node.__authz_original_hidden == null) {
            node.__authz_original_hidden = node.hasAttribute('hidden') ? '1' : '0';
        }

        if (hidden) {
            node.setAttribute('hidden', 'hidden');
            node.setAttribute('data-authz-hidden', '1');
            return;
        }

        if (node.getAttribute('data-authz-hidden') === '1') {
            if (node.__authz_original_hidden === '1') {
                node.setAttribute('hidden', 'hidden');
            } else {
                node.removeAttribute('hidden');
            }
            node.removeAttribute('data-authz-hidden');
        }
    }

    function applyDisabledState(node, disabled) {
        if (!node || node.nodeType !== 1) {
            return;
        }

        if (node.__authz_original_disabled == null) {
            node.__authz_original_disabled = node.hasAttribute('disabled') ? '1' : '0';
        }
        if (node.__authz_original_aria_disabled == null) {
            node.__authz_original_aria_disabled = node.getAttribute('aria-disabled');
        }
        if (node.__authz_original_tabindex == null) {
            node.__authz_original_tabindex = node.getAttribute('tabindex');
        }

        if (disabled) {
            if (isDisableableElement(node)) {
                node.setAttribute('disabled', 'disabled');
            }
            node.setAttribute('aria-disabled', 'true');
            if (node.classList) {
                node.classList.add('disabled');
            }
            if (!isDisableableElement(node) && !attrExists(node, 'tabindex')) {
                node.setAttribute('tabindex', '-1');
            }
            node.setAttribute('data-authz-disabled', '1');
            return;
        }

        if (node.getAttribute('data-authz-disabled') !== '1') {
            return;
        }

        if (isDisableableElement(node)) {
            if (node.__authz_original_disabled === '1') {
                node.setAttribute('disabled', 'disabled');
            } else {
                node.removeAttribute('disabled');
            }
        }

        if (node.__authz_original_aria_disabled == null) {
            node.removeAttribute('aria-disabled');
        } else {
            node.setAttribute('aria-disabled', node.__authz_original_aria_disabled);
        }

        if (node.classList) {
            node.classList.remove('disabled');
        }

        if (node.__authz_original_tabindex == null) {
            node.removeAttribute('tabindex');
        } else {
            node.setAttribute('tabindex', node.__authz_original_tabindex);
        }

        node.removeAttribute('data-authz-disabled');
    }

    function parseAuthzMode(node) {
        var mode = attrString(node, 'data-requires-mode').toLowerCase();
        if (!mode) {
            return { hide: true, disable: false };
        }

        var tokens = mode.split(/[,\s|]+/).filter(function (token) { return token; });
        var out = { hide: false, disable: false };
        for (var i = 0; i < tokens.length; i++) {
            if (tokens[i] === 'hide') {
                out.hide = true;
            }
            if (tokens[i] === 'disable') {
                out.disable = true;
            }
            if (tokens[i] === 'both') {
                out.hide = true;
                out.disable = true;
            }
        }

        if (!out.hide && !out.disable) {
            out.hide = true;
        }
        return out;
    }

    function evaluateAuthzRequirements(node, gate) {
        if (!node || node.nodeType !== 1) {
            return true;
        }

        var matchMode = attrString(node, 'data-requires-match').toLowerCase();
        var matchAny = (matchMode === 'any' || matchMode === 'or');
        var hasRequirement = false;
        var allowed = matchAny ? false : true;
        var pageKey = getPageKey();
        var context = { pageKey: pageKey, element: node };

        function combineRule(result) {
            hasRequirement = true;
            if (matchAny) {
                allowed = allowed || !!result;
                return;
            }
            allowed = allowed && !!result;
        }

        var requiresAuth = attrFlag(node, 'data-requires-auth');
        if (requiresAuth !== null) {
            var auth = !!(gate && typeof gate.isAuthenticated === 'function' && gate.isAuthenticated());
            combineRule(requiresAuth ? auth : !auth);
        }

        var requiresGuest = attrFlag(node, 'data-requires-guest');
        if (requiresGuest !== null) {
            var isGuest = !(gate && typeof gate.isAuthenticated === 'function' && gate.isAuthenticated());
            combineRule(requiresGuest ? isGuest : !isGuest);
        }

        var requiresPageOwner = attrFlag(node, 'data-requires-page-owner');
        if (requiresPageOwner !== null) {
            var isPageOwner = toBool(readPageData('is-owner'));
            combineRule(requiresPageOwner ? isPageOwner : !isPageOwner);
        }

        var requiresAdmin = attrFlag(node, 'data-requires-admin');
        if (requiresAdmin !== null) {
            var isAdmin = !!(gate && typeof gate.isAdmin === 'function' && gate.isAdmin());
            combineRule(requiresAdmin ? isAdmin : !isAdmin);
        }

        var requiresModerator = attrFlag(node, 'data-requires-moderator');
        if (requiresModerator !== null) {
            var isModerator = !!(gate && typeof gate.isModerator === 'function' && gate.isModerator());
            combineRule(requiresModerator ? isModerator : !isModerator);
        }

        var requiresMaster = attrFlag(node, 'data-requires-master');
        if (requiresMaster !== null) {
            var isMaster = !!(gate && typeof gate.isMaster === 'function' && gate.isMaster());
            combineRule(requiresMaster ? isMaster : !isMaster);
        }

        var requiresStaff = attrFlag(node, 'data-requires-staff');
        if (requiresStaff !== null) {
            var isStaff = !!(gate && typeof gate.isStaff === 'function' && gate.isStaff());
            combineRule(requiresStaff ? isStaff : !isStaff);
        }

        var requiresForumAdmin = attrFlag(node, 'data-requires-forum-admin');
        if (requiresForumAdmin !== null) {
            var isForumAdmin = false;
            if (gate && typeof gate.canAdminForum === 'function') {
                isForumAdmin = !!gate.canAdminForum();
            } else if (gate && typeof gate.can === 'function') {
                isForumAdmin = !!gate.can('forum.admin', context);
            }
            combineRule(requiresForumAdmin ? isForumAdmin : !isForumAdmin);
        }

        var ownerId = attrString(node, 'data-requires-owner-id');
        if (ownerId !== '') {
            var isOwner = !!(gate && typeof gate.isOwner === 'function' && gate.isOwner(ownerId));
            combineRule(isOwner);
        }

        var rolesAny = parseAttrList(node, 'data-requires-role');
        if (rolesAny.length > 0) {
            var hasAnyRole = false;
            if (gate && typeof gate.hasRole === 'function') {
                for (var i = 0; i < rolesAny.length; i++) {
                    if (gate.hasRole(rolesAny[i])) {
                        hasAnyRole = true;
                        break;
                    }
                }
            }
            combineRule(hasAnyRole);
        }

        var rolesAll = parseAttrList(node, 'data-requires-all-roles');
        if (rolesAll.length > 0) {
            var hasAllRoles = !!(gate && typeof gate.hasRole === 'function');
            if (hasAllRoles) {
                for (var j = 0; j < rolesAll.length; j++) {
                    if (!gate.hasRole(rolesAll[j])) {
                        hasAllRoles = false;
                        break;
                    }
                }
            }
            combineRule(hasAllRoles);
        }

        var capabilitiesAny = parseAttrList(node, 'data-requires-capability');
        if (capabilitiesAny.length > 0) {
            var hasAnyCapability = false;
            if (gate && typeof gate.can === 'function') {
                for (var k = 0; k < capabilitiesAny.length; k++) {
                    if (gate.can(capabilitiesAny[k], context)) {
                        hasAnyCapability = true;
                        break;
                    }
                }
            }
            combineRule(hasAnyCapability);
        }

        var capabilitiesAll = parseAttrList(node, 'data-requires-all-capabilities');
        if (capabilitiesAll.length > 0) {
            var hasAllCapabilities = !!(gate && typeof gate.can === 'function');
            if (hasAllCapabilities) {
                for (var x = 0; x < capabilitiesAll.length; x++) {
                    if (!gate.can(capabilitiesAll[x], context)) {
                        hasAllCapabilities = false;
                        break;
                    }
                }
            }
            combineRule(hasAllCapabilities);
        }

        if (!hasRequirement) {
            return true;
        }

        return !!allowed;
    }

    function applyAuthzUiState(node, allowed) {
        if (!node || node.nodeType !== 1) {
            return;
        }
        var mode = parseAuthzMode(node);
        if (mode.hide) {
            applyHiddenState(node, !allowed);
        }
        if (mode.disable) {
            applyDisabledState(node, !allowed);
        } else {
            applyDisabledState(node, false);
        }
    }

    function getAuthzAwareNodes(root) {
        if (!root || typeof root.querySelectorAll !== 'function') {
            return [];
        }
        var selector = [
            '[data-requires-auth]',
            '[data-requires-guest]',
            '[data-requires-page-owner]',
            '[data-requires-admin]',
            '[data-requires-moderator]',
            '[data-requires-master]',
            '[data-requires-staff]',
            '[data-requires-forum-admin]',
            '[data-requires-owner-id]',
            '[data-requires-role]',
            '[data-requires-all-roles]',
            '[data-requires-capability]',
            '[data-requires-all-capabilities]'
        ].join(',');
        try {
            return root.querySelectorAll(selector);
        } catch (error) {
            return [];
        }
    }

    function hasHiddenStateInTree(node, stopRoot) {
        var current = node;
        while (current && current.nodeType === 1) {
            if (current.hasAttribute && (current.hasAttribute('hidden') || current.getAttribute('data-authz-hidden') === '1')) {
                return true;
            }
            if (stopRoot && current === stopRoot) {
                break;
            }
            current = current.parentElement;
        }
        return false;
    }

    function hasDisabledState(node) {
        if (!node || node.nodeType !== 1) {
            return false;
        }
        if (node.hasAttribute && node.hasAttribute('disabled')) {
            return true;
        }
        if (node.getAttribute && node.getAttribute('data-authz-disabled') === '1') {
            return true;
        }
        if (node.getAttribute && String(node.getAttribute('aria-disabled') || '').toLowerCase() === 'true') {
            return true;
        }
        return false;
    }

    function resolveTabTargetPane(trigger, scopeRoot) {
        if (!trigger || trigger.nodeType !== 1) {
            return null;
        }

        var selector = String(trigger.getAttribute('data-bs-target') || trigger.getAttribute('data-target') || '').trim();
        if (!selector) {
            var href = String(trigger.getAttribute('href') || '').trim();
            if (href && href.indexOf('#') >= 0) {
                selector = href.substring(href.indexOf('#'));
            }
        }
        if (!selector || selector.charAt(0) !== '#') {
            return null;
        }

        var root = (scopeRoot && scopeRoot.querySelector) ? scopeRoot : document;
        try {
            return root.querySelector(selector) || document.querySelector(selector);
        } catch (error) {
            return null;
        }
    }

    function isTabTriggerAvailable(trigger, scopeRoot) {
        if (!trigger || trigger.nodeType !== 1) {
            return false;
        }
        if (hasHiddenStateInTree(trigger, scopeRoot)) {
            return false;
        }
        if (hasDisabledState(trigger)) {
            return false;
        }

        var pane = resolveTabTargetPane(trigger, scopeRoot);
        if (pane && hasHiddenStateInTree(pane, scopeRoot)) {
            return false;
        }

        return true;
    }

    function manualActivateTab(trigger, scopeRoot) {
        if (!trigger || trigger.nodeType !== 1) {
            return false;
        }

        var group = (trigger.closest && (trigger.closest('[role="tablist"]') || trigger.closest('.nav'))) || null;
        if (group && group.querySelectorAll) {
            var groupTriggers = group.querySelectorAll('[data-bs-toggle="tab"], [data-bs-toggle="pill"], [role="tab"]');
            for (var i = 0; i < groupTriggers.length; i++) {
                var item = groupTriggers[i];
                if (item.classList) {
                    item.classList.remove('active', 'show');
                }
                if (item.getAttribute && item.getAttribute('role') === 'tab') {
                    item.setAttribute('aria-selected', 'false');
                }
            }
        }

        if (trigger.classList) {
            trigger.classList.add('active');
        }
        if (trigger.getAttribute && trigger.getAttribute('role') === 'tab') {
            trigger.setAttribute('aria-selected', 'true');
        }

        var pane = resolveTabTargetPane(trigger, scopeRoot);
        if (pane && pane.parentElement && pane.parentElement.querySelectorAll) {
            var siblings = pane.parentElement.querySelectorAll('.tab-pane');
            for (var j = 0; j < siblings.length; j++) {
                if (siblings[j].classList) {
                    siblings[j].classList.remove('active', 'show');
                }
            }
            if (pane.classList) {
                pane.classList.add('active', 'show');
            }
        }

        return true;
    }

    function activateTabTrigger(trigger, scopeRoot) {
        if (!trigger || trigger.nodeType !== 1) {
            return false;
        }

        if (window.bootstrap && window.bootstrap.Tab) {
            try {
                var tabInstance = (typeof window.bootstrap.Tab.getOrCreateInstance === 'function')
                    ? window.bootstrap.Tab.getOrCreateInstance(trigger)
                    : new window.bootstrap.Tab(trigger);
                if (tabInstance && typeof tabInstance.show === 'function') {
                    tabInstance.show();
                    return true;
                }
            } catch (error) {
                // fallback manual below
            }
        }

        return manualActivateTab(trigger, scopeRoot);
    }

    function ensureAuthzTabsState(scopeRoot) {
        var root = (scopeRoot && scopeRoot.querySelectorAll) ? scopeRoot : (getPageContent() || document);
        if (!root || typeof root.querySelectorAll !== 'function') {
            return;
        }

        var groups = root.querySelectorAll('[role="tablist"], .nav-tabs, .nav-pills');
        for (var g = 0; g < groups.length; g++) {
            var group = groups[g];
            if (!group || typeof group.querySelectorAll !== 'function') {
                continue;
            }

            var triggers = group.querySelectorAll('[data-bs-toggle="tab"], [data-bs-toggle="pill"], [role="tab"]');
            if (!triggers.length) {
                continue;
            }

            var active = null;
            for (var i = 0; i < triggers.length; i++) {
                var isActiveClass = !!(triggers[i].classList && triggers[i].classList.contains('active'));
                var isActiveAria = String(triggers[i].getAttribute('aria-selected') || '').toLowerCase() === 'true';
                if (isActiveClass || isActiveAria) {
                    active = triggers[i];
                    break;
                }
            }

            if (active && isTabTriggerAvailable(active, root)) {
                continue;
            }

            var fallback = null;
            for (var j = 0; j < triggers.length; j++) {
                if (isTabTriggerAvailable(triggers[j], root)) {
                    fallback = triggers[j];
                    break;
                }
            }

            if (fallback) {
                activateTabTrigger(fallback, root);
            }
        }
    }

    function normalizeAuthzFocus(scopeRoot) {
        var root = (scopeRoot && scopeRoot.nodeType === 1) ? scopeRoot : (getPageContent() || document.body || null);
        var active = document.activeElement;
        if (!active || active === document.body) {
            return;
        }

        if (root && root !== document && root.contains && !root.contains(active)) {
            return;
        }

        if (hasHiddenStateInTree(active, root) || hasDisabledState(active)) {
            if (typeof active.blur === 'function') {
                active.blur();
            }
        }
    }

    function refreshAuthzUi() {
        var root = getPageContent() || document;
        var gate = resolvePermissionGate();
        var nodes = getAuthzAwareNodes(root);
        if (!nodes || typeof nodes.length === 'undefined') {
            ensureAuthzTabsState(root);
            normalizeAuthzFocus(root);
            return;
        }

        for (var i = 0; i < nodes.length; i++) {
            var node = nodes[i];
            var allowed = evaluateAuthzRequirements(node, gate);
            applyAuthzUiState(node, allowed);
        }

        ensureAuthzTabsState(root);
        normalizeAuthzFocus(root);
    }

    function cleanupController(name) {
        var key = String(name || '').trim();
        if (!key) {
            return;
        }

        var instance = window[key];
        if (!instance || typeof instance !== 'object') {
            return;
        }

        var lifecycle = ['destroy', 'unmount', 'stop', 'dispose'];
        for (var i = 0; i < lifecycle.length; i++) {
            var method = lifecycle[i];
            if (typeof instance[method] === 'function') {
                try {
                    instance[method]();
                } catch (error) {}
                break;
            }
        }

        try {
            delete window[key];
        } catch (error) {
            window[key] = undefined;
        }
    }

    function cleanupControllers(list) {
        var names = Array.isArray(list) ? list : [];
        for (var i = 0; i < names.length; i++) {
            cleanupController(names[i]);
        }
    }

    function detectPageKey() {
        var pageContent = getPageContent();
        if (!pageContent) {
            return '';
        }

        var current = String(pageContent.getAttribute('data-app-page') || '').trim().toLowerCase();
        if (current) {
            return current;
        }

        var node = getPageRoot();
        if (!node) {
            return '';
        }

        var rawId = String(node.id || '').trim().toLowerCase();
        if (!rawId) {
            return '';
        }

        var key = rawId.replace(/-page$/i, '').replace(/_/g, '-');
        pageContent.setAttribute('data-app-page', key);
        return key;
    }

    function getPageKey() {
        var pageContent = getPageContent();
        if (!pageContent) {
            return '';
        }
        var key = String(pageContent.getAttribute('data-app-page') || '').trim().toLowerCase();
        key = key.replace(/_+/g, '-').replace(/-page$/i, '');
        if (key) {
            return key;
        }

        var root = getPageRoot();
        if (!root) {
            return '';
        }
        var rawId = String(root.id || '').trim().toLowerCase();
        if (!rawId) {
            return '';
        }
        return rawId.replace(/_+/g, '-').replace(/-page$/i, '');
    }

    function initSharedWidgets() {
        cleanupController('Weather');
        window.Weather = createController({
            module: 'game.weather',
            factory: 'GameWeatherPage',
            args: [{}]
        }) || window.Weather;
    }

    function initPageController() {
        var key = getPageKey();
        cleanupControllers(PAGE_CONTROLLER_GLOBALS);

        if (!key) {
            return;
        }

        if (key === 'forum') {
            window.Forum = createController({
                module: 'game.forum',
                factory: 'GameForumPage',
                args: [{}]
            }) || window.Forum;
            return;
        }

        if (key === 'threads') {
            window.Threads = createController({
                module: 'game.forum',
                factory: 'GameThreadsPage',
                args: [readPageData('forum-id'), {}]
            }) || window.Threads;
            return;
        }

        if (key === 'thread') {
            window.Threads = createController({
                module: 'game.forum',
                factory: 'GameThreadPage',
                args: [readPageData('thread-id'), {}]
            }) || window.Threads;
            return;
        }

        if (key === 'profile') {
            window.Profile = createController({
                module: 'game.profile',
                factory: 'GameProfilePage',
                args: [readPageData('character-id'), {}]
            }) || window.Profile;
            if (toBool(readPageData('is-owner'))) {
                window.JobSummary = createController({
                    module: 'game.jobs',
                    factory: 'GameJobSummaryPage',
                    args: [{}]
                }) || window.JobSummary;
            }
            return;
        }

        if (key === 'profile-edit') {
            window.Profile = createController({
                module: 'game.profile',
                factory: 'GameProfilePage',
                args: [readPageData('character-id'), {}]
            }) || window.Profile;
            return;
        }

        if (key === 'settings') {
            window.Settings = createController({
                module: 'game.settings',
                factory: 'GameSettingsPage',
                args: [{}]
            }) || window.Settings;
            return;
        }

        if (key === 'jobs') {
            window.Jobs = createController({
                module: 'game.jobs',
                factory: 'GameJobsPage',
                args: [{}]
            }) || window.Jobs;
            return;
        }

        if (key === 'quests-history') {
            window.QuestHistory = createController({
                module: 'game.quests',
                factory: 'GameQuestHistoryPage',
                args: [{}]
            }) || window.QuestHistory;
            return;
        }

        if (key === 'guilds') {
            window.Guilds = createController({
                module: 'game.guilds',
                factory: 'GameGuildsPage',
                args: [{}]
            }) || window.Guilds;
            return;
        }

        if (key === 'guild') {
            window.Guild = createController({
                module: 'game.guilds',
                factory: 'GameGuildPage',
                args: [{}]
            }) || window.Guild;
            return;
        }

        if (key === 'maps') {
            window.Maps = createController({
                module: 'game.maps',
                factory: 'GameMapsPage',
                args: [{}]
            }) || window.Maps;
            return;
        }

        if (key === 'locations') {
            window.Locations = createController({
                module: 'game.maps',
                factory: 'GameLocationsPage',
                args: [readPageData('map-id'), {}]
            }) || window.Locations;
            return;
        }

        if (key === 'shop') {
            window.Shop = createController({
                module: 'game.shop',
                factory: 'GameShopPage',
                args: [{}]
            }) || window.Shop;
            return;
        }

        if (key === 'bank') {
            window.Bank = createController({
                module: 'game.bank',
                factory: 'GameBankPage',
                args: [{}]
            }) || window.Bank;
            return;
        }

        if (key === 'bag') {
            window.Bag = createController({
                module: 'game.inventory',
                factory: 'GameBagPage',
                args: [readPageData('character-id'), {}]
            }) || window.Bag;
            return;
        }

        if (key === 'equips') {
            window.Equips = createController({
                module: 'game.inventory',
                factory: 'GameEquipsPage',
                args: [{}]
            }) || window.Equips;
            return;
        }

        if (key === 'location') {
            window.Location = createController({
                module: 'game.location.page',
                factory: 'GameLocationPage',
                args: [{}]
            }) || window.Location;
            window.LocationChat = createController({
                module: 'game.location.chat',
                factory: 'GameLocationChatPage',
                args: [{}]
            }) || window.LocationChat;
            window.LocationSidebar = createController({
                module: 'game.location.page',
                factory: 'GameLocationSidebarPage',
                args: [{}]
            }) || window.LocationSidebar;
            window.LocationWhispers = createController({
                module: 'game.location.whispers',
                factory: 'GameLocationWhispersPage',
                args: [{}]
            }) || window.LocationWhispers;
            window.LocationDrops = createController({
                module: 'game.location.drops',
                factory: 'GameLocationDropsPage',
                args: [{}]
            }) || window.LocationDrops;
            window.LocationWeatherStaff = createController({
                module: 'game.weather',
                factory: 'GameWeatherStaffPage',
                args: [{}]
            }) || window.LocationWeatherStaff;
            return;
        }

        if (key === 'onlines') {
            window.Onlines = createController({
                module: 'game.onlines',
                factory: 'GameOnlinesPage',
                args: [null, 'complete']
            }) || window.Onlines;
            return;
        }
    }

    function bindPageActions() {
        if (window.__gamePageActionsBound === true) {
            return;
        }

        pageActionsHandler = function (event) {
            var trigger = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
            if (!trigger) {
                return;
            }

            var action = String(trigger.getAttribute('data-action') || '').trim();
            if (!action) {
                return;
            }

            if (action === 'location-roll') {
                event.preventDefault();
                if (window.Location && typeof window.Location.roll === 'function') {
                    window.Location.roll();
                }
                return;
            }

            if (action === 'profile-open-diary') {
                event.preventDefault();
                if (window.Profile && typeof window.Profile.openDiaryModal === 'function') {
                    window.Profile.openDiaryModal();
                }
                return;
            }

            if (action === 'thread-open-create') {
                event.preventDefault();
                if (window.editThreadModal && typeof window.editThreadModal.show === 'function') {
                    window.editThreadModal.show();
                }
                return;
            }

            if (action === 'thread-answer') {
                event.preventDefault();
                if (window.Threads && typeof window.Threads.answered === 'function') {
                    window.Threads.answered();
                }
                return;
            }
        };

        document.addEventListener('click', pageActionsHandler);

        window.__gamePageActionsBound = true;
    }

    function unbindPageActions() {
        if (pageActionsHandler) {
            document.removeEventListener('click', pageActionsHandler);
            pageActionsHandler = null;
        }
        window.__gamePageActionsBound = false;
    }

    function bindFormGuards() {
        if (window.__gameFormGuardsBound === true) {
            return;
        }

        formGuardsHandler = function (event) {
            var form = event.target;
            if (!form || !form.id) {
                return;
            }

            if (form.id === 'profile-form' || form.id === 'settings-form') {
                event.preventDefault();
            }
        };

        document.addEventListener('submit', formGuardsHandler);

        window.__gameFormGuardsBound = true;
    }

    function unbindFormGuards() {
        if (formGuardsHandler) {
            document.removeEventListener('submit', formGuardsHandler);
            formGuardsHandler = null;
        }
        window.__gameFormGuardsBound = false;
    }

    function initAuthFromPage() {
        var pageContent = document.getElementById('page-content');
        if (!pageContent) {
            return;
        }

        var userId = parseInt(pageContent.getAttribute('data-session-user-id') || '0', 10);
        if (userId <= 0) {
            return;
        }

        if (typeof window.Storage !== 'function') {
            return;
        }

        try {
            var store = window.Storage();
            if (!store || typeof store.set !== 'function') {
                return;
            }

            store.set('userId', userId);

            var characterId = parseInt(pageContent.getAttribute('data-session-character-id') || '0', 10);
            if (characterId > 0) {
                store.set('characterId', characterId);
            }

            store.set('userIsAdministrator', parseInt(pageContent.getAttribute('data-session-is-admin') || '0', 10));
            store.set('userIsModerator', parseInt(pageContent.getAttribute('data-session-is-moderator') || '0', 10));
            store.set('userIsMaster', parseInt(pageContent.getAttribute('data-session-is-master') || '0', 10));
            store.set('userIsSuperuser', parseInt(pageContent.getAttribute('data-session-is-superuser') || '0', 10));
        } catch (error) {}
    }

    function bindAuthzUi() {
        if (window.__gameAuthzUiBound === true) {
            return;
        }

        initAuthFromPage();

        var bus = resolveEventBus();
        if (bus && typeof bus.on === 'function') {
            authzUiOff = bus.on('authz:changed', function () {
                refreshAuthzUi();
            });
        }

        refreshAuthzUi();
        window.__gameAuthzUiBound = true;
    }

    function unbindAuthzUi() {
        if (typeof authzUiOff === 'function') {
            authzUiOff();
        }
        authzUiOff = null;
        window.__gameAuthzUiBound = false;
    }

    function bind() {
        bindPageActions();
        bindFormGuards();
        bindAuthzUi();
    }

    function unbind() {
        unbindPageActions();
        unbindFormGuards();
        unbindAuthzUi();
        cleanupControllers(PAGE_CONTROLLER_GLOBALS);
        cleanupController('Weather');
        cleanupController('AvailabilityObserver');
    }

    window.GamePage = window.GamePage || {};
    window.GamePage.bind = bind;
    window.GamePage.unbind = unbind;
    window.GamePage.getPageKey = getPageKey;
    window.GamePage.readPageData = readPageData;
    window.GamePage.detectPageKey = detectPageKey;
    window.GamePage.bindPageActions = bindPageActions;
    window.GamePage.unbindPageActions = unbindPageActions;
    window.GamePage.bindFormGuards = bindFormGuards;
    window.GamePage.unbindFormGuards = unbindFormGuards;
    window.GamePage.initAuthFromPage = initAuthFromPage;
    window.GamePage.bindAuthzUi = bindAuthzUi;
    window.GamePage.unbindAuthzUi = unbindAuthzUi;
    window.GamePage.refreshAuthzUi = refreshAuthzUi;
    window.GamePage.ensureAuthzTabsState = ensureAuthzTabsState;
    window.GamePage.normalizeAuthzFocus = normalizeAuthzFocus;
    window.GamePage.initSharedWidgets = initSharedWidgets;
    window.GamePage.initPageController = initPageController;
})(window);
