const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminLogsJobModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminLogsJob !== 'undefined'
                && globalWindow.AdminLogsJob
                && typeof globalWindow.AdminLogsJob.init === 'function') {
                globalWindow.AdminLogsJob.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminLogsJobModuleFactory = createAdminLogsJobModule;
export { createAdminLogsJobModule as AdminLogsJobModuleFactory };
export default createAdminLogsJobModule;

