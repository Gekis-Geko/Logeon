const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createModulesModule() {
    return {
        ctx: null,
        options: {},

        mount: function (ctx, options) {
            this.ctx = ctx || null;
            this.options = options || {};

            if (typeof globalWindow.AdminModules !== 'undefined' && globalWindow.AdminModules && typeof globalWindow.AdminModules.init === 'function') {
                globalWindow.AdminModules.init();
            }

            return this;
        },

        unmount: function () {}
    };
}

globalWindow.AdminModulesModuleFactory = createModulesModule;
export { createModulesModule as AdminModulesModuleFactory };
export default createModulesModule;

