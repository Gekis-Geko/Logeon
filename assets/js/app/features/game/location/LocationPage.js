(function (window) {
    'use strict';

    function resolveModule(name) {
        if (!window.RuntimeBootstrap || typeof window.RuntimeBootstrap.resolveAppModule !== 'function') {
            return null;
        }
        try {
            return window.RuntimeBootstrap.resolveAppModule(name);
        } catch (error) {
            return null;
        }
    }


    function GameLocationPage(extension) {
            let page = {
                locationPageModule: null,
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

                    if (window.GameFeatureError && typeof window.GameFeatureError.toast === 'function') {
                        window.GameFeatureError.toast(message, 'Lancio dado non disponibile.', 'warning');
                        return;
                    }

                    if (window.Toast && typeof window.Toast.show === 'function') {
                        window.Toast.show({
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
                    var viewportH = window.innerHeight || $(window).outerHeight();
                    var navbarH = $("#appNavbar").outerHeight() || 0;
                    var target = $("#chat_display");
                    var column = $("#location-chat-column");
                    var composer = $("#chat_action_character");
                    var chatHeader = $("#location-chat-card .card-header");
                    var isMobile = (window.matchMedia && window.matchMedia("(max-width: 1199.98px)").matches);

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
                    if (typeof window.LocationChat !== 'undefined' && typeof window.LocationChat.sendCommand === 'function') {
                        window.LocationChat.sendCommand('/dado 1d20');
                        return;
                    }
                    if (typeof window.Dice === 'function') {
                        var result = window.Dice(20, this.getLocalDiceOptions()).roll();
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
                init: function () {
                    var self = this;
                    if (!this.callLocationPage('syncLayout', {}, null, function () {
                        self.syncLayoutFallback();
                    })) {
                        this.syncLayoutFallback();
                    }
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
            };
            let location = Object.assign({}, page, extension);
            return location.init();
    }

    window.GameLocationPage = GameLocationPage;
})(window);
