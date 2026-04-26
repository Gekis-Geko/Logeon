const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createMapsModule() {
    return {
        ctx: null,
        options: {},

        mount: function (ctx, options) {
            this.ctx = ctx || null;
            this.options = options || {};

            if (typeof globalWindow.AdminMaps !== 'undefined' && globalWindow.AdminMaps && typeof globalWindow.AdminMaps.init === 'function') {
                globalWindow.AdminMaps.init();
            }

            return this;
        },

        unmount: function () {}
    };
}

globalWindow.AdminMapsModuleFactory = createMapsModule;
export { createMapsModule as AdminMapsModuleFactory };
export default createMapsModule;

