const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminJobsLevelsModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminJobsLevels !== 'undefined'
                && globalWindow.AdminJobsLevels
                && typeof globalWindow.AdminJobsLevels.init === 'function') {
                globalWindow.AdminJobsLevels.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminJobsLevelsModuleFactory = createAdminJobsLevelsModule;
export { createAdminJobsLevelsModule as AdminJobsLevelsModuleFactory };
export default createAdminJobsLevelsModule;

