const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createLocationPageModule() {
    return {
        ctx: null,
        options: {},

        mount: function (ctx, options) {
            this.ctx = ctx || null;
            this.options = options || {};
            return this;
        },

        unmount: function () {},

        resolveDiceRootSelector: function () {
            if (typeof globalWindow.$ === 'function' && $('#location-page').length) {
                return '#location-page';
            }
            if (this.ctx && this.ctx.rootSelector) {
                return String(this.ctx.rootSelector);
            }
            return null;
        },

        resolveDiceExpression: function (payload, command) {
            if (payload && payload.expression) {
                return String(payload.expression);
            }

            var rawCommand = String(command || '').trim();
            if (rawCommand.toLowerCase().indexOf('/dado ') === 0) {
                return rawCommand.substring(6).trim() || '1d20';
            }

            return '1d20';
        },

        buildDiceOptions: function () {
            var options = {
                faceLinkSelector: '[data-dice-face-link], [data-dice-face]',
                fallbackOnInvalidExpression: false,
                allowVisualFallbackWithoutEngine: false
            };

            var rootSelector = this.resolveDiceRootSelector();
            if (rootSelector) {
                options.rootSelector = rootSelector;
            }

            return options;
        },

        syncLayout: function () {
            var viewportH = globalWindow.innerHeight || $(window).outerHeight();
            var navbarH = $("#appNavbar").outerHeight() || 0;
            var target = $("#chat_display");
            var column = $("#location-chat-column");
            var composer = $("#chat_action_character");
            var chatHeader = $("#location-chat-card .card-header");
            var isMobile = (globalWindow.matchMedia && globalWindow.matchMedia("(max-width: 1199.98px)").matches);

            if (!target.length) {
                return Promise.resolve({ chat_height: 0 });
            }

            if (isMobile || !column.length) {
                var inputChatH = composer.outerHeight() || 0;
                var fallbackH = Math.max(320, viewportH - inputChatH - navbarH);
                target.height(fallbackH);
                return Promise.resolve({ chat_height: fallbackH });
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
            var chatHeight = Math.max(260, available - composerH - headerH - 10);
            target.height(chatHeight);

            return Promise.resolve({ chat_height: chatHeight });
        },

        roll: function (payload) {
            var command = '/dado 1d20';
            if (payload && payload.command) {
                command = String(payload.command);
            }

            if (typeof globalWindow.LocationChat !== 'undefined' && globalWindow.LocationChat && typeof globalWindow.LocationChat.sendCommand === 'function') {
                globalWindow.LocationChat.sendCommand(command);
                return Promise.resolve({ channel: 'chat-command' });
            }

            if (typeof globalWindow.Dice === 'function') {
                var expression = this.resolveDiceExpression(payload, command);
                var diceResult = globalWindow.Dice(20, this.buildDiceOptions()).roll(expression);

                if (diceResult && diceResult.ok === false) {
                    var error = new Error(String(diceResult.message || 'Dice engine unavailable.'));
                    error.code = diceResult.code || 'dice_error';
                    error.payload = diceResult;
                    return Promise.reject(error);
                }

                return Promise.resolve({
                    channel: 'dice',
                    expression: expression,
                    result: diceResult
                });
            }

            return Promise.reject(new Error('Dice engine unavailable.'));
        }
    };
}

globalWindow.GameLocationPageModuleFactory = createLocationPageModule;
export { createLocationPageModule as GameLocationPageModuleFactory };
export default createLocationPageModule;

