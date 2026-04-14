(function (window) {
    'use strict';

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

    function resolveTooltipService() {
        if (typeof window.Tooltip === 'function') {
            return window.Tooltip();
        }
        return null;
    }

    function hideOpenTooltips() {
        var tooltipService = resolveTooltipService();
        if (tooltipService && typeof tooltipService.hideAll === 'function') {
            tooltipService.hideAll();
            return;
        }

        if (typeof window.bootstrap === 'undefined' || !window.bootstrap.Tooltip) {
            return;
        }

        document.querySelectorAll('[data-bs-toggle="tooltip"][aria-describedby]').forEach(function (el) {
            var instance = window.bootstrap.Tooltip.getInstance(el);
            if (instance) {
                instance.hide();
            }
        });

        document.querySelectorAll('.tooltip.show').forEach(function (tip) {
            tip.remove();
        });
    }

    function initTooltips(context) {
        var tooltipService = resolveTooltipService();
        if (tooltipService && typeof tooltipService.init === 'function') {
            tooltipService.init(context || document);
            return;
        }

        if (typeof window.bootstrap === 'undefined' || !window.bootstrap.Tooltip) {
            return;
        }

        var root = context || document;
        var nodes = (root && root.querySelectorAll) ? root.querySelectorAll('[data-bs-toggle="tooltip"]') : document.querySelectorAll('[data-bs-toggle="tooltip"]');

        nodes.forEach(function (el) {
            var title = el.getAttribute('data-bs-title');
            if (title === null || title === '') {
                title = el.getAttribute('title');
            }

            var current = window.bootstrap.Tooltip.getInstance(el);
            if (current) {
                current.dispose();
            }

            if (title === null || title === '') {
                return;
            }

            new window.bootstrap.Tooltip(el, {
                title: title,
                trigger: 'hover',
                container: 'body',
                boundary: 'window'
            });
        });
    }

    function patchSummernoteAutoLink() {
        if (window.__summernoteAutoLinkPatched === true) {
            return;
        }

        if (typeof window.$ === 'undefined' || !window.$.summernote || !window.$.summernote.options || !window.$.summernote.options.modules) {
            return;
        }

        var modules = window.$.summernote.options.modules;
        var autoLink = modules.autoLink;

        if (typeof autoLink === 'function' && autoLink.prototype) {
            if (typeof autoLink.prototype.handleKeydown === 'function' && autoLink.prototype.handleKeydown.__safeWrapped !== true) {
                var originalKeydown = autoLink.prototype.handleKeydown;
                var safeKeydown = function (e) {
                    try {
                        return originalKeydown.call(this, e);
                    } catch (error) {
                        this.lastWordRange = null;
                    }
                };
                safeKeydown.__safeWrapped = true;
                autoLink.prototype.handleKeydown = safeKeydown;
            }

            if (typeof autoLink.prototype.handleKeyup === 'function' && autoLink.prototype.handleKeyup.__safeWrapped !== true) {
                var originalKeyup = autoLink.prototype.handleKeyup;
                var safeKeyup = function (e) {
                    try {
                        return originalKeyup.call(this, e);
                    } catch (error) {
                        this.lastWordRange = null;
                    }
                };
                safeKeyup.__safeWrapped = true;
                autoLink.prototype.handleKeyup = safeKeyup;
            }

            window.__summernoteAutoLinkPatched = true;
            return;
        }

        modules.autoLink = function () {
            this.shouldInitialize = function () {
                return false;
            };
        };

        window.__summernoteAutoLinkPatched = true;
    }

    function initSummernote(context) {
        if (typeof window.$ === 'undefined' || !window.$.fn || typeof window.$.fn.summernote !== 'function') {
            return;
        }

        patchSummernoteAutoLink();

        var root = context || document;
        var nodes = [];
        if (root && root.querySelectorAll) {
            nodes = root.querySelectorAll('.summernote');
        } else {
            nodes = document.querySelectorAll('.summernote');
        }

        window.$(nodes).each(function () {
            var node = window.$(this);
            if (node.next('.note-editor').length || node.data('summernote')) {
                node.next('.note-editor').addClass('ui-richtext');
                return;
            }

            node.summernote({
                lang: 'it-IT',
                height: 250,
                icons: {
                    bold: 'bi bi-type-bold',
                    italic: 'bi bi-type-italic',
                    underline: 'bi bi-type-underline',
                    unorderedlist: 'bi bi-list-ul',
                    orderedlist: 'bi bi-list-ol',
                    paragraph: 'bi bi-text-paragraph',
                    table: 'bi bi-table',
                    link: 'bi bi-link-45deg',
                    picture: 'bi bi-image',
                    caret: 'bi bi-caret-down-fill'
                },
                toolbar: [
                    ['font', ['bold', 'underline', 'italic']],
                    ['para', ['ul', 'ol', 'paragraph']],
                    ['table', ['table']],
                    ['insert', ['link', 'picture']]
                ],
                callbacks: {
                    onInit: function () {
                        window.$(this).next('.note-editor').addClass('ui-richtext');
                    }
                }
            });
        });
    }

    function initInboxUnread() {
        if (!document.getElementById('inbox-modal')) {
            return;
        }

        if (window.InboxMessages && typeof window.InboxMessages.loadUnread === 'function') {
            window.InboxMessages.loadUnread();
            return;
        }

        try {
            var module = resolveModule('game.messages');
            if (module && typeof module.widget === 'function') {
                var inboxFromModule = module.widget({ key: 'modal', root: '#inbox-modal' });
                if (inboxFromModule && typeof inboxFromModule.loadUnread === 'function') {
                    window.InboxMessages = inboxFromModule;
                    inboxFromModule.loadUnread();
                    return;
                }
            }
        } catch (error) {}

        if (typeof window.GameMessagesPage === 'function') {
            try {
                var neutralInbox = window.GameMessagesPage({ key: 'modal', root: '#inbox-modal' });
                if (neutralInbox && typeof neutralInbox.loadUnread === 'function') {
                    window.InboxMessages = neutralInbox;
                    neutralInbox.loadUnread();
                    return;
                }
            } catch (error) {}
        }

    }

    function initLocationInvites() {
        if (!document.getElementById('location-invite-modal')) {
            return;
        }

        if (typeof window.LocationInvites !== 'undefined') {
            return;
        }

        if (typeof window.GameLocationInvitesPage === 'function') {
            try {
                window.LocationInvites = window.GameLocationInvitesPage({});
                return;
            } catch (error) {}
        }

    }

    function initAvailabilityObserver() {
        if (typeof window.Storage !== 'function') {
            return;
        }
        if (typeof window.AvailabilityObserver !== 'undefined') {
            return;
        }

        try {
            if (!window.Storage().get('characterId')) {
                return;
            }
            if (typeof window.GameAvailabilityObserverPage === 'function') {
                window.AvailabilityObserver = window.GameAvailabilityObserverPage({});
                return;
            }
        } catch (error) {}
    }

    function bindTooltipGuards() {
        if (window.__gameTooltipGuardsBound === true) {
            return;
        }

        var tooltipService = resolveTooltipService();
        if (tooltipService && typeof tooltipService.bindGlobalGuards === 'function') {
            tooltipService.bindGlobalGuards();
            window.__gameTooltipGuardsBound = true;
            return;
        }

        if (typeof window.$ === 'undefined') {
            return;
        }

        window.$(document).on('click.game_tooltip', function () {
            hideOpenTooltips();
        });
        window.$(document).on('shown.bs.modal.game_tooltip hidden.bs.modal.game_tooltip shown.bs.offcanvas.game_tooltip hidden.bs.offcanvas.game_tooltip', function () {
            hideOpenTooltips();
        });
        window.__gameTooltipGuardsBound = true;
    }

    function unbindTooltipGuards() {
        if (window.__gameTooltipGuardsBound !== true) {
            return;
        }

        var tooltipService = resolveTooltipService();
        if (tooltipService && typeof tooltipService.unbindGlobalGuards === 'function') {
            tooltipService.unbindGlobalGuards();
            window.__gameTooltipGuardsBound = false;
            return;
        }

        if (typeof window.$ !== 'undefined') {
            window.$(document).off('click.game_tooltip');
            window.$(document).off('shown.bs.modal.game_tooltip hidden.bs.modal.game_tooltip shown.bs.offcanvas.game_tooltip hidden.bs.offcanvas.game_tooltip');
        }

        window.__gameTooltipGuardsBound = false;
    }

    function initBaseServices() {
        if (typeof window.EventBus === 'function' && typeof window.AppEvents === 'undefined') {
            window.AppEvents = window.EventBus();
        }

        if (typeof window.PollManager === 'function') {
            window.PollManager();
        }
    }

    function sync() {
        initBaseServices();
        initTooltips(document);
        bindTooltipGuards();
        initSummernote(document);
        initInboxUnread();
        initLocationInvites();
        initAvailabilityObserver();
    }

    function bind() {
        if (window.__gameGlobalsBound === true || window.__gameGlobalsBinding === true) {
            return;
        }

        window.__gameGlobalsBinding = true;
        try {
            sync();
            window.__gameGlobalsBound = true;
        } finally {
            window.__gameGlobalsBinding = false;
        }
    }

    function unbind() {
        unbindTooltipGuards();
        window.__gameGlobalsBound = false;
    }

    function destroy(context) {
        unbindTooltipGuards();

        var tooltipService = resolveTooltipService();
        if (tooltipService && typeof tooltipService.destroy === 'function') {
            tooltipService.destroy(context || document);
            return;
        }

        if (tooltipService && typeof tooltipService.dispose === 'function') {
            tooltipService.dispose(context || document);
        }
        hideOpenTooltips();
    }

    window.GameGlobals = window.GameGlobals || {};
    window.GameGlobals.bind = bind;
    window.GameGlobals.unbind = unbind;
    window.GameGlobals.destroy = destroy;
    window.GameGlobals.sync = sync;
    window.GameGlobals.resolveTooltipService = resolveTooltipService;
    window.GameGlobals.initTooltips = initTooltips;
    window.GameGlobals.initSummernote = initSummernote;
    window.GameGlobals.patchSummernoteAutoLink = patchSummernoteAutoLink;
    window.GameGlobals.bindTooltipGuards = bindTooltipGuards;
    window.GameGlobals.unbindTooltipGuards = unbindTooltipGuards;
    window.resolveTooltipService = resolveTooltipService;
    window.hideOpenTooltips = hideOpenTooltips;
    window.initTooltips = initTooltips;
})(window);
