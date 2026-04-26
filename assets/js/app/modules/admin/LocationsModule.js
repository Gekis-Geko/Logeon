const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminLocationsModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminLocations !== 'undefined'
                && globalWindow.AdminLocations
                && typeof globalWindow.AdminLocations.init === 'function') {
                globalWindow.AdminLocations.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminLocationsModuleFactory = createAdminLocationsModule;
export { createAdminLocationsModule as AdminLocationsModuleFactory };
export default createAdminLocationsModule;

