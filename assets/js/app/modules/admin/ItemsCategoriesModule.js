const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminItemsCategoriesModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminItemsCategories !== 'undefined'
                && globalWindow.AdminItemsCategories
                && typeof globalWindow.AdminItemsCategories.init === 'function') {
                globalWindow.AdminItemsCategories.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminItemsCategoriesModuleFactory = createAdminItemsCategoriesModule;
export { createAdminItemsCategoriesModule as AdminItemsCategoriesModuleFactory };
export default createAdminItemsCategoriesModule;

