(function (window) {
    'use strict';

    function createCharacterAttributesModule() {
        return {
            ctx: null,
            options: {},

            mount: function (ctx, options) {
                this.ctx = ctx || null;
                this.options = options || {};

                if (
                    typeof window.AdminCharacterAttributes !== 'undefined'
                    && window.AdminCharacterAttributes
                    && typeof window.AdminCharacterAttributes.init === 'function'
                ) {
                    window.AdminCharacterAttributes.init();
                }

                return this;
            },

            unmount: function () {}
        };
    }

    window.AdminCharacterAttributesModuleFactory = createCharacterAttributesModule;
})(window);
