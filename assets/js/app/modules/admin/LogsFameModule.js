const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminLogsFameModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminLogsFame !== 'undefined'
                && globalWindow.AdminLogsFame
                && typeof globalWindow.AdminLogsFame.init === 'function') {
                globalWindow.AdminLogsFame.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminLogsFameModuleFactory = createAdminLogsFameModule;
export { createAdminLogsFameModule as AdminLogsFameModuleFactory };
export default createAdminLogsFameModule;

