const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminGuildReqsModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminGuildReqs !== 'undefined'
                && globalWindow.AdminGuildReqs
                && typeof globalWindow.AdminGuildReqs.init === 'function') {
                globalWindow.AdminGuildReqs.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminGuildReqsModuleFactory = createAdminGuildReqsModule;
export { createAdminGuildReqsModule as AdminGuildReqsModuleFactory };
export default createAdminGuildReqsModule;

