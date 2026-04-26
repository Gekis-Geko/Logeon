const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminItemsRaritiesModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminItemsRarities !== 'undefined'
                && globalWindow.AdminItemsRarities
                && typeof globalWindow.AdminItemsRarities.init === 'function') {
                globalWindow.AdminItemsRarities.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminItemsRaritiesModuleFactory = createAdminItemsRaritiesModule;
export { createAdminItemsRaritiesModule as AdminItemsRaritiesModuleFactory };
export default createAdminItemsRaritiesModule;

