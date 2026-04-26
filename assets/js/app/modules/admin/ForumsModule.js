const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminForumsModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminForums !== 'undefined'
                && globalWindow.AdminForums
                && typeof globalWindow.AdminForums.init === 'function') {
                globalWindow.AdminForums.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminForumsModuleFactory = createAdminForumsModule;
export { createAdminForumsModule as AdminForumsModuleFactory };
export default createAdminForumsModule;

