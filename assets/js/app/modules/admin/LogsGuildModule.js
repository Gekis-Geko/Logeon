const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminLogsGuildModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminLogsGuild !== 'undefined'
                && globalWindow.AdminLogsGuild
                && typeof globalWindow.AdminLogsGuild.init === 'function') {
                globalWindow.AdminLogsGuild.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminLogsGuildModuleFactory = createAdminLogsGuildModule;
export { createAdminLogsGuildModule as AdminLogsGuildModuleFactory };
export default createAdminLogsGuildModule;

