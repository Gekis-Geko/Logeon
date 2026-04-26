const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminLogsCurrencyModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminLogsCurrency !== 'undefined'
                && globalWindow.AdminLogsCurrency
                && typeof globalWindow.AdminLogsCurrency.init === 'function') {
                globalWindow.AdminLogsCurrency.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminLogsCurrencyModuleFactory = createAdminLogsCurrencyModule;
export { createAdminLogsCurrencyModule as AdminLogsCurrencyModuleFactory };
export default createAdminLogsCurrencyModule;

