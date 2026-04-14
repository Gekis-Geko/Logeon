(function (window) {
    'use strict';

    function createMapsModule() {
        return {
            ctx: null,
            options: {},

            mount: function (ctx, options) {
                this.ctx = ctx || null;
                this.options = options || {};

                if (typeof window.AdminMaps !== 'undefined' && window.AdminMaps && typeof window.AdminMaps.init === 'function') {
                    window.AdminMaps.init();
                }

                return this;
            },

            unmount: function () {}
        };
    }

    window.AdminMapsModuleFactory = createMapsModule;
})(window);
