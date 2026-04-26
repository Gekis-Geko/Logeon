const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminBlacklistModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminBlacklist !== 'undefined'
                && globalWindow.AdminBlacklist
                && typeof globalWindow.AdminBlacklist.init === 'function') {
                globalWindow.AdminBlacklist.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminBlacklistModuleFactory = createAdminBlacklistModule;
export { createAdminBlacklistModule as AdminBlacklistModuleFactory };
export default createAdminBlacklistModule;

