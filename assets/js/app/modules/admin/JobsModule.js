const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminJobsModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminJobs !== 'undefined'
                && globalWindow.AdminJobs
                && typeof globalWindow.AdminJobs.init === 'function') {
                globalWindow.AdminJobs.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminJobsModuleFactory = createAdminJobsModule;
export { createAdminJobsModule as AdminJobsModuleFactory };
export default createAdminJobsModule;

