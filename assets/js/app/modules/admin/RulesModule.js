const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminRulesModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminRules !== 'undefined'
                && globalWindow.AdminRules
                && typeof globalWindow.AdminRules.init === 'function') {
                globalWindow.AdminRules.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminRulesModuleFactory = createAdminRulesModule;
export { createAdminRulesModule as AdminRulesModuleFactory };
export default createAdminRulesModule;

