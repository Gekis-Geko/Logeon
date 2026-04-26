const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminCurrenciesModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminCurrencies !== 'undefined'
                && globalWindow.AdminCurrencies
                && typeof globalWindow.AdminCurrencies.init === 'function') {
                globalWindow.AdminCurrencies.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminCurrenciesModuleFactory = createAdminCurrenciesModule;
export { createAdminCurrenciesModule as AdminCurrenciesModuleFactory };
export default createAdminCurrenciesModule;

