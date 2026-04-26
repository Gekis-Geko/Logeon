const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminShopsModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminShops !== 'undefined'
                && globalWindow.AdminShops
                && typeof globalWindow.AdminShops.init === 'function') {
                globalWindow.AdminShops.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminShopsModuleFactory = createAdminShopsModule;
export { createAdminShopsModule as AdminShopsModuleFactory };
export default createAdminShopsModule;

