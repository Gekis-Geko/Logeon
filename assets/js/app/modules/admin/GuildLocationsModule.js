const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminGuildLocationsModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminGuildLocations !== 'undefined'
                && globalWindow.AdminGuildLocations
                && typeof globalWindow.AdminGuildLocations.init === 'function') {
                globalWindow.AdminGuildLocations.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminGuildLocationsModuleFactory = createAdminGuildLocationsModule;
export { createAdminGuildLocationsModule as AdminGuildLocationsModuleFactory };
export default createAdminGuildLocationsModule;

