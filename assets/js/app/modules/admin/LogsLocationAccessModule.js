const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminLogsLocationAccessModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminLogsLocationAccess !== 'undefined'
                && globalWindow.AdminLogsLocationAccess
                && typeof globalWindow.AdminLogsLocationAccess.init === 'function') {
                globalWindow.AdminLogsLocationAccess.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminLogsLocationAccessModuleFactory = createAdminLogsLocationAccessModule;
export { createAdminLogsLocationAccessModule as AdminLogsLocationAccessModuleFactory };
export default createAdminLogsLocationAccessModule;

