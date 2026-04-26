const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createConflictsModule() {
    return {
        ctx: null,
        options: {},

        mount: function (ctx, options) {
            this.ctx = ctx || null;
            this.options = options || {};

            if (
                typeof globalWindow.AdminConflicts !== 'undefined'
                && globalWindow.AdminConflicts
                && typeof globalWindow.AdminConflicts.init === 'function'
            ) {
                globalWindow.AdminConflicts.init();
            }

            return this;
        },

        unmount: function () {}
    };
}

globalWindow.AdminConflictsModuleFactory = createConflictsModule;
export { createConflictsModule as AdminConflictsModuleFactory };
export default createConflictsModule;

