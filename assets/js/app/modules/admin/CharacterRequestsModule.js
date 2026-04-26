const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminCharacterRequestsModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminCharacterRequests !== 'undefined'
                && globalWindow.AdminCharacterRequests
                && typeof globalWindow.AdminCharacterRequests.init === 'function') {
                globalWindow.AdminCharacterRequests.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminCharacterRequestsModuleFactory = createAdminCharacterRequestsModule;
export { createAdminCharacterRequestsModule as AdminCharacterRequestsModuleFactory };
export default createAdminCharacterRequestsModule;

