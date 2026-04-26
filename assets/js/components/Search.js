function Search(extension) {
    var base = {
        initialized: false,
        menuInstances: [],
        inputBindings: [],
        datagridFormBindings: {},
        autoConfig: {
            menu: {
                root: '#admin-page',
                input: '#menu-search',
                clearButton: '[data-action="menu-search-clear"]',
                sectionsSelector: '.admin-aside .admin-menu-section',
                menusSelector: '.admin-aside .admin-menu:not(.admin-menu-tools)',
                itemSelector: 'a.nav-link',
                separatorSelector: 'hr',
                debounceMs: 150
            },
            dataGrid: {
                clearButtonSelector: '[data-search-clear], [data-action="search-clear"], [data-action="grid-search-clear"]',
                inputSelector: null,
                submitOnClear: true,
                debounceMs: 200,
                instant: false,
                formsSelector: 'form[data-search-form], form'
            }
        },

        init: function (options) {
            if (!this.initialized) {
                this.initialized = true;
                this.initMenu();
                this.initDataGridForms(document);
            }

            if (options && typeof options === 'object') {
                this.applyInitOptions(options);
            }

            return this;
        },

        applyInitOptions: function (options) {
            if (options.menu && options.menu.enabled !== false) {
                this.initMenu(options.menu);
            }

            if (options.dataGrid && options.dataGrid.enabled !== false) {
                this.initDataGridForms(document, options.dataGrid);
            }

            if (Array.isArray(options.inputGroups)) {
                for (var i = 0; i < options.inputGroups.length; i++) {
                    this.bindInputGroup(options.inputGroups[i]);
                }
            }

            return this;
        },

        resolveElement: function (value, root) {
            if (!value) {
                return null;
            }

            if (value.nodeType === 1 || value === window || value === document) {
                return value;
            }

            if (value.jquery && value.length) {
                return value.get(0);
            }

            if (typeof value === 'string') {
                var scope = (root && root.querySelector) ? root : document;
                var scopedFound = scope.querySelector(value);
                if (scopedFound) {
                    return scopedFound;
                }
                return document.querySelector(value);
            }

            return null;
        },

        queryAll: function (root, selector) {
            if (!selector) {
                return [];
            }
            var scope = (root && root.querySelectorAll) ? root : document;
            return Array.prototype.slice.call(scope.querySelectorAll(selector));
        },

        createDebounced: function (callback, delayMs) {
            var timer = null;
            var wrapped = function (value) {
                if (timer) {
                    window.clearTimeout(timer);
                }
                timer = window.setTimeout(function () {
                    callback(value);
                }, delayMs);
            };
            wrapped.cancel = function () {
                if (timer) {
                    window.clearTimeout(timer);
                    timer = null;
                }
            };
            return wrapped;
        },

        bindInputGroup: function (options) {
            options = options || {};

            var root = this.resolveElement(options.root || document);
            var input = this.resolveElement(options.input, root);
            if (!input) {
                return null;
            }

            var clearButton = this.resolveElement(options.clearButton, root);
            var debounceMs = parseInt(options.debounceMs, 10);
            if (isNaN(debounceMs) || debounceMs < 0) {
                debounceMs = 150;
            }

            var onChange = (typeof options.onChange === 'function') ? options.onChange : function () {};
            var onClear = (typeof options.onClear === 'function') ? options.onClear : function () {};

            var debouncedOnChange = this.createDebounced(onChange, debounceMs);

            var handleInput = function () {
                debouncedOnChange(input.value || '');
            };
            var handleChange = function () {
                debouncedOnChange(input.value || '');
            };

            input.addEventListener('input', handleInput);
            input.addEventListener('change', handleChange);

            var handleClear = null;
            if (clearButton) {
                handleClear = function (event) {
                    event.preventDefault();
                    if (typeof debouncedOnChange.cancel === 'function') {
                        debouncedOnChange.cancel();
                    }
                    input.value = '';
                    onClear('');
                    onChange('');
                    input.focus();
                };
                clearButton.addEventListener('click', handleClear);
            }

            var binding = {
                input: input,
                clearButton: clearButton,
                destroy: function () {
                    input.removeEventListener('input', handleInput);
                    input.removeEventListener('change', handleChange);
                    if (clearButton && handleClear) {
                        clearButton.removeEventListener('click', handleClear);
                    }
                }
            };

            this.inputBindings.push(binding);
            return binding;
        },

        resolveSearchInputInForm: function (form, explicitSelector) {
            if (!form) {
                return null;
            }

            if (explicitSelector) {
                return form.querySelector(explicitSelector);
            }

            return form.querySelector(
                '[data-search-type="instant"], [data-search-type="standard"], input[type="search"], input[name="search"], input[name="q"], input[type="text"]'
            );
        },

        bindDataGridForm: function (formRef, options) {
            options = options || {};
            var form = this.resolveElement(formRef);
            if (!form || !form.querySelectorAll) {
                return null;
            }

            var bindId = form.getAttribute('data-search-bind-id');
            if (!bindId) {
                bindId = form.id || ('search_form_' + Math.random().toString(16).slice(2));
                form.setAttribute('data-search-bind-id', bindId);
            }

            if (this.datagridFormBindings[bindId]) {
                return this.datagridFormBindings[bindId];
            }

            var clearSelector = options.clearButtonSelector || this.autoConfig.dataGrid.clearButtonSelector;
            var clearButtons = this.queryAll(form, clearSelector);
            if (!clearButtons.length) {
                return null;
            }

            var input = this.resolveSearchInputInForm(form, options.inputSelector || this.autoConfig.dataGrid.inputSelector);
            var submitOnClear = (options.submitOnClear !== false);
            var listeners = [];

            function triggerSubmit() {
                if (!submitOnClear) {
                    return;
                }
                form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
            }

            for (var i = 0; i < clearButtons.length; i++) {
                (function (button) {
                    var handler = function (event) {
                        event.preventDefault();

                        var selector = button.getAttribute('data-search-input');
                        var targetInput = selector ? form.querySelector(selector) : input;

                        if (button.getAttribute('data-search-clear-all') === '1') {
                            form.reset();
                        } else if (targetInput) {
                            targetInput.value = '';
                            targetInput.dispatchEvent(new Event('input', { bubbles: true }));
                            targetInput.dispatchEvent(new Event('change', { bubbles: true }));
                        }

                        triggerSubmit();
                    };
                    button.addEventListener('click', handler);
                    listeners.push({ element: button, handler: handler });
                })(clearButtons[i]);
            }

            if ((options.instant === true || this.autoConfig.dataGrid.instant === true) && input) {
                var delay = parseInt(options.debounceMs, 10);
                if (isNaN(delay) || delay < 0) {
                    delay = this.autoConfig.dataGrid.debounceMs;
                }
                var debouncedSubmit = this.createDebounced(function () {
                    triggerSubmit();
                }, delay);

                var instantHandler = function () {
                    debouncedSubmit();
                };
                input.addEventListener('input', instantHandler);
                listeners.push({ element: input, handler: instantHandler, type: 'input' });
            }

            var binding = {
                id: bindId,
                form: form,
                destroy: function () {
                    for (var l = 0; l < listeners.length; l++) {
                        var entry = listeners[l];
                        entry.element.removeEventListener(entry.type || 'click', entry.handler);
                    }
                }
            };

            this.datagridFormBindings[bindId] = binding;
            return binding;
        },

        initDataGridForms: function (context, options) {
            options = options || {};
            var root = this.resolveElement(context || document);
            if (!root || !root.querySelectorAll) {
                return this;
            }

            var selector = options.formsSelector || this.autoConfig.dataGrid.formsSelector;
            var forms = this.queryAll(root, selector);
            for (var i = 0; i < forms.length; i++) {
                var form = forms[i];
                var clearSelector = options.clearButtonSelector || this.autoConfig.dataGrid.clearButtonSelector;
                var hasClear = form.querySelector(clearSelector);
                var hasSearchField = form.querySelector('[data-search-type], input[type="search"], input[type="text"]');

                if (!hasClear || !hasSearchField) {
                    continue;
                }

                this.bindDataGridForm(form, options);
            }

            return this;
        },

        initMenu: function (options) {
            var cfg = Object.assign({}, this.autoConfig.menu, options || {});
            var root = this.resolveElement(cfg.root || document);
            if (!root) {
                return null;
            }

            var input = this.resolveElement(cfg.input, root);
            var menus = this.queryAll(root, cfg.menusSelector);
            if (!input || !menus.length) {
                return null;
            }

            for (var m = 0; m < this.menuInstances.length; m++) {
                if (this.menuInstances[m].input === input) {
                    return this.menuInstances[m];
                }
            }

            var menuInstance = {
                root: root,
                input: input,
                clearButton: this.resolveElement(cfg.clearButton, root),
                sections: this.queryAll(root, cfg.sectionsSelector),
                menus: menus,
                itemSelector: cfg.itemSelector,
                separatorSelector: cfg.separatorSelector
            };

            var self = this;
            menuInstance.binding = this.bindInputGroup({
                input: menuInstance.input,
                clearButton: menuInstance.clearButton,
                debounceMs: cfg.debounceMs,
                onChange: function (value) {
                    self.applyMenuFilter(menuInstance, value);
                },
                onClear: function () {
                    self.applyMenuFilter(menuInstance, '');
                }
            });

            this.applyMenuFilter(menuInstance, menuInstance.input.value || '');
            this.menuInstances.push(menuInstance);

            return menuInstance;
        },

        applyMenuFilter: function (instance, value) {
            if (!instance) {
                return this;
            }

            var term = String(value || '').toLowerCase().trim();
            var hasTerm = term.length > 0;

            for (var i = 0; i < instance.menus.length; i++) {
                var menu = instance.menus[i];
                var links = menu.querySelectorAll(instance.itemSelector);
                var visibleCount = 0;
                var isCollapsed = String(menu.getAttribute('data-menu-collapsed') || '') === '1';

                for (var l = 0; l < links.length; l++) {
                    var link = links[l];
                    var text = String(link.textContent || '').toLowerCase();
                    var visible = !hasTerm || text.indexOf(term) !== -1;
                    link.classList.toggle('d-none', !visible);
                    if (visible) {
                        visibleCount++;
                    }
                }

                this.updateMenuSeparators(menu, instance.separatorSelector, instance.itemSelector);

                var hideMenu = (visibleCount === 0) || (!hasTerm && isCollapsed);
                menu.classList.toggle('d-none', hideMenu);

                if (instance.sections[i]) {
                    instance.sections[i].classList.toggle('d-none', visibleCount === 0);
                }
            }

            return this;
        },

        updateMenuSeparators: function (menu, separatorSelector, itemSelector) {
            var separators = menu.querySelectorAll(separatorSelector || 'hr');
            for (var i = 0; i < separators.length; i++) {
                var separator = separators[i];
                var hasVisiblePrev = this.findVisibleSiblingLink(separator, 'previousElementSibling', itemSelector);
                var hasVisibleNext = this.findVisibleSiblingLink(separator, 'nextElementSibling', itemSelector);
                separator.classList.toggle('d-none', !(hasVisiblePrev && hasVisibleNext));
            }

            return this;
        },

        findVisibleSiblingLink: function (node, direction, itemSelector) {
            var current = node[direction];
            while (current) {
                var isMatch = current.matches && current.matches(itemSelector || 'a.nav-link');
                var isVisible = !current.classList.contains('d-none');
                if (isMatch && isVisible) {
                    return current;
                }
                current = current[direction];
            }
            return null;
        }
    };

    if (typeof window !== 'undefined') {
        if (!window.__search_instance) {
            window.__search_instance = Object.assign({}, base, extension || {});
        } else if (extension && typeof extension === 'object') {
            Object.assign(window.__search_instance, extension);
        }
        return window.__search_instance;
    }

    return Object.assign({}, base, extension || {});
}

if (typeof window !== 'undefined') {
    window.Search = Search;

    var _searchAutoInit = Search();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            _searchAutoInit.init();
        });
    } else {
        _searchAutoInit.init();
    }
}
