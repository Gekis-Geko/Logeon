(function (window) {
    'use strict';

    function createModulesModule() {
        return {
            ctx: null,
            options: {},

            mount: function (ctx, options) {
                this.ctx = ctx || null;
                this.options = options || {};

                if (typeof window.AdminModules !== 'undefined' && window.AdminModules && typeof window.AdminModules.init === 'function') {
                    window.AdminModules.init();
                }

                return this;
            },

            unmount: function () {}
        };
    }

    window.AdminModulesModuleFactory = createModulesModule;
})(window);
