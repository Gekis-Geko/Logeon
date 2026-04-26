const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminItemsModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminItems !== 'undefined'
                && globalWindow.AdminItems
                && typeof globalWindow.AdminItems.init === 'function') {
                globalWindow.AdminItems.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminItemsModuleFactory = createAdminItemsModule;
export { createAdminItemsModule as AdminItemsModuleFactory };
export default createAdminItemsModule;

