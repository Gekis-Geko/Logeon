var ASIDE_MENU_STATE_KEY = 'logeon.admin.aside.openMenus.v1';
var UI_NORMALIZE_TIMER_KEY = '__adminUiNormalizeTimer';
var UI_OBSERVER_KEY = '__adminUiObserver';

var GRID_ACTION_SPECS = [
    { key: 'edit', label: 'Modifica', icon: 'bi bi-pencil', text: ['modifica'] },
    { key: 'delete', label: 'Elimina', icon: 'bi bi-trash', text: ['elimina', 'cancella'] },
    { key: 'view', label: 'Dettaglio', icon: 'bi bi-eye', text: ['dettaglio', 'visualizza'] },
    { key: 'status', label: 'Stato', icon: 'bi bi-sliders', text: ['stato'] }
];

function getRoot() {
    return document.getElementById('admin-page');
}

function getPageNode() {
    var root = getRoot();
    if (!root) {
        return null;
    }
    return root.querySelector('[data-admin-page]');
}

function detectPageKey() {
    var node = getPageNode();
    if (!node) {
        return '';
    }

    var key = String(node.getAttribute('data-admin-page') || '').trim().toLowerCase();
    if (key) {
        return key;
    }

    var fallback = String(node.id || '').trim().toLowerCase();
    if (!fallback) {
        return '';
    }

    var normalized = fallback.replace(/_+/g, '-').replace(/-page$/i, '');
    node.setAttribute('data-admin-page', normalized);
    return normalized;
}

function getPageKey() {
    return detectPageKey();
}

function initTooltips(root) {
    if (typeof window.bootstrap === 'undefined' || !window.bootstrap.Tooltip) {
        return;
    }
    var nodes = (root && root.querySelectorAll)
        ? root.querySelectorAll('[data-bs-toggle="tooltip"]')
        : document.querySelectorAll('[data-bs-toggle="tooltip"]');
    for (var i = 0; i < nodes.length; i++) {
        window.bootstrap.Tooltip.getOrCreateInstance(nodes[i]);
    }
}

function requestFormSubmit(form) {
    if (!form) {
        return;
    }

    if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
        return;
    }

    var event = new Event('submit', { bubbles: true, cancelable: true });
    form.dispatchEvent(event);
}

function clearFilterFormValues(form) {
    if (!form || !form.elements) {
        return;
    }

    for (var i = 0; i < form.elements.length; i++) {
        var field = form.elements[i];
        if (!field || field.disabled) {
            continue;
        }

        var tag = String(field.tagName || '').toLowerCase();
        var type = String(field.getAttribute('type') || '').toLowerCase();

        if (tag === 'select') {
            if (typeof field.selectedIndex === 'number') {
                field.selectedIndex = 0;
            } else {
                field.value = '';
            }
            continue;
        }

        if (type === 'checkbox' || type === 'radio') {
            field.checked = false;
            continue;
        }

        if (type === 'button' || type === 'submit' || type === 'reset') {
            continue;
        }

        field.value = '';
    }
}

