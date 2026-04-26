const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminLogsSysModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminLogsSys !== 'undefined'
                && globalWindow.AdminLogsSys
                && typeof globalWindow.AdminLogsSys.init === 'function') {
                globalWindow.AdminLogsSys.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminLogsSysModuleFactory = createAdminLogsSysModule;
export { createAdminLogsSysModule as AdminLogsSysModuleFactory };
export default createAdminLogsSysModule;

