const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminLogsConflictsModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminLogsConflicts !== 'undefined'
                && globalWindow.AdminLogsConflicts
                && typeof globalWindow.AdminLogsConflicts.init === 'function') {
                globalWindow.AdminLogsConflicts.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminLogsConflictsModuleFactory = createAdminLogsConflictsModule;
export { createAdminLogsConflictsModule as AdminLogsConflictsModuleFactory };
export default createAdminLogsConflictsModule;

