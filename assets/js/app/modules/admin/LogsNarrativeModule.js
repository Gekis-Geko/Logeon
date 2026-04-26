const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminLogsNarrativeModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminLogsNarrative !== 'undefined'
                && globalWindow.AdminLogsNarrative
                && typeof globalWindow.AdminLogsNarrative.init === 'function') {
                globalWindow.AdminLogsNarrative.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminLogsNarrativeModuleFactory = createAdminLogsNarrativeModule;
export { createAdminLogsNarrativeModule as AdminLogsNarrativeModuleFactory };
export default createAdminLogsNarrativeModule;

