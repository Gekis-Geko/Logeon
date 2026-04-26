const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminJobsTasksModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminJobsTasks !== 'undefined'
                && globalWindow.AdminJobsTasks
                && typeof globalWindow.AdminJobsTasks.init === 'function') {
                globalWindow.AdminJobsTasks.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminJobsTasksModuleFactory = createAdminJobsTasksModule;
export { createAdminJobsTasksModule as AdminJobsTasksModuleFactory };
export default createAdminJobsTasksModule;

