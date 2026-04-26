const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function resolveModule(name) {
    if (!globalWindow.RuntimeBootstrap || typeof globalWindow.RuntimeBootstrap.resolveAppModule !== 'function') {
        return null;
    }
    try {
        return globalWindow.RuntimeBootstrap.resolveAppModule(name);
    } catch (error) {
        return null;
    }
}


function GameLocationPage(extension) {
        let page = {
            locationPageModule: null,
            resizeBound: false,
            contextObserver: null,
            utilityStorageKey: 'logeon.location.utility.collapsed',
            quickToolsStorageKey: 'logeon.location.quicktools.collapsed',
            getLocalDiceOptions: function () {
                return {
                    rootSelector: '#location-page',
                    faceLinkSelector: '[data-dice-face-link], [data-dice-face]',
                    fallbackOnInvalidExpression: false,
                    allowVisualFallbackWithoutEngine: false
                };
            },
            notifyRollUnavailable: function (error) {
                var message = 'Lancio dado non disponibile.';
                if (error && typeof error === 'object' && error.message) {
                    message = String(error.message);
                } else if (typeof error === 'string' && error.trim() !== '') {
                    message = error.trim();
                }

                if (globalWindow.GameFeatureError && typeof globalWindow.GameFeatureError.toast === 'function') {
                    globalWindow.GameFeatureError.toast(message, 'Lancio dado non disponibile.', 'warning');
                    return;
                }

                if (globalWindow.Toast && typeof globalWindow.Toast.show === 'function') {
                    globalWindow.Toast.show({
                        body: message,
                        type: 'warning'
                    });
                    return;
                }

                if (typeof console !== 'undefined' && typeof console.warn === 'function') {
                    console.warn('[LocationPage.roll] ' + message);
                }
            },
            syncLayoutFallback: function () {
                var viewportH = globalWindow.innerHeight || $(window).outerHeight();
                var navbarH = $("#appNavbar").outerHeight() || 0;
                var target = $("#chat_display");
                var column = $("#location-chat-column");
                var composer = $("#chat_action_character");
                var chatHeader = $("#location-chat-card .card-header");
                var isMobile = (globalWindow.matchMedia && globalWindow.matchMedia("(max-width: 1199.98px)").matches);

                if (!target.length) {
                    return;
                }

                if (isMobile || !column.length) {
                    var inputChatH = composer.outerHeight() || 0;
                    target.height(Math.max(320, viewportH - inputChatH - navbarH));
                    return;
                }

                var top = 0;
                if (column.offset() && typeof column.offset().top === "number") {
                    top = column.offset().top;
                }

                var available = Math.max(420, viewportH - top - 10);
                column.css("height", available + "px");
                column.css("min-height", available + "px");

                var composerH = composer.outerHeight(true) || 0;
                var headerH = chatHeader.outerHeight(true) || 0;
                target.height(Math.max(260, available - composerH - headerH - 10));
            },
            rollFallback: function () {
                if (typeof globalWindow.LocationChat !== 'undefined' && typeof globalWindow.LocationChat.sendCommand === 'function') {
                    globalWindow.LocationChat.sendCommand('/dado 1d20');
                    return;
                }
                if (typeof globalWindow.Dice === 'function') {
                    var result = globalWindow.Dice(20, this.getLocalDiceOptions()).roll();
                    if (result && result.ok === false) {
                        this.notifyRollUnavailable(result);
                    }
                    return;
                }
                this.notifyRollUnavailable('Servizio dado non disponibile.');
            },
            getLocationPageModule: function () {
                if (this.locationPageModule) {
                    return this.locationPageModule;
                }
                if (typeof resolveModule !== 'function') {
                    return null;
                }

                this.locationPageModule = resolveModule('game.location.page');
                return this.locationPageModule;
            },
            callLocationPage: function (method, payload, onSuccess, onError) {
                var mod = this.getLocationPageModule();
                var fn = String(method || '').trim();
                if (!mod || fn === '' || typeof mod[fn] !== 'function') {
                    if (typeof onError === 'function') {
                        onError(new Error('Location page module not available: ' + fn));
                    }
                    return false;
                }

                mod[fn](payload || {}).then(function (response) {
                    if (typeof onSuccess === 'function') {
                        onSuccess(response);
                    }
                }).catch(function (error) {
                    if (typeof onError === 'function') {
                        onError(error);
                    }
                });

                return true;
            },
            syncLayout: function () {
                var self = this;
                if (!this.callLocationPage('syncLayout', {}, null, function () {
                    self.syncLayoutFallback();
                })) {
                    this.syncLayoutFallback();
                }
            },
            bind: function () {
                if (this.resizeBound === true) {
                    return this;
                }
                var self = this;
                $(globalWindow).off('resize.locationPage').on('resize.locationPage', function () {
                    self.syncUtilityPanelMode();
                    self.syncQuickToolsMode();
                    self.syncLayout();
                });
                $('#location-utility-toggle').off('click.locationPage').on('click.locationPage', function (event) {
                    event.preventDefault();
                    self.toggleUtilityPanel();
                });
                $('#location-quick-tools-toggle').off('click.locationPage').on('click.locationPage', function (event) {
                    event.preventDefault();
                    self.toggleQuickTools();
                });
                this.resizeBound = true;
                return this;
            },
            getPageRoot: function () {
                return $('#location-page');
            },
            getContextColumn: function () {
                return $('#location-context-column');
            },
            getUtilityPanel: function () {
                return $('#chat-utils');
            },
            getUtilityColumn: function () {
                return $('#location-utility-column');
            },
            getChatColumn: function () {
                return $('#location-chat-column');
            },
            getQuickTools: function () {
                return $('#location-quick-tools');
            },
            getContextPanels: function () {
                return $('#location-scenes-panel, #location-npcs-panel');
            },
            isDesktopLayout: function () {
                return !!(globalWindow.matchMedia && globalWindow.matchMedia('(min-width: 1200px)').matches);
            },
            hasVisibleContextPanels: function () {
                var panels = this.getContextPanels();
                if (!panels.length) {
                    return false;
                }
                var visible = false;
                panels.each(function () {
                    var el = $(this);
                    if (!el.hasClass('d-none')) {
                        visible = true;
                        return false;
                    }
                });
                return visible;
            },
            syncDesktopColumns: function () {
                var context = this.getContextColumn();
                var chat = this.getChatColumn();
                var utilityColumn = this.getUtilityColumn();
                if (!context.length || !chat.length || !utilityColumn.length) {
                    return;
                }

                var contextVisible = this.hasVisibleContextPanels();
                var utilityCollapsed = this.getPageRoot().hasClass('location-utility-collapsed');

                context.toggleClass('d-none', !contextVisible);
                utilityColumn.toggleClass('d-xl-none', utilityCollapsed === true);

                chat.removeClass('col-xl-8 col-xl-10 col-xl-12');
                if (contextVisible && utilityCollapsed !== true) {
                    chat.addClass('col-xl-8');
                } else if (contextVisible || utilityCollapsed !== true) {
                    chat.addClass('col-xl-10');
                } else {
                    chat.addClass('col-xl-12');
                }
            },
            syncContextColumn: function () {
                var context = this.getContextColumn();
                if (!context.length) {
                    return;
                }

                if (this.isDesktopLayout()) {
                    this.syncDesktopColumns();
                    return;
                }

                context.toggleClass('d-none', !this.hasVisibleContextPanels());
            },
            ensureContextObserver: function () {
                var self = this;
                var context = this.getContextColumn();
                if (!context.length || this.contextObserver) {
                    return;
                }

                this.contextObserver = new MutationObserver(function () {
                    self.syncContextColumn();
                    self.syncLayout();
                });

                this.contextObserver.observe(context[0], {
                    attributes: true,
                    childList: true,
                    subtree: true,
                    attributeFilter: ['class', 'style']
                });
            },
            getStoredUtilityCollapsed: function () {
                try {
                    return globalWindow.localStorage.getItem(this.utilityStorageKey) === '1';
                } catch (error) {
                    return false;
                }
            },
            getStoredQuickToolsCollapsed: function () {
                try {
                    return globalWindow.localStorage.getItem(this.quickToolsStorageKey) === '1';
                } catch (error) {
                    return false;
                }
            },
            setStoredUtilityCollapsed: function (collapsed) {
                try {
                    globalWindow.localStorage.setItem(this.utilityStorageKey, collapsed ? '1' : '0');
                } catch (error) {}
            },
            setStoredQuickToolsCollapsed: function (collapsed) {
                try {
                    globalWindow.localStorage.setItem(this.quickToolsStorageKey, collapsed ? '1' : '0');
                } catch (error) {}
            },
            getUtilityOffcanvasInstance: function () {
                var panel = this.getUtilityPanel();
                if (!panel.length || typeof globalWindow.bootstrap === 'undefined' || !globalWindow.bootstrap.Offcanvas) {
                    return null;
                }
                return globalWindow.bootstrap.Offcanvas.getOrCreateInstance(panel[0]);
            },
            syncUtilityToggleLabel: function () {
                var toggle = $('#location-utility-toggle');
                if (!toggle.length) {
                    return;
                }

                var collapsed = this.getPageRoot().hasClass('location-utility-collapsed');
                var label = toggle.find('.location-utility-toggle__label span');
                if (this.isDesktopLayout()) {
                    toggle.attr('aria-expanded', collapsed ? 'false' : 'true');
                    if (label.length) {
                        label.text(collapsed ? 'Mostra strumenti' : 'Nascondi strumenti');
                    }
                    return;
                }

                toggle.attr('aria-expanded', 'false');
                if (label.length) {
                    label.text('Strumenti');
                }
            },
            syncQuickToolsToggleLabel: function () {
                var toggle = $('#location-quick-tools-toggle');
                if (!toggle.length) {
                    return;
                }

                var collapsed = this.getPageRoot().hasClass('location-quick-tools-collapsed');
                toggle.attr('aria-expanded', collapsed ? 'false' : 'true');
                toggle.find('.location-quick-tools-toggle__label').text(collapsed ? 'Mostra azioni rapide' : 'Nascondi azioni rapide');
            },
            syncUtilityPanelMode: function () {
                var root = this.getPageRoot();
                var panel = this.getUtilityPanel();
                var utilityColumn = this.getUtilityColumn();
                if (!root.length || !panel.length || !utilityColumn.length) {
                    return;
                }

                if (this.isDesktopLayout()) {
                    root.toggleClass('location-utility-collapsed', this.getStoredUtilityCollapsed());
                    var offcanvas = this.getUtilityOffcanvasInstance();
                    if (offcanvas && panel.hasClass('show')) {
                        offcanvas.hide();
                    }
                    this.syncDesktopColumns();
                } else {
                    root.removeClass('location-utility-collapsed');
                    utilityColumn.removeClass('d-xl-none');
                }
                this.syncContextColumn();
                this.syncUtilityToggleLabel();
            },
            syncQuickToolsMode: function () {
                var root = this.getPageRoot();
                var quickTools = this.getQuickTools();
                if (!root.length || !quickTools.length) {
                    return;
                }

                var collapsed = this.getStoredQuickToolsCollapsed();
                root.toggleClass('location-quick-tools-collapsed', collapsed);
                quickTools.toggleClass('d-none', collapsed);
                this.syncQuickToolsToggleLabel();
            },
            toggleUtilityPanel: function () {
                if (!this.getUtilityPanel().length) {
                    return;
                }

                if (this.isDesktopLayout()) {
                    var root = this.getPageRoot();
                    var nextCollapsed = !root.hasClass('location-utility-collapsed');
                    root.toggleClass('location-utility-collapsed', nextCollapsed);
                    this.setStoredUtilityCollapsed(nextCollapsed);
                    this.syncDesktopColumns();
                    this.syncUtilityToggleLabel();
                    this.syncLayout();
                    return;
                }

                var offcanvas = this.getUtilityOffcanvasInstance();
                if (offcanvas) {
                    offcanvas.toggle();
                }
            },
            toggleQuickTools: function () {
                var root = this.getPageRoot();
                var quickTools = this.getQuickTools();
                if (!root.length || !quickTools.length) {
                    return;
                }

                var collapsed = !root.hasClass('location-quick-tools-collapsed');
                root.toggleClass('location-quick-tools-collapsed', collapsed);
                quickTools.toggleClass('d-none', collapsed);
                this.setStoredQuickToolsCollapsed(collapsed);
                this.syncQuickToolsToggleLabel();
                this.syncLayout();
            },
            init: function () {
                this.bind();
                this.ensureContextObserver();
                this.syncUtilityPanelMode();
                this.syncQuickToolsMode();
                this.syncLayout();
                return this;
            },

            roll: function () {
                var self = this;
                if (!this.callLocationPage('roll', { command: '/dado 1d20' }, null, function () {
                    self.rollFallback();
                })) {
                    this.rollFallback();
                }
            },
            destroy: function () {
                $(globalWindow).off('resize.locationPage');
                $('#location-utility-toggle').off('click.locationPage');
                $('#location-quick-tools-toggle').off('click.locationPage');
                if (this.contextObserver) {
                    this.contextObserver.disconnect();
                    this.contextObserver = null;
                }
                this.resizeBound = false;
                return this;
            },
            unmount: function () {
                return this.destroy();
            }
        };
        let location = Object.assign({}, page, extension);
        return location.init();
}

globalWindow.GameLocationPage = GameLocationPage;
export { GameLocationPage as GameLocationPage };
export default GameLocationPage;

