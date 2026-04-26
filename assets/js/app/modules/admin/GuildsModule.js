const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminGuildsModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminGuilds !== 'undefined'
                && globalWindow.AdminGuilds
                && typeof globalWindow.AdminGuilds.init === 'function') {
                globalWindow.AdminGuilds.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminGuildsModuleFactory = createAdminGuildsModule;
export { createAdminGuildsModule as AdminGuildsModuleFactory };
export default createAdminGuildsModule;