function bindSmartFilterForms(root) {
    if (!root || !root.querySelectorAll) {
        return;
    }

    var forms = root.querySelectorAll('form[id^="admin-"][id$="-filters"]');
    for (var i = 0; i < forms.length; i++) {
        (function (form) {
            if (!form || form.getAttribute('data-admin-filters-bound') === '1') {
                return;
            }

            form.classList.add('admin-filters-form');

            var wrappers = form.children || [];
            for (var w = 0; w < wrappers.length; w++) {
                var wrapper = wrappers[w];
                if (!wrapper || wrapper.nodeType !== 1 || !wrapper.classList) {
                    continue;
                }
                if (wrapper.classList.contains('form-text') || wrapper.getAttribute('data-admin-filter-static') === '1') {
                    continue;
                }

                var hasColClass = false;
                var toRemove = [];
                for (var cl = 0; cl < wrapper.classList.length; cl++) {
                    var cls = String(wrapper.classList[cl] || '');
                    if (cls === 'col' || cls.indexOf('col-') === 0) {
                        hasColClass = true;
                        if (cls !== 'col-auto') {
                            toRemove.push(cls);
                        }
                    }
                }

                if (hasColClass) {
                    for (var r = 0; r < toRemove.length; r++) {
                        wrapper.classList.remove(toRemove[r]);
                    }
                    wrapper.classList.add('col-auto');
                }
            }

            form.addEventListener('click', function (event) {
                var trigger = event.target && event.target.closest ? event.target.closest('a,button') : null;
                if (!trigger || !form.contains(trigger)) {
                    return;
                }

                var tag = String(trigger.tagName || '').toLowerCase();
                var type = String(trigger.getAttribute('type') || '').toLowerCase();
                var text = toLowerText(trigger.textContent);
                var isResetAnchor = tag === 'a' && text.indexOf('reset') !== -1;
                var isResetButton = tag === 'button' && type === 'reset';

                if (!isResetAnchor && !isResetButton) {
                    return;
                }

                event.preventDefault();
                clearFilterFormValues(form);
                requestFormSubmit(form);
            });

            if (String(form.getAttribute('data-admin-filter-live') || '1') === '0') {
                form.setAttribute('data-admin-filters-bound', '1');
                return;
            }

            var debounceTimer = null;
            var scheduleSubmit = function () {
                if (debounceTimer) {
                    window.clearTimeout(debounceTimer);
                }
                debounceTimer = window.setTimeout(function () {
                    requestFormSubmit(form);
                }, 260);
            };

            var controls = form.querySelectorAll('input,select,textarea,button[type="submit"],button[type="reset"]');
            for (var c = 0; c < controls.length; c++) {
                var control = controls[c];
                if (!control || control.disabled) {
                    continue;
                }

                var tagName = String(control.tagName || '').toLowerCase();
                var type = String(control.getAttribute('type') || '').toLowerCase();

                if (tagName === 'select') {
                    if (control.classList.contains('form-select') && !control.classList.contains('form-select-sm') && !control.classList.contains('form-select-lg')) {
                        control.classList.add('form-select-sm');
                    }
                    control.addEventListener('change', function () { requestFormSubmit(form); });
                    continue;
                }

                if ((tagName === 'input' || tagName === 'textarea') && control.classList.contains('form-control') && !control.classList.contains('form-control-sm') && !control.classList.contains('form-control-lg')) {
                    control.classList.add('form-control-sm');
                }

                if (tagName === 'input' && (type === 'search' || type === 'text' || type === 'email')) {
                    control.addEventListener('input', function () { scheduleSubmit(); });
                    continue;
                }

                if (tagName === 'input' && (type === 'number' || type === 'date' || type === 'datetime-local' || type === 'checkbox' || type === 'radio')) {
                    control.addEventListener('change', function () { requestFormSubmit(form); });
                }

                if (tagName === 'button' && (type === 'submit' || type === 'reset')) {
                    if (control.classList.contains('btn') && !control.classList.contains('btn-sm') && !control.classList.contains('btn-lg')) {
                        control.classList.add('btn-sm');
                    }
                }
            }

            form.setAttribute('data-admin-filters-bound', '1');
        })(forms[i]);
    }
}

function toLowerText(value) {
    return String(value || '').trim().toLowerCase();
}

function detectGridActionSpec(button) {
    if (!button) {
        return null;
    }

    var action = toLowerText(button.getAttribute('data-action'));
    var text = toLowerText(button.textContent);

    for (var i = 0; i < GRID_ACTION_SPECS.length; i++) {
        var spec = GRID_ACTION_SPECS[i];
        if (action.indexOf(spec.key) !== -1) {
            return spec;
        }
        for (var j = 0; j < spec.text.length; j++) {
            if (text === spec.text[j]) {
                return spec;
            }
        }
    }

    return null;
}

