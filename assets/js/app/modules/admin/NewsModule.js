const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminNewsModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminNews !== 'undefined'
                && globalWindow.AdminNews
                && typeof globalWindow.AdminNews.init === 'function') {
                globalWindow.AdminNews.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminNewsModuleFactory = createAdminNewsModule;
export { createAdminNewsModule as AdminNewsModuleFactory };
export default createAdminNewsModule;

