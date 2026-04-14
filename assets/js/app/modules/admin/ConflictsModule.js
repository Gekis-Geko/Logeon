(function (window) {
    'use strict';

    function createConflictsModule() {
        return {
            ctx: null,
            options: {},

            mount: function (ctx, options) {
                this.ctx = ctx || null;
                this.options = options || {};

                if (
                    typeof window.AdminConflicts !== 'undefined'
                    && window.AdminConflicts
                    && typeof window.AdminConflicts.init === 'function'
                ) {
                    window.AdminConflicts.init();
                }

                return this;
            },

            unmount: function () {}
        };
    }

    window.AdminConflictsModuleFactory = createConflictsModule;
})(window);