function normalizeGridActionButtons(root) {
    if (!root || !root.querySelectorAll) {
        return;
    }

    var gridRoots = root.querySelectorAll('[id^="grid-admin-"]');
    for (var g = 0; g < gridRoots.length; g++) {
        var gridRoot = gridRoots[g];
        var buttons = gridRoot.querySelectorAll('button[data-action]');
        for (var b = 0; b < buttons.length; b++) {
            var button = buttons[b];
            if (!button || button.getAttribute('data-admin-action-normalized') === '1') {
                continue;
            }

            var spec = detectGridActionSpec(button);
            if (!spec) {
                continue;
            }

            var wrapper = button.parentElement;
            if (wrapper && wrapper.children && wrapper.children.length > 1) {
                wrapper.classList.add('admin-grid-actions');
            }

            button.classList.add('admin-grid-action');
            button.setAttribute('title', spec.label);
            button.setAttribute('aria-label', spec.label);
            button.setAttribute('data-bs-toggle', 'tooltip');
            button.setAttribute('data-bs-title', spec.label);
            button.innerHTML = '<i class="' + spec.icon + '"></i>';
            button.setAttribute('data-admin-action-normalized', '1');
        }

        initTooltips(gridRoot);
    }
}

function normalizePageHeader(root) {
    var page = getPageNode();
    if (!page || !page.children || !page.children.length) {
        return;
    }

    for (var i = 0; i < page.children.length; i++) {
        var candidate = page.children[i];
        if (!candidate || !candidate.classList) {
            continue;
        }

        if (!candidate.classList.contains('d-flex') || !candidate.classList.contains('justify-content-between')) {
            continue;
        }

        candidate.classList.add('admin-page-head');

        var header = candidate.querySelector('header');
        if (header) {
            header.classList.add('admin-page-title');
            if (header.classList.contains('mt-3')) {
                header.classList.remove('mt-3');
            }
        }

        var actionsWrap = candidate.lastElementChild;
        if (actionsWrap && actionsWrap !== header && actionsWrap.classList) {
            actionsWrap.classList.add('admin-page-actions');
        }

        break;
    }
}

function runUiNormalization(root) {
    normalizePageHeader(root);
    bindSmartFilterForms(root);
    normalizeGridActionButtons(root);
    initTooltips(root || document);
}

function scheduleUiNormalization(root) {
    var host = root || getRoot() || document;
    var existing = window[UI_NORMALIZE_TIMER_KEY];
    if (existing) {
        window.clearTimeout(existing);
    }

    window[UI_NORMALIZE_TIMER_KEY] = window.setTimeout(function () {
        window[UI_NORMALIZE_TIMER_KEY] = null;
        runUiNormalization(host);
    }, 60);
}

function bindUiObserver() {
    if (window[UI_OBSERVER_KEY]) {
        return;
    }

    var root = getRoot();
    if (!root || !window.MutationObserver) {
        return;
    }

    var observer = new window.MutationObserver(function () {
        scheduleUiNormalization(root);
    });
    observer.observe(root, { childList: true, subtree: true });
    window[UI_OBSERVER_KEY] = observer;
}

function bindPageActions() {
    if (window.__adminPageActionsBound === true) {
        return;
    }
    bindAsideMenuToggles();
    runUiNormalization(document);
    bindUiObserver();
    window.__adminPageActionsBound = true;
}

function bindFormGuards() {
    if (window.__adminFormGuardsBound === true) {
        return;
    }
    window.__adminFormGuardsBound = true;
}

function findMenuForSection(section) {
    if (!section) {
        return null;
    }

    var menu = section.nextElementSibling;
    while (menu && !(menu.matches && menu.matches('.admin-menu:not(.admin-menu-tools)'))) {
        menu = menu.nextElementSibling;
    }
    return menu || null;
}

function applyMenuOpenState(menu, section, isOpen) {
    if (!menu) {
        return;
    }

    menu.setAttribute('data-menu-collapsed', isOpen ? '0' : '1');
    menu.classList.toggle('d-none', !isOpen);

    if (!section) {
        return;
    }

    section.classList.toggle('is-open', isOpen);
    section.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
}

function readAsideMenuState() {
    if (!window.localStorage) {
        return {};
    }

    try {
        var raw = String(window.localStorage.getItem(ASIDE_MENU_STATE_KEY) || '');
        if (!raw) {
            return {};
        }
        var parsed = JSON.parse(raw);
        if (!Array.isArray(parsed)) {
            return {};
        }

        var state = {};
        for (var i = 0; i < parsed.length; i++) {
            var id = String(parsed[i] || '').trim();
            if (!id) {
                continue;
            }
            state[id] = true;
        }
        return state;
    } catch (err) {
        return {};
    }
}

function persistAsideMenuState(root) {
    if (!window.localStorage || !root) {
        return;
    }

    try {
        var menus = root.querySelectorAll('.admin-aside .admin-menu:not(.admin-menu-tools)');
        var openIds = [];
        for (var i = 0; i < menus.length; i++) {
            var menu = menus[i];
            var isCollapsed = String(menu.getAttribute('data-menu-collapsed') || '') === '1';
            if (!isCollapsed && menu.id) {
                openIds.push(menu.id);
            }
        }
        window.localStorage.setItem(ASIDE_MENU_STATE_KEY, JSON.stringify(openIds));
    } catch (err) {}
}

function bindAsideMenuToggles() {
    if (window.__adminAsideMenuBound === true) {
        return;
    }

    var root = getRoot();
    if (!root) {
        return;
    }

    var menus = root.querySelectorAll('.admin-aside .admin-menu:not(.admin-menu-tools)');
    if (!menus.length) {
        return;
    }

    var persistedState = readAsideMenuState();
    for (var m = 0; m < menus.length; m++) {
        var menu = menus[m];
        if (!menu.id) {
            menu.id = 'admin-menu-group-' + String(m + 1);
        }
        var shouldOpen = persistedState[menu.id] === true;
        menu.setAttribute('data-menu-collapsed', shouldOpen ? '0' : '1');
        menu.classList.toggle('d-none', !shouldOpen);
    }

    var sections = root.querySelectorAll('.admin-aside .admin-menu-section');
    for (var s = 0; s < sections.length; s++) {
        (function (section) {
            var menu = findMenuForSection(section);
            if (!menu) {
                return;
            }

            section.setAttribute('role', 'button');
            section.setAttribute('tabindex', '0');
            section.setAttribute('aria-controls', menu.id || '');
            var isCollapsedInitially = String(menu.getAttribute('data-menu-collapsed') || '') === '1';
            applyMenuOpenState(menu, section, !isCollapsedInitially);

            var toggle = function () {
                var isCollapsed = String(menu.getAttribute('data-menu-collapsed') || '') === '1';
                applyMenuOpenState(menu, section, isCollapsed);
                persistAsideMenuState(root);
            };

            section.addEventListener('click', function (event) {
                var target = event.target;
                if (target && target.closest && target.closest('a,button,input,select,textarea,label')) {
                    return;
                }
                toggle();
            });

            section.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    toggle();
                }
            });
        })(sections[s]);
    }

    persistAsideMenuState(root);
    window.__adminAsideMenuBound = true;
}

function bind() {
    bindPageActions();
    bindFormGuards();
}

function initSharedWidgets() {}

function initPageController() {}

window.AdminPage = window.AdminPage || {};
window.AdminPage.bind = bind;
window.AdminPage.getRoot = getRoot;
window.AdminPage.getPageKey = getPageKey;
window.AdminPage.getPageNode = getPageNode;
window.AdminPage.detectPageKey = detectPageKey;
window.AdminPage.bindPageActions = bindPageActions;
window.AdminPage.bindFormGuards = bindFormGuards;
window.AdminPage.initSharedWidgets = initSharedWidgets;
window.AdminPage.initPageController = initPageController;
